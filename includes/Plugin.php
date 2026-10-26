<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Wires the plugin together and keeps the database in step with the code.
 */
final class Plugin
{
    public const VERSION_OPTION = 'sepo_membership_version';
    public const CRON = 'sepo_membership_daily';

    public static function activate(): void
    {
        Schema::install();
        Files::protect();
        PostTypes::register();
        Tiers::seedDefaults();
        Settings::ensurePages();
        flush_rewrite_rules();
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 600, 'daily', self::CRON);
        }
        update_option(self::VERSION_OPTION, SMEM_VERSION);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
        flush_rewrite_rules();
    }

    public static function boot(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain('sepo-membership', false, dirname(plugin_basename(SMEM_FILE)) . '/languages');
        }, 1);

        PostTypes::hook();
        Bridge::hook();
        Frontend::hook();
        Rest::hook();
        Resources::hook();
        Embeds::hook();
        Privacy::hook();
        add_action(self::CRON, [Members::class, 'daily']);

        if (is_admin()) {
            Admin\Menu::hook();
            Admin\MetaBoxes::hook();
        }

        add_action('init', static function (): void {
            if (get_option(self::VERSION_OPTION) !== SMEM_VERSION) {
                Schema::install();
                Files::protect();
                flush_rewrite_rules(false);
                update_option(self::VERSION_OPTION, SMEM_VERSION);
            }
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 600, 'daily', self::CRON);
            }
        }, 99);
    }

    /** The plugin version plus the file's change time, so browsers fetch an edited file at once. */
    public static function assetVersion(string $rel): string
    {
        $file = SMEM_DIR . $rel;
        return SMEM_VERSION . (is_file($file) ? '.' . filemtime($file) : '');
    }

    /** Who runs the membership: confirms payments, manages members, sponsors and resources. */
    public static function canManage(): bool
    {
        return current_user_can((string) apply_filters('sepo_membership_manage_capability', 'manage_options'));
    }
}
