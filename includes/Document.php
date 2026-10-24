<?php

declare(strict_types=1);

namespace SepoResources;

use WP_Post;

/**
 * Shapes one document post into the array the views consume.
 *
 * Everything is read from WordPress: post fields, post meta, the two
 * taxonomies and the Media Library. Filter the result with
 * `sepo_resources_document`.
 */
final class Document
{
    /** @var array<int,array<string,mixed>> */
    private static array $cache = [];

    /** @return array<string,mixed> */
    public static function fromPost(WP_Post|int $post): array
    {
        $post = get_post($post);
        if (!$post instanceof WP_Post) {
            return [];
        }
        if (isset(self::$cache[$post->ID])) {
            return self::$cache[$post->ID];
        }

        $id = $post->ID;
        $m = static fn(string $k): string => trim((string)get_post_meta($id, $k, true));

        // ---- the file ----------------------------------------------------
        $fileId = (int)get_post_meta($id, '_spr_file_id', true);
        $fileUrl = $m('_spr_file_url');
        $path = null;
        $rawSrc = '';
        if ($fileId > 0) {
            $attached = get_attached_file($fileId);
            if (is_string($attached) && $attached !== '') {
                $rawSrc = $attached;
                $path = is_file($attached) ? $attached : null;
            }
        }
        if ($rawSrc === '' && $fileUrl !== '') {
            $rawSrc = $fileUrl;
        }
        $hasFile = $rawSrc !== '';
        $external = $rawSrc !== '' && $path === null && preg_match('#^https?://#i', $rawSrc) === 1;

        // ---- format and size --------------------------------------------
        $ext = strtolower($m('_spr_format'));
        if ($ext === '' && $rawSrc !== '') {
            $ext = strtolower(pathinfo((string)(wp_parse_url($rawSrc, PHP_URL_PATH) ?: $rawSrc), PATHINFO_EXTENSION));
        }
        [$formatLabel, $formatClass] = Helpers::format($ext);

        $size = $m('_spr_size');
        $bytes = 0;
        if ($size === '' && $path !== null) {
            $bytes = (int)@filesize($path);
            $size = Helpers::bytes($bytes);
        }

        // ---- flags --------------------------------------------------------
        $allowRaw = get_post_meta($id, '_spr_allow_download', true);
        $allow = $allowRaw === '' ? (bool)Settings::get('default_allow_download') : $allowRaw === '1';

        $gatedRaw = get_post_meta($id, '_spr_requires_login', true);
        $gated = $gatedRaw === '' ? (bool)Settings::get('default_requires_login') : $gatedRaw === '1';

        $previewRaw = (string)get_post_meta($id, '_spr_preview', true);
        $preview = $previewRaw === '' ? ($ext === 'pdf') : $previewRaw === '1';

        // ---- taxonomy -----------------------------------------------------
        $groups = get_the_terms($id, PostType::GROUP);
        $group = '';
        $groupSlug = '';
        $groupId = 0;
        if (is_array($groups) && $groups !== []) {
            $g = $groups[0];
            $group = $g->name;
            $groupSlug = $g->slug;
            $groupId = (int)$g->term_id;
        }
        $tagTerms = get_the_terms($id, PostType::TAG);
        $tags = is_array($tagTerms) ? array_values(array_map(static fn($t) => $t->name, $tagTerms)) : [];

        // ---- images -------------------------------------------------------
        $image = (string)(get_the_post_thumbnail_url($post, 'medium') ?: '');
        $avatarId = (int)get_post_meta($id, '_spr_author_image_id', true);
        $avatar = $avatarId > 0 ? (string)(wp_get_attachment_image_url($avatarId, 'thumbnail') ?: '') : '';

        $published = $m('_spr_published');
        if ($published === '') {
            $published = get_the_date('Y-m-d', $post) ?: '';
        }

        $doc = [
            'id'           => $id,
            'key'          => $id,
            'slug'         => $post->post_name,
            'title'        => get_the_title($post) ?: $post->post_name,
            'url'          => (string)get_permalink($post),
            'has_file'     => $hasFile,
            'external'     => $external,
            'src'          => $hasFile ? Download::url($id) : '',
            'raw_src'      => $rawSrc,
            'ext'          => $ext,
            'format'       => $formatLabel,
            'format_class' => $formatClass,
            'size'         => $size,
            'size_bytes'   => $bytes,
            'pages'        => $m('_spr_pages'),
            'version'      => $m('_spr_version'),
            'image'        => $image,
            'author'       => $m('_spr_author'),
            'author_role'  => $m('_spr_author_role'),
            'author_org'   => $m('_spr_author_org'),
            'author_url'   => $m('_spr_author_url'),
            'author_image' => $avatar,
            'published'    => $published,
            'updated'      => $m('_spr_updated'),
            'summary'      => trim((string)$post->post_excerpt),
            'group'        => $group,
            'group_slug'   => $groupSlug,
            'group_id'     => $groupId,
            'tags'         => $tags,
            'language'     => $m('_spr_language'),
            'licence'      => $m('_spr_licence'),
            'reference'    => $m('_spr_reference'),
            'preview'      => $preview,
            'download'     => $allow,
            'gated'        => $gated,
            'order'        => (int)$post->menu_order,
        ];

        /** Let themes and add-ons adjust what the views see. */
        $doc = (array)apply_filters('sepo_resources_document', $doc, $post);

        return self::$cache[$id] = $doc;
    }

    /** Absolute local path of the attached file, or null. Only paths inside wp-content are trusted. */
    public static function localPath(int $postId): ?string
    {
        $fileId = (int)get_post_meta($postId, '_spr_file_id', true);
        if ($fileId <= 0) {
            return null;
        }
        $path = get_attached_file($fileId);
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        $up = wp_get_upload_dir();
        foreach ([WP_CONTENT_DIR, (string)$up['basedir']] as $base) {
            $root = realpath($base);
            if ($root !== false && str_starts_with($real, $root . DIRECTORY_SEPARATOR)) {
                return $real;
            }
        }
        return null;
    }
}
