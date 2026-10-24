<?php
/**
 * Plugin Name:       Sepo Resources
 * Plugin URI:        https://sepodesk.com/
 * Description:       A document library for PDFs, Word files, spreadsheets and slides — search, groups, pagination, likes, download counts, gated downloads and PDF preview. Runs on WordPress alone.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Euneolink Solutions
 * Author URI:        https://sepodesk.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sepo-resources
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Sepo Resources needs PHP 8.1 or newer. The plugin is installed but not running.', 'sepo-resources')
            . '</p></div>';
    });
    return;
}

define('SPR_VERSION', '1.1.0');
define('SPR_FILE', __FILE__);
define('SPR_DIR', plugin_dir_path(__FILE__));
define('SPR_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SepoResources\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = SPR_DIR . 'includes/' . $rel . '.php';
    if (is_file($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [\SepoResources\Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [\SepoResources\Activator::class, 'deactivate']);

add_action('plugins_loaded', [\SepoResources\Plugin::class, 'boot']);
