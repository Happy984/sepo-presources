<?php

declare(strict_types=1);

namespace SepoResources;

use InvalidArgumentException;
use Throwable;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;

/**
 * REST routes under /wp-json/sepo-resources/v1.
 *
 *   GET  /stats?doc=<id|slug>        public
 *   POST /downloaded  {doc}           public — counts a download made outside
 *                                     the streamer (API clients); gated
 *                                     documents are counted by the streamer only
 *   POST /like        {doc}           signed in
 *   POST /reset       {doc, what}     manage_sepo_resources
 *
 * Responses are {status: 'success', data: {...}} or {status: 'error', message}.
 * Signed-in requests must send the wp_rest nonce as X-WP-Nonce.
 */
final class Rest
{
    public const NS = 'sepo-resources/v1';

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $doc = ['doc' => ['required' => true, 'type' => ['integer', 'string']]];

        register_rest_route(self::NS, '/stats', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'stats'],
            'permission_callback' => '__return_true',
            'args'                => $doc,
        ]);
        register_rest_route(self::NS, '/downloaded', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'downloaded'],
            'permission_callback' => '__return_true',
            'args'                => $doc,
        ]);
        register_rest_route(self::NS, '/like', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'like'],
            'permission_callback' => static fn(): bool => true, // answered with a friendly 401 below
            'args'                => $doc,
        ]);
        register_rest_route(self::NS, '/reset', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'reset'],
            'permission_callback' => static fn(): bool => current_user_can(Activator::CAP),
            'args'                => $doc + ['what' => ['type' => 'string', 'default' => 'all']],
        ]);
    }

    public static function stats(WP_REST_Request $req): WP_REST_Response
    {
        $post = self::resolve($req);
        if (!$post) {
            return self::error(__('Document not found.', 'sepo-resources'), 404);
        }
        $summary = EventStore::summary($post->ID, get_current_user_id());
        return self::ok(['doc' => $post->ID, 'slug' => $post->post_name] + $summary);
    }

    public static function downloaded(WP_REST_Request $req): WP_REST_Response
    {
        $post = self::resolve($req);
        if (!$post) {
            return self::error(__('Document not found.', 'sepo-resources'), 404);
        }
        $doc = Document::fromPost($post);
        if (!$doc['download'] || !$doc['has_file']) {
            return self::ok(['counted' => false, 'reason' => 'unavailable']);
        }
        if ($doc['gated']) {
            return self::ok(['counted' => false, 'reason' => 'served']);
        }
        if (!Download::countOnce($post->ID)) {
            return self::ok(['counted' => false, 'total' => EventStore::downloadTotal($post->ID)]);
        }
        try {
            $r = Download::count($post->ID);
        } catch (Throwable $e) {
            error_log('[sepo-resources] download count failed: ' . $e->getMessage());
            return self::error(__('Could not record that download.', 'sepo-resources'), 500);
        }
        return self::ok(['counted' => $r['counted'], 'total' => $r['total'], 'yours' => $r['yours']]);
    }

    public static function like(WP_REST_Request $req): WP_REST_Response
    {
        if (!is_user_logged_in()) {
            return self::error(__('Sign in to like this document.', 'sepo-resources'), 401);
        }
        $post = self::resolve($req);
        if (!$post) {
            return self::error(__('Document not found.', 'sepo-resources'), 404);
        }
        try {
            $user = wp_get_current_user();
            $r = EventStore::toggleLike($post->ID, (int)$user->ID, (string)$user->display_name);
        } catch (InvalidArgumentException $e) {
            return self::error($e->getMessage(), 422);
        } catch (Throwable $e) {
            error_log('[sepo-resources] like failed: ' . $e->getMessage());
            return self::error(__('Could not record that like.', 'sepo-resources'), 500);
        }
        return self::ok(['doc' => $post->ID, 'liked' => $r['liked'], 'count' => $r['count']]);
    }

    public static function reset(WP_REST_Request $req): WP_REST_Response
    {
        $post = self::resolve($req, true);
        if (!$post) {
            return self::error(__('Document not found.', 'sepo-resources'), 404);
        }
        $what = strtolower(trim((string)$req->get_param('what'))) ?: 'all';
        try {
            $ok = EventStore::reset($post->ID, $what);
        } catch (InvalidArgumentException $e) {
            return self::error($e->getMessage(), 422);
        }
        $res = self::ok(['reset' => $ok] + EventStore::summary($post->ID));
        if (!$ok) {
            $res->set_status(404);
            $res->set_data(['status' => 'error', 'data' => $res->get_data()['data']]);
        }
        return $res;
    }

    /** Accepts a post id or a post slug. */
    private static function resolve(WP_REST_Request $req, bool $anyStatus = false): ?WP_Post
    {
        $raw = trim((string)$req->get_param('doc'));
        if ($raw === '') {
            return null;
        }
        $post = ctype_digit($raw) ? get_post((int)$raw) : Download::findBySlug($raw);
        if (!$post instanceof WP_Post || $post->post_type !== PostType::POST_TYPE) {
            return null;
        }
        if (!$anyStatus && $post->post_status !== 'publish') {
            return null;
        }
        return $post;
    }

    /** @param array<string,mixed> $data */
    private static function ok(array $data): WP_REST_Response
    {
        return new WP_REST_Response(['status' => 'success', 'data' => $data], 200);
    }

    private static function error(string $message, int $code): WP_REST_Response
    {
        return new WP_REST_Response(['status' => 'error', 'message' => $message], $code);
    }
}
