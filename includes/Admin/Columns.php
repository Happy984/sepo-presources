<?php

declare(strict_types=1);

namespace SepoResources\Admin;

use SepoResources\Activator;
use SepoResources\Document;
use SepoResources\EventStore;
use SepoResources\PostType;
use WP_Post;
use WP_Query;
use WP_Term;

/**
 * "All documents" list table (Format, Downloads, Likes, reset row action)
 * and the group screen's Display order field.
 */
final class Columns
{
    public static function init(): void
    {
        $pt = PostType::POST_TYPE;
        add_filter("manage_{$pt}_posts_columns", [self::class, 'columns']);
        add_action("manage_{$pt}_posts_custom_column", [self::class, 'cell'], 10, 2);
        add_filter("manage_edit-{$pt}_sortable_columns", [self::class, 'sortable']);
        add_action('pre_get_posts', [self::class, 'sort']);
        add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);

        $tx = PostType::GROUP;
        add_action("{$tx}_add_form_fields", [self::class, 'termAddField']);
        add_action("{$tx}_edit_form_fields", [self::class, 'termEditField']);
        add_action("created_{$tx}", [self::class, 'termSave']);
        add_action("edited_{$tx}", [self::class, 'termSave']);
        add_filter("manage_edit-{$tx}_columns", static function (array $c): array {
            $c['spr_order'] = __('Order', 'sepo-resources');
            return $c;
        });
        add_filter("manage_{$tx}_custom_column", static function (string $out, string $col, int $termId): string {
            if ($col !== 'spr_order') {
                return $out;
            }
            $o = get_term_meta($termId, 'spr_order', true);
            return $o === '' ? '—' : esc_html((string)$o);
        }, 10, 3);
    }

    /** @param array<string,string> $cols */
    public static function columns(array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            if ($k === 'title') {
                $out['spr_format'] = __('Format', 'sepo-resources');
            }
            $out[$k] = $v;
            if ($k === 'title') {
                $out['spr_downloads'] = __('Downloads', 'sepo-resources');
                $out['spr_likes'] = __('Likes', 'sepo-resources');
            }
        }
        return $out;
    }

    public static function cell(string $col, int $postId): void
    {
        static $counts = null;
        if ($col === 'spr_format') {
            $d = Document::fromPost($postId);
            if (!$d['has_file']) {
                echo '<span class="spr-fmt spr-fmt--none" title="' . esc_attr__('No file attached', 'sepo-resources') . '">—</span>';
                return;
            }
            printf(
                '<span class="spr-fmt spr-fmt--%s">%s</span>%s',
                esc_attr($d['format_class']),
                esc_html($d['format']),
                $d['gated'] ? ' <span class="dashicons dashicons-lock" title="' . esc_attr__('Sign-in required', 'sepo-resources') . '"></span>' : ''
            );
            return;
        }
        if ($col !== 'spr_downloads' && $col !== 'spr_likes') {
            return;
        }
        if ($counts === null) {
            global $wp_query;
            $ids = array_map(static fn($p) => $p instanceof WP_Post ? $p->ID : (int)$p, (array)($wp_query->posts ?? []));
            $counts = EventStore::counts($ids);
        }
        $c = $counts[$postId] ?? EventStore::counts([$postId])[$postId];
        echo (int)($col === 'spr_downloads' ? $c['downloads'] : $c['likes']);
    }

    /** @param array<string,string> $cols */
    public static function sortable(array $cols): array
    {
        $cols['spr_downloads'] = 'spr_downloads';
        $cols['spr_likes'] = 'spr_likes';
        return $cols;
    }

    public static function sort(WP_Query $q): void
    {
        if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== PostType::POST_TYPE) {
            return;
        }
        $by = (string)$q->get('orderby');
        if ($by !== 'spr_downloads' && $by !== 'spr_likes') {
            return;
        }
        $key = $by === 'spr_downloads' ? '_spr_downloads' : '_spr_likes';
        // EXISTS/NOT EXISTS pair keeps documents that were never counted in the list.
        $q->set('meta_query', [
            'relation' => 'OR',
            'spr_sort' => ['key' => $key, 'compare' => 'EXISTS', 'type' => 'NUMERIC'],
            ['key' => $key, 'compare' => 'NOT EXISTS'],
        ]);
        $q->set('orderby', 'spr_sort');
    }

    /**
     * @param array<string,string> $actions
     * @return array<string,string>
     */
    public static function rowActions(array $actions, WP_Post $post): array
    {
        if ($post->post_type !== PostType::POST_TYPE || !current_user_can(Activator::CAP)) {
            return $actions;
        }
        $actions['spr_reset'] = sprintf(
            '<a href="%s" onclick="return confirm(%s)">%s</a>',
            esc_url(MetaBoxes::resetUrl($post->ID, 'all')),
            esc_attr(wp_json_encode(__('Reset likes and downloads for this document? This can’t be undone.', 'sepo-resources'))),
            esc_html__('Reset counts', 'sepo-resources')
        );
        return $actions;
    }

    // ----------------------------------------------------------- groups

    public static function termAddField(): void
    {
        wp_nonce_field('spr_term_order', 'spr_term_nonce');
        ?>
        <div class="form-field">
            <label for="spr_order"><?php esc_html_e('Display order', 'sepo-resources'); ?></label>
            <input type="number" name="spr_order" id="spr_order" value="" step="1">
            <p><?php esc_html_e('Lower numbers show first on the library page. Groups without a number follow, A to Z.', 'sepo-resources'); ?></p>
        </div>
        <?php
    }

    public static function termEditField(WP_Term $term): void
    {
        $o = get_term_meta($term->term_id, 'spr_order', true);
        ?>
        <tr class="form-field">
            <th scope="row"><label for="spr_order"><?php esc_html_e('Display order', 'sepo-resources'); ?></label></th>
            <td>
                <?php wp_nonce_field('spr_term_order', 'spr_term_nonce'); ?>
                <input type="number" name="spr_order" id="spr_order" value="<?php echo esc_attr((string)$o); ?>" step="1">
                <p class="description"><?php esc_html_e('Lower numbers show first on the library page. Groups without a number follow, A to Z.', 'sepo-resources'); ?></p>
            </td>
        </tr>
        <?php
    }

    public static function termSave(int $termId): void
    {
        if (!isset($_POST['spr_term_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['spr_term_nonce'])), 'spr_term_order')) {
            return;
        }
        if (!current_user_can('manage_categories')) {
            return;
        }
        $raw = trim((string)($_POST['spr_order'] ?? ''));
        if ($raw === '' || !is_numeric($raw)) {
            delete_term_meta($termId, 'spr_order');
        } else {
            update_term_meta($termId, 'spr_order', (int)$raw);
        }
    }
}
