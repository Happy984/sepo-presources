<?php

declare(strict_types=1);

namespace SepoResources;

use WP;
use WP_Post;

/**
 * The download route: /{base}/download/{id}/ (or ?spr_download={id} when
 * pretty permalinks are off).
 *
 * Every download goes through here, gated or not, so counting is done on
 * the server and protected files are never exposed by URL.
 *   - ?stream=1 serves the file inline for the PDF preview and is never
 *     counted, and is allowed even when downloads are switched off.
 *   - Range requests are honoured (PDF viewers rely on them); only the
 *     first, non-range request counts.
 *   - External files are counted, then redirected to.
 *   - The same visitor is counted once per window (Settings: count_window).

 */
final class Download
{
    public const QV = 'spr_download';

    public static function init(): void
    {
        add_action('init', [self::class, 'addRewrite']);
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = self::QV;
            return $vars;
        });
        add_action('parse_request', [self::class, 'handle'], 1);
    }

    public static function addRewrite(): void
    {
        $base = preg_quote(Settings::base(), '#');
        add_rewrite_rule('^' . $base . '/download/([0-9]+)/?$', 'index.php?' . self::QV . '=$matches[1]', 'top');
    }

    public static function url(int $postId, bool $stream = false): string
    {
        if (get_option('permalink_structure')) {
            $url = home_url('/' . Settings::base() . '/download/' . $postId . '/');
        } else {
            $url = add_query_arg(self::QV, $postId, home_url('/'));
        }
        return $stream ? add_query_arg('stream', '1', $url) : $url;
    }

    public static function handle(WP $wp): void
    {
        if (!isset($wp->query_vars[self::QV])) {
            return;
        }
        $post = get_post(absint($wp->query_vars[self::QV]));
        if (!$post instanceof WP_Post || $post->post_type !== PostType::POST_TYPE) {
            self::fail(404, __('Document not found.', 'sepo-resources'));
        }
        if ($post->post_status !== 'publish' && !current_user_can('read_post', $post->ID)) {
            self::fail(404, __('Document not found.', 'sepo-resources'));
        }
        if (post_password_required($post)) {
            self::fail(403, __('This document is password protected.', 'sepo-resources'));
        }

        $doc = Document::fromPost($post);
        $inline = isset($_GET['stream']) && $_GET['stream'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification

        if (!$doc['download'] && !$inline) {
            self::fail(403, __('Downloads are turned off for this document.', 'sepo-resources'));
        }
        if ($doc['gated'] && !is_user_logged_in()) {
            wp_safe_redirect(wp_login_url((string)get_permalink($post)));
            exit;
        }
        if (!$doc['has_file']) {
            self::fail(404, __('This document has no file attached.', 'sepo-resources'));
        }

        $countIt = static function () use ($post, $inline): void {
            if ($inline || !self::countOnce($post->ID)) {
                return;
            }
            self::count($post->ID);
        };

        // External file: count, then hand off.
        $path = Document::localPath($post->ID);
        if ($path === null) {
            $ext = trim((string)get_post_meta($post->ID, '_spr_file_url', true));
            if ($ext !== '' && preg_match('#^https?://#i', $ext) === 1) {
                $countIt();
                header('Cache-Control: private, no-store');
                wp_redirect($ext, 302); // phpcs:ignore WordPress.Security.SafeRedirect -- the editor chose this URL
                exit;
            }
            self::fail(404, __('The file could not be found.', 'sepo-resources'));
        }

        self::stream($path, $doc, $inline, $countIt);
    }

    /**
     * @param array<string,mixed> $doc
     */
    private static function stream(string $path, array $doc, bool $inline, callable $countIt): void
    {
        $size = (int)filesize($path);
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = Helpers::mime($ext);

        $start = 0;
        $end = $size - 1;
        $partial = false;
        $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('~^bytes=(\d*)-(\d*)$~', trim($range), $m) === 1) {
            $reqStart = $m[1] === '' ? null : (int)$m[1];
            $reqEnd = $m[2] === '' ? null : (int)$m[2];
            if ($reqStart === null && $reqEnd !== null) {
                $start = max(0, $size - $reqEnd);
            } else {
                $start = (int)$reqStart;
                if ($reqEnd !== null) {
                    $end = min($reqEnd, $size - 1);
                }
            }
            if ($start > $end || $start >= $size) {
                status_header(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            $partial = true;
        }

        $isHead = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'HEAD';
        if (!$partial && !$isHead) {
            $countIt();
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        @ini_set('zlib.output_compression', 'Off');
        @set_time_limit(0);

        $filename = sanitize_file_name(basename($path));
        $length = $end - $start + 1;

        status_header($partial ? 206 : 200);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . $length);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $filename . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=0, no-store');
        header('X-Robots-Tag: noindex');
        if ($partial) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        if ($isHead) {
            exit;
        }

        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            exit;
        }
        fseek($fh, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fh) && !connection_aborted()) {
            $chunk = fread($fh, min(65536, $remaining));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput
            flush();
            $remaining -= strlen($chunk);
        }
        fclose($fh);
        exit;
    }

    /**
     * Count one download by the current visitor. Bots, crawlers and scripts
     * are written to the activity log but left out of the total, unless
     * "Count bots" is on in settings.
     *
     * @return array{total:int,yours:int,counted:bool}
     */
    public static function count(int $postId): array
    {
        $user = wp_get_current_user();
        $uid = (int)$user->ID;
        $name = (string)($user->display_name ?? '');
        if (Visitor::isBot() && !(int)Settings::get('count_bots')) {
            Activity::log($postId, Activity::DOWNLOAD, $uid, $name, false);
            return ['total' => EventStore::downloadTotal($postId), 'yours' => 0, 'counted' => false];
        }
        return EventStore::recordDownload($postId, $uid, $name) + ['counted' => true];
    }

    /**
     * True the first time this visitor downloads this document within the
     * counting window; false on repeats. Uses a short-lived transient per
     * visitor and document, since WordPress does not run PHP sessions.
     */
    public static function countOnce(int $postId): bool
    {
        $window = (int)Settings::get('count_window');
        if ($window <= 0) {
            return true;
        }
        $uid = get_current_user_id();
        $who = $uid > 0
            ? 'u' . $uid
            : 'g' . md5((string)($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $key = 'spr_seen_' . md5($who . '|' . $postId);
        if (get_transient($key)) {
            return false;
        }
        set_transient($key, 1, $window);
        return true;
    }

    /** A published document by its slug (post_name). */
    public static function findBySlug(string $slug): ?WP_Post
    {
        $p = get_page_by_path(sanitize_title(basename(trim($slug, '/'))), OBJECT, PostType::POST_TYPE);
        return $p instanceof WP_Post ? $p : null;
    }

    /** @return never */
    private static function fail(int $code, string $message): void
    {
        status_header($code);
        nocache_headers();
        wp_die(esc_html($message), esc_html(get_bloginfo('name')), ['response' => $code, 'back_link' => true]);
    }
}
