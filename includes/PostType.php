<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * The document post type, its two taxonomies (groups and keywords), and
 * the post meta that describes each document.
 */
final class PostType
{
    public const POST_TYPE = 'sepo_resource';
    public const GROUP = 'resource_group';
    public const TAG = 'resource_tag';

    /**
     * Every meta key the plugin owns, with its type. Kept in one place so
     * the meta box and uninstall agree.
     *
     * @var array<string,string>
     */
    public const META = [
        '_spr_file_id'         => 'integer',
        '_spr_file_url'        => 'string',
        '_spr_format'          => 'string',
        '_spr_size'            => 'string',
        '_spr_pages'           => 'string',
        '_spr_version'         => 'string',
        '_spr_author'          => 'string',
        '_spr_author_role'     => 'string',
        '_spr_author_org'      => 'string',
        '_spr_author_url'      => 'string',
        '_spr_author_image_id' => 'integer',
        '_spr_published'       => 'string',
        '_spr_updated'         => 'string',
        '_spr_language'        => 'string',
        '_spr_licence'         => 'string',
        '_spr_reference'       => 'string',
        '_spr_allow_download'  => 'string',  // '1' | '0'
        '_spr_preview'         => 'string',  // '' auto | '1' | '0'
        '_spr_requires_login'  => 'string',  // '1' | '0'
        '_spr_downloads'       => 'integer', // mirror of EventStore totals, for sorting
        '_spr_likes'           => 'integer',
    ];

    public static function init(): void
    {
        add_action('init', [self::class, 'register']);
        add_filter('wp_unique_post_slug', [self::class, 'reserveSlugs'], 10, 4);
        add_action('before_delete_post', [self::class, 'onDelete']);
    }

    public static function register(): void
    {
        $base = Settings::base();

        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name'               => __('Resources', 'sepo-resources'),
                'singular_name'      => __('Resource', 'sepo-resources'),
                'add_new'            => __('Add document', 'sepo-resources'),
                'add_new_item'       => __('Add document', 'sepo-resources'),
                'edit_item'          => __('Edit document', 'sepo-resources'),
                'new_item'           => __('New document', 'sepo-resources'),
                'view_item'          => __('View document', 'sepo-resources'),
                'search_items'       => __('Search documents', 'sepo-resources'),
                'not_found'          => __('No documents yet.', 'sepo-resources'),
                'not_found_in_trash' => __('No documents in the trash.', 'sepo-resources'),
                'all_items'          => __('All documents', 'sepo-resources'),
                'menu_name'          => __('Resources', 'sepo-resources'),
            ],
            'public'          => true,
            'show_in_rest'    => true,
            'has_archive'     => $base,
            'rewrite'         => ['slug' => $base, 'with_front' => false],
            'menu_icon'       => 'dashicons-media-document',
            'menu_position'   => 21,
            'supports'        => ['title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions'],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        register_taxonomy(self::GROUP, self::POST_TYPE, [
            'labels' => [
                'name'          => __('Groups', 'sepo-resources'),
                'singular_name' => __('Group', 'sepo-resources'),
                'add_new_item'  => __('Add group', 'sepo-resources'),
                'edit_item'     => __('Edit group', 'sepo-resources'),
                'search_items'  => __('Search groups', 'sepo-resources'),
                'all_items'     => __('All groups', 'sepo-resources'),
                'menu_name'     => __('Groups', 'sepo-resources'),
            ],
            'hierarchical'      => true,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'resource-group', 'with_front' => false],
        ]);

        register_taxonomy(self::TAG, self::POST_TYPE, [
            'labels' => [
                'name'          => __('Keywords', 'sepo-resources'),
                'singular_name' => __('Keyword', 'sepo-resources'),
                'add_new_item'  => __('Add keyword', 'sepo-resources'),
                'search_items'  => __('Search keywords', 'sepo-resources'),
                'menu_name'     => __('Keywords', 'sepo-resources'),
            ],
            'hierarchical'      => false,
            'public'            => true,
            'show_in_rest'      => true,
            'show_admin_column' => false,
            'rewrite'           => ['slug' => 'resource-tag', 'with_front' => false],
        ]);

        // Meta stays out of the REST API on purpose: file ids and URLs are
        // only ever handed out through the download streamer.
        foreach (self::META as $key => $type) {
            register_post_meta(self::POST_TYPE, $key, [
                'type'          => $type,
                'single'        => true,
                'show_in_rest'  => false,
                'auth_callback' => static fn(): bool => current_user_can('edit_posts'),
            ]);
        }

        register_term_meta(self::GROUP, 'spr_order', [
            'type'         => 'integer',
            'single'       => true,
            'show_in_rest' => false,
        ]);
    }

    /** "download" under the document base is the download route; no document may take it. */
    public static function reserveSlugs(string $slug, int $postId, string $status, string $postType): string
    {
        if ($postType === self::POST_TYPE && in_array($slug, ['download'], true)) {
            return $slug . '-2';
        }
        return $slug;
    }

    public static function onDelete(int $postId): void
    {
        if (get_post_type($postId) === self::POST_TYPE) {
            EventStore::deleteFor($postId);
        }
    }
}
