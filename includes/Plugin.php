<?php

declare(strict_types=1);

namespace SepoResources;

/**
 * Wires every part of the plugin into WordPress.
 */
final class Plugin
{
    public static function boot(): void
    {
        load_plugin_textdomain('sepo-resources', false, dirname(plugin_basename(SPR_FILE)) . '/languages');

        Activator::maybeUpgrade();

        PostType::init();
        Uploads::init();
        Download::init();
        Rest::init();
        Frontend::init();
        Activity::init();
        Privacy::init();

        if (is_admin()) {
            Admin\MetaBoxes::init();
            Admin\Columns::init();
            Admin\SettingsPage::init();
            Admin\ActivityPage::init();
        }

        // Rewrite rules are flushed once, after the library address changes.
        add_action('init', static function (): void {
            if (get_option('sepo_resources_flush')) {
                delete_option('sepo_resources_flush');
                flush_rewrite_rules(false);
            }
        }, 99);
    }
}
