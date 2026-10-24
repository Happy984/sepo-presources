<?php

declare(strict_types=1);

namespace SepoResources\Admin;

use SepoResources\Activator;
use SepoResources\Activity;
use SepoResources\Document;
use SepoResources\EventStore;
use SepoResources\PostType;
use SepoResources\Settings;
use WP_Post;

/**
 * The document edit screen: Document, Author, Download rules and
 * Engagement boxes.
 */
final class MetaBoxes
{
    private const NONCE = 'spr_meta_nonce';

    public static function init(): void
    {
        add_action('add_meta_boxes_' . PostType::POST_TYPE, [self::class, 'add']);
        add_action('save_post_' . PostType::POST_TYPE, [self::class, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_spr_reset', [self::class, 'handleReset']);
        add_action('admin_notices', [self::class, 'notices']);
        add_filter('enter_title_here', static function (string $t, WP_Post $p): string {
            return $p->post_type === PostType::POST_TYPE ? __('Document title', 'sepo-resources') : $t;
        }, 10, 2);
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== PostType::POST_TYPE) {
            return;
        }
        wp_enqueue_style('sepo-resources-admin', SPR_URL . 'assets/css/admin.css', [], SPR_VERSION);
        if (in_array($hook, ['post.php', 'post-new.php'], true)) {
            wp_enqueue_media();
            wp_enqueue_script('sepo-resources-admin', SPR_URL . 'assets/js/admin.js', ['jquery'], SPR_VERSION, true);
            wp_localize_script('sepo-resources-admin', 'SepoResourcesAdmin', [
                'chooseFile'  => __('Choose a document', 'sepo-resources'),
                'chooseImage' => __('Choose a photo', 'sepo-resources'),
                'use'         => __('Use this file', 'sepo-resources'),
                'none'        => __('No file attached', 'sepo-resources'),
            ]);
        }
    }

    public static function add(WP_Post $post): void
    {
        add_meta_box('spr_document', __('Document', 'sepo-resources'), [self::class, 'boxDocument'], null, 'normal', 'high');
        add_meta_box('spr_author', __('Author', 'sepo-resources'), [self::class, 'boxAuthor'], null, 'normal', 'default');
        add_meta_box('spr_rules', __('Download rules', 'sepo-resources'), [self::class, 'boxRules'], null, 'side', 'default');
        if ($post->post_status !== 'auto-draft') {
            add_meta_box('spr_stats', __('Engagement', 'sepo-resources'), [self::class, 'boxStats'], null, 'side', 'default');
            if (current_user_can(Activator::CAP)) {
                add_meta_box('spr_activity', __('Who liked and downloaded it', 'sepo-resources'), [self::class, 'boxActivity'], null, 'normal', 'low');
            }
        }
        // The excerpt is the document summary; say so.
        add_filter('gettext', static function (string $tr, string $text, string $domain): string {
            if ($domain === 'default' && $text === 'Excerpt') {
                return __('Summary', 'sepo-resources');
            }
            return $tr;
        }, 10, 3);
    }

    private static function val(int $id, string $key): string
    {
        return (string)get_post_meta($id, $key, true);
    }

    private static function input(int $id, string $key, string $label, string $type = 'text', string $placeholder = ''): void
    {
        $name = substr($key, 1); // _spr_x -> spr_x
        printf(
            '<p class="spr-field"><label for="%1$s">%2$s</label><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" placeholder="%5$s" class="widefat"></p>',
            esc_attr($name),
            esc_html($label),
            esc_attr($type),
            esc_attr(self::val($id, $key)),
            esc_attr($placeholder)
        );
    }

    public static function boxDocument(WP_Post $post): void
    {
        wp_nonce_field('spr_save_' . $post->ID, self::NONCE);
        $fileId = (int)get_post_meta($post->ID, '_spr_file_id', true);
        $fileName = '';
        if ($fileId > 0) {
            $path = get_attached_file($fileId);
            $fileName = is_string($path) ? basename($path) : '';
        }
        $doc = $post->post_status !== 'auto-draft' ? Document::fromPost($post) : null;
        ?>
        <div class="spr-file" data-spr-file>
            <input type="hidden" name="spr_file_id" value="<?php echo (int)$fileId; ?>" data-spr-file-id>
            <span class="spr-file-name" data-spr-file-name><?php echo $fileName !== '' ? esc_html($fileName) : esc_html__('No file attached', 'sepo-resources'); ?></span>
            <button type="button" class="button" data-spr-file-pick><?php esc_html_e('Upload or choose file', 'sepo-resources'); ?></button>
            <button type="button" class="button-link button-link-delete" data-spr-file-clear<?php echo $fileId > 0 ? '' : ' hidden'; ?>><?php esc_html_e('Remove', 'sepo-resources'); ?></button>
        </div>
        <p class="description">
            <?php
            echo (int)Settings::get('protect_uploads')
                ? esc_html__('Documents uploaded here are stored in a protected folder and only reach visitors through the download link.', 'sepo-resources')
                : esc_html__('Documents uploaded here go to the normal uploads folder.', 'sepo-resources');
            ?>
        </p>
        <?php self::input($post->ID, '_spr_file_url', __('Or link to a file hosted elsewhere', 'sepo-resources'), 'url', 'https://'); ?>

        <div class="spr-grid">
            <?php
            self::input($post->ID, '_spr_format', __('Format', 'sepo-resources'), 'text', $doc && $doc['ext'] !== '' ? $doc['ext'] : 'pdf');
            self::input($post->ID, '_spr_size', __('Size', 'sepo-resources'), 'text', $doc && $doc['size'] !== '' ? $doc['size'] : '2.4 MB');
            self::input($post->ID, '_spr_pages', __('Pages', 'sepo-resources'), 'text', '38');
            self::input($post->ID, '_spr_version', __('Version', 'sepo-resources'), 'text', '1.0');
            self::input($post->ID, '_spr_published', __('Published', 'sepo-resources'), 'date');
            self::input($post->ID, '_spr_updated', __('Last updated', 'sepo-resources'), 'date');
            self::input($post->ID, '_spr_language', __('Language', 'sepo-resources'), 'text', 'English');
            self::input($post->ID, '_spr_licence', __('Licence', 'sepo-resources'), 'text', 'CC BY 4.0');
            self::input($post->ID, '_spr_reference', __('Reference, DOI or ISBN', 'sepo-resources'), 'text');
            ?>
        </div>
        <p class="description"><?php esc_html_e('Leave format and size empty to read them from the file. Leave Published empty to use the post date.', 'sepo-resources'); ?></p>
        <?php
    }

    public static function boxAuthor(WP_Post $post): void
    {
        $imgId = (int)get_post_meta($post->ID, '_spr_author_image_id', true);
        $img = $imgId > 0 ? (string)wp_get_attachment_image_url($imgId, 'thumbnail') : '';
        ?>
        <div class="spr-author-box">
            <div class="spr-avatar-pick" data-spr-image>
                <input type="hidden" name="spr_author_image_id" value="<?php echo (int)$imgId; ?>" data-spr-image-id>
                <span class="spr-avatar-preview" data-spr-image-preview<?php echo $img !== '' ? ' style="background-image:url(\'' . esc_url($img) . '\')"' : ''; ?>></span>
                <button type="button" class="button-link" data-spr-image-pick><?php esc_html_e('Choose photo', 'sepo-resources'); ?></button>
                <button type="button" class="button-link button-link-delete" data-spr-image-clear<?php echo $imgId > 0 ? '' : ' hidden'; ?>><?php esc_html_e('Remove', 'sepo-resources'); ?></button>
            </div>
            <div class="spr-grid spr-grid--author">
                <?php
                self::input($post->ID, '_spr_author', __('Name', 'sepo-resources'));
                self::input($post->ID, '_spr_author_role', __('Role', 'sepo-resources'));
                self::input($post->ID, '_spr_author_org', __('Organisation', 'sepo-resources'));
                self::input($post->ID, '_spr_author_url', __('Profile link', 'sepo-resources'), 'url', 'https://');
                ?>
            </div>
        </div>
        <?php
    }

    public static function boxRules(WP_Post $post): void
    {
        $isNew = $post->post_status === 'auto-draft';
        $allowRaw = self::val($post->ID, '_spr_allow_download');
        $loginRaw = self::val($post->ID, '_spr_requires_login');
        $allow = $allowRaw === '' ? (bool)Settings::get('default_allow_download') : $allowRaw === '1';
        $login = $loginRaw === '' ? (bool)Settings::get('default_requires_login') : $loginRaw === '1';
        $preview = self::val($post->ID, '_spr_preview');
        unset($isNew);
        ?>
        <p><label><input type="checkbox" name="spr_allow_download" value="1" <?php checked($allow); ?>> <?php esc_html_e('Allow download', 'sepo-resources'); ?></label></p>
        <p><label><input type="checkbox" name="spr_requires_login" value="1" <?php checked($login); ?>> <?php esc_html_e('Sign-in required to download', 'sepo-resources'); ?></label></p>
        <p class="spr-field">
            <label for="spr_preview"><?php esc_html_e('PDF preview', 'sepo-resources'); ?></label>
            <select id="spr_preview" name="spr_preview" class="widefat">
                <option value="" <?php selected($preview, ''); ?>><?php esc_html_e('Automatic (PDFs only)', 'sepo-resources'); ?></option>
                <option value="1" <?php selected($preview, '1'); ?>><?php esc_html_e('Show', 'sepo-resources'); ?></option>
                <option value="0" <?php selected($preview, '0'); ?>><?php esc_html_e('Hide', 'sepo-resources'); ?></option>
            </select>
        </p>
        <p class="description"><?php esc_html_e('Use Page attributes → Order to fix a document’s place within its group.', 'sepo-resources'); ?></p>
        <?php
    }

    public static function boxStats(WP_Post $post): void
    {
        $s = EventStore::summary($post->ID);
        ?>
        <ul class="spr-stats">
            <li><span><?php esc_html_e('Downloads', 'sepo-resources'); ?></span><strong><?php echo (int)$s['downloads']; ?></strong></li>
            <li><span><?php esc_html_e('People who downloaded', 'sepo-resources'); ?></span><strong><?php echo (int)$s['unique']; ?></strong></li>
            <li><span><?php esc_html_e('Likes', 'sepo-resources'); ?></span><strong><?php echo (int)$s['likes']; ?></strong></li>
        </ul>
        <?php
        $recent = EventStore::recent($post->ID, EventStore::DOWNLOAD, 5);
        if ($recent !== []) {
            echo '<p class="spr-recent-h">' . esc_html__('Recent downloads', 'sepo-resources') . '</p><ul class="spr-recent">';
            foreach ($recent as $r) {
                $who = $r['actor'] === EventStore::GUEST
                    ? __('Signed-out visitors', 'sepo-resources')
                    : ($r['name'] !== '' ? $r['name'] : $r['actor']);
                printf(
                    '<li>%s <span>%s · %s</span></li>',
                    esc_html($who),
                    esc_html(sprintf(/* translators: %d: count */ _n('%d time', '%d times', $r['count'], 'sepo-resources'), $r['count'])),
                    esc_html(human_time_diff((int)strtotime($r['last'] . ' UTC')) . ' ' . __('ago', 'sepo-resources'))
                );
            }
            echo '</ul>';
        }

        if (current_user_can(Activator::CAP)) {
            echo '<p class="spr-reset">';
            foreach (['downloads' => __('Reset downloads', 'sepo-resources'), 'likes' => __('Reset likes', 'sepo-resources'), 'all' => __('Reset both', 'sepo-resources')] as $what => $label) {
                printf(
                    '<a class="button-link button-link-delete" href="%s" onclick="return confirm(%s)">%s</a> ',
                    esc_url(self::resetUrl($post->ID, $what)),
                    esc_attr(wp_json_encode(__('This can’t be undone. Continue?', 'sepo-resources'))),
                    esc_html($label)
                );
            }
            echo '</p>';
        }
    }

    /** Countries, browsers, systems and devices for this document, and the latest events. */
    public static function boxActivity(WP_Post $post): void
    {
        $f = Activity::filters(['doc' => $post->ID]);
        $t = Activity::totals($f);
        if ($t['events'] === 0 && $t['bots'] === 0) {
            echo '<p class="spr-empty">' . esc_html__('No likes or downloads recorded yet. Each one will show the country, browser, system and device here.', 'sepo-resources') . '</p>';
            return;
        }
        echo '<div class="spr-breakdowns spr-breakdowns--box">';
        ActivityPage::panel(__('Countries', 'sepo-resources'), 'country', Activity::breakdown('country', $f, 6), $t['events']);
        ActivityPage::panel(__('Browsers', 'sepo-resources'), 'browser', Activity::breakdown('browser', $f, 6), $t['events']);
        ActivityPage::panel(__('Systems', 'sepo-resources'), 'os', Activity::breakdown('os', $f, 6), $t['events']);
        ActivityPage::panel(__('Devices', 'sepo-resources'), 'device', Activity::breakdown('device', $f, 6), $t['events']);
        echo '</div>';
        echo '<h4 class="spr-box-h">' . esc_html__('Latest', 'sepo-resources') . '</h4>';
        ActivityPage::eventsTable(Activity::events($f, 1, 10)['rows'], false);
        echo '<p class="spr-box-foot">';
        printf(
            '<a class="button" href="%s">%s</a> ',
            esc_url(ActivityPage::url(['doc' => $post->ID])),
            esc_html__('See all activity for this document', 'sepo-resources')
        );
        if ($t['bots'] > 0) {
            printf(
                '<span class="spr-muted">%s</span>',
                esc_html(sprintf(/* translators: %s: number */ _n('%s bot hit not shown or counted.', '%s bot hits not shown or counted.', $t['bots'], 'sepo-resources'), number_format_i18n($t['bots'])))
            );
        }
        echo '</p>';
    }

    public static function resetUrl(int $postId, string $what): string
    {
        return wp_nonce_url(
            admin_url('admin-post.php?action=spr_reset&post=' . $postId . '&what=' . rawurlencode($what)),
            'spr_reset_' . $postId
        );
    }

    public static function handleReset(): void
    {
        $id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        check_admin_referer('spr_reset_' . $id);
        if (!current_user_can(Activator::CAP) || get_post_type($id) !== PostType::POST_TYPE) {
            wp_die(esc_html__('You don’t have permission to reset these counts.', 'sepo-resources'), 403);
        }
        $what = sanitize_key((string)($_GET['what'] ?? 'all'));
        $ok = false;
        try {
            $ok = EventStore::reset($id, $what);
        } catch (\InvalidArgumentException) {
            $ok = false;
        }
        $back = wp_get_referer() ?: admin_url('edit.php?post_type=' . PostType::POST_TYPE);
        wp_safe_redirect(add_query_arg('spr_reset', $ok ? '1' : '0', $back));
        exit;
    }

    public static function notices(): void
    {
        if (!isset($_GET['spr_reset'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $ok = $_GET['spr_reset'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            $ok ? 'success' : 'info',
            esc_html($ok ? __('Counts reset.', 'sepo-resources') : __('There was nothing to reset.', 'sepo-resources'))
        );
    }

    public static function save(int $postId, WP_Post $post): void
    {
        if (!isset($_POST[self::NONCE]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE])), 'spr_save_' . $postId)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $text = [
            '_spr_format', '_spr_size', '_spr_pages', '_spr_version', '_spr_author', '_spr_author_role',
            '_spr_author_org', '_spr_language', '_spr_licence', '_spr_reference',
        ];
        foreach ($text as $key) {
            $field = substr($key, 1);
            $v = isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string)$_POST[$field])) : '';
            if ($key === '_spr_format') {
                $v = strtolower(trim($v, '. '));
            }
            self::put($postId, $key, $v);
        }

