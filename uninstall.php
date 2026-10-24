<?php
/**
 * Runs when Sepo Resources is deleted from the Plugins screen.
 *
 * Documents and their files are never removed. Likes, download counts, the activity log and
 * settings are removed only if "Delete likes, download counts and settings"
 * was ticked in Resources → Settings.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('sepo_resources_settings', []);

wp_clear_scheduled_hook('spr_activity_purge');
wp_clear_scheduled_hook('spr_resolve_countries');

foreach (['administrator', 'editor'] as $roleName) {
    $role = get_role($roleName);
    if ($role) {
        $role->remove_cap('manage_sepo_resources');
    }
}

if (is_array($settings) && !empty($settings['delete_on_uninstall'])) {
    global $wpdb;
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sepo_resource_events'); // phpcs:ignore WordPress.DB
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sepo_resource_log'); // phpcs:ignore WordPress.DB
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_spr\\_geo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_spr\\_geo\\_%'"); // phpcs:ignore WordPress.DB
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_spr_downloads','_spr_likes')"); // phpcs:ignore WordPress.DB
    delete_option('sepo_resources_settings');
    delete_option('sepo_resources_db');
    delete_option('sepo_resources_flush');
}
