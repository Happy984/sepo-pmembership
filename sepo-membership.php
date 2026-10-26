<?php

/**
 * Plugin Name:       Sepo Membership
 * Description:       Paid membership plans with pricing tables, manual and link payments confirmed by you, donations with goals, a sponsors page, and a members area with tier-gated downloads, links, discussions and suggestions. Works with Sepo WordPress Bridge tiers. Runs on WordPress alone.
 * Version:           1.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Euneolink Solutions
 * Author URI:        https://sepodesk.com
 * Plugin URI:        https://edu.sepodesk.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sepo-membership
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Sepo Membership needs PHP 8.1 or newer. The plugin is installed but not running.', 'sepo-membership')
            . '</p></div>';
    });
    return;
}

define('SMEM_VERSION', '1.2.1');
define('SMEM_FILE', __FILE__);
define('SMEM_DIR', plugin_dir_path(__FILE__));
define('SMEM_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SepoMembership\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = SMEM_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['SepoMembership\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SepoMembership\\Plugin', 'deactivate']);

add_action('plugins_loaded', ['SepoMembership\\Plugin', 'boot'], 20);