        foreach (['_spr_published', '_spr_updated'] as $key) {
            $field = substr($key, 1);
            $v = isset($_POST[$field]) ? sanitize_text_field(wp_unslash((string)$_POST[$field])) : '';
            self::put($postId, $key, preg_match('~^\d{4}-\d{2}-\d{2}$~', $v) === 1 ? $v : '');
        }

        foreach (['_spr_file_url', '_spr_author_url'] as $key) {
            $field = substr($key, 1);
            $v = isset($_POST[$field]) ? esc_url_raw(wp_unslash((string)$_POST[$field]), ['http', 'https']) : '';
            self::put($postId, $key, $v);
        }

        foreach (['_spr_file_id', '_spr_author_image_id'] as $key) {
            $field = substr($key, 1);
            $v = isset($_POST[$field]) ? absint($_POST[$field]) : 0;
            if ($v > 0 && get_post_type($v) !== 'attachment') {
                $v = 0;
            }
            self::put($postId, $key, $v > 0 ? (string)$v : '');
        }

        update_post_meta($postId, '_spr_allow_download', empty($_POST['spr_allow_download']) ? '0' : '1');
        update_post_meta($postId, '_spr_requires_login', empty($_POST['spr_requires_login']) ? '0' : '1');
        $preview = isset($_POST['spr_preview']) ? sanitize_key((string)$_POST['spr_preview']) : '';
        self::put($postId, '_spr_preview', in_array($preview, ['1', '0'], true) ? $preview : '');

        // Make sure the sortable totals exist so list-table sorting includes every document.
        foreach (['_spr_downloads', '_spr_likes'] as $key) {
            if (get_post_meta($postId, $key, true) === '') {
                update_post_meta($postId, $key, 0);
            }
        }
        unset($post);
    }

    private static function put(int $postId, string $key, string $value): void
    {
        if ($value === '') {
            delete_post_meta($postId, $key);
        } else {
            update_post_meta($postId, $key, $value);
        }
    }
}
