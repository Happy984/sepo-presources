<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * Keeps document files out of public reach.
 *
 * Any non-image file uploaded to a document from its edit screen lands in uploads/sepo-resources/, which holds
 * a deny-all .htaccess. The only way to fetch it is the download streamer,
 * which applies the sign-in and download rules and counts the download.
 *
 * Images (featured images, author photos) stay in the normal uploads
 * folders because the theme has to show them.
 *
 * On nginx the .htaccess file is ignored; add the location rule shown on
 * the plugin's settings page.
 */
final class Uploads
{
    public const SUBDIR = 'sepo-resources';

    private static bool $protectNext = false;

    public static function init(): void
    {
        add_filter('wp_handle_upload_prefilter', [self::class, 'prefilter']);
        add_filter('wp_handle_sideload_prefilter', [self::class, 'prefilter']);
        add_filter('upload_dir', [self::class, 'uploadDir']);
        add_filter('wp_handle_upload', [self::class, 'done']);
        add_filter('wp_handle_sideload', [self::class, 'done']);
    }

    /**
     * @param array<string,mixed> $file
     * @return array<string,mixed>
     */
    public static function prefilter(array $file): array
    {
        self::$protectNext = false;
        if (!(int)Settings::get('protect_uploads')) {
            return $file;
        }
        $type = (string)($file['type'] ?? '');
        $isImage = str_starts_with($type, 'image/')
            || preg_match('~\.(png|jpe?g|gif|webp|avif|svg)$~i', (string)($file['name'] ?? '')) === 1;
        if ($isImage) {
            return $file;
        }
        if (self::isResourceUpload()) {
            self::$protectNext = true;
        }
        return $file;
    }

    /**
     * @param array<string,mixed> $dirs
     * @return array<string,mixed>
     */
    public static function uploadDir(array $dirs): array
    {
        if (!self::$protectNext) {
            return $dirs;
        }
        $dirs['subdir'] = '/' . self::SUBDIR;
        $dirs['path'] = $dirs['basedir'] . '/' . self::SUBDIR;
        $dirs['url'] = $dirs['baseurl'] . '/' . self::SUBDIR;
        // Pass basedir in: calling wp_get_upload_dir() here would re-run
        // this filter and recurse until PHP runs out of memory.
        self::ensureProtectedDir((string)$dirs['basedir']);
        return $dirs;
    }

    /**
     * @param array<string,mixed> $upload
     * @return array<string,mixed>
     */
    public static function done(array $upload): array
    {
        self::$protectNext = false;
        return $upload;
    }

    /** True when the media modal is uploading to a sepo_resource post. */
    private static function isResourceUpload(): bool
    {
        $postId = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
        return $postId > 0 && get_post_type($postId) === PostType::POST_TYPE;
    }

    public static function protectedDir(?string $basedir = null): string
    {
        if ($basedir === null) {
            $up = wp_get_upload_dir();
            $basedir = (string)$up['basedir'];
        }
        return rtrim($basedir, '/\\') . '/' . self::SUBDIR;
    }

    public static function ensureProtectedDir(?string $basedir = null): void
    {
        $dir = self::protectedDir($basedir);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $ht = $dir . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Sepo Resources: files here are served only through the download link.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
        }
        $idx = $dir . '/index.php';
        if (!is_file($idx)) {
            @file_put_contents($idx, "<?php\n// Silence is golden.\n");
        }
    }

    public static function nginxSnippet(): string
    {
        $up = wp_get_upload_dir();
        $path = (string)wp_parse_url((string)$up['baseurl'], PHP_URL_PATH);
        return 'location ^~ ' . rtrim($path, '/') . '/' . self::SUBDIR . '/ { deny all; return 403; }';
    }
}
