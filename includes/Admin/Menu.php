<?php

declare(strict_types=1);

namespace SepoMembership\Admin;

use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Plugin;
use SepoMembership\Projects;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/**
 * The Membership menu in the dashboard: Overview, Members, Payments, Plans and
 * Settings, with Projects, Sponsors and Resources from their post types.
 *
 * Every change is a form posted to admin-post.php with its own nonce, checked
 * against the capability that runs the membership.
 */
final class Menu
{
    public const SLUG = 'sepo-membership';

    public static function hook(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_menu', [self::class, 'order'], 99);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_notices', [self::class, 'notices']);
        foreach (['confirm', 'reject', 'withdrawal', 'delete_payment', 'record', 'gift', 'set_plan', 'set_status', 'add_member', 'delete_member', 'plans', 'settings', 'export'] as $a) {
            add_action('admin_post_smem_' . $a, [Actions::class, $a]);
        }
        add_filter('plugin_action_links_' . plugin_basename(SMEM_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Settings', 'sepo-membership') . '</a>');
            return $links;
        });
    }

    public static function cap(): string
    {
        return (string) apply_filters('sepo_membership_manage_capability', 'manage_options');
    }

    public static function url(string $screen = '', array $args = []): string
    {
        $page = $screen === '' ? self::SLUG : self::SLUG . '-' . $screen;
        return add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php'));
    }

    public static function menu(): void
    {
        $cap = self::cap();
        $pending = Payments::query(['status' => Payments::OPEN, 'per_page' => 1])['total'];
        $badge = $pending > 0 ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
        add_menu_page(__('Membership', 'sepo-membership'), __('Membership', 'sepo-membership') . $badge, $cap, self::SLUG, [Screens::class, 'overview'], 'dashicons-groups', 56);
        add_submenu_page(self::SLUG, __('Overview', 'sepo-membership'), __('Overview', 'sepo-membership'), $cap, self::SLUG, [Screens::class, 'overview']);
        add_submenu_page(self::SLUG, __('Members', 'sepo-membership'), __('Members', 'sepo-membership'), $cap, self::SLUG . '-members', [Screens::class, 'members']);
        add_submenu_page(self::SLUG, __('Payments', 'sepo-membership'), __('Payments', 'sepo-membership') . $badge, $cap, self::SLUG . '-payments', [Screens::class, 'payments']);
        add_submenu_page(self::SLUG, __('Plans and prices', 'sepo-membership'), __('Plans', 'sepo-membership'), $cap, self::SLUG . '-plans', [Screens::class, 'plans']);
        add_submenu_page(self::SLUG, __('Activity log', 'sepo-membership'), __('Activity log', 'sepo-membership'), $cap, self::SLUG . '-activity', [Screens::class, 'activity']);
        add_submenu_page(self::SLUG, __('Membership settings', 'sepo-membership'), __('Settings', 'sepo-membership'), $cap, self::SLUG . '-settings', [Screens::class, 'settings']);
    }

    /** Overview, Members, Payments and Plans first; the content types; Settings last. */
    public static function order(): void
    {
        global $submenu;
        if (empty($submenu[self::SLUG])) {
            return;
        }
        $weight = static function (array $item): int {
            $slug = (string) ($item[2] ?? '');
            $map = [self::SLUG => 0, self::SLUG . '-members' => 1, self::SLUG . '-payments' => 2, self::SLUG . '-plans' => 3, self::SLUG . '-activity' => 15, self::SLUG . '-settings' => 20];
            return $map[$slug] ?? 10;
        };
        usort($submenu[self::SLUG], static fn(array $a, array $b): int => $weight($a) <=> $weight($b));
    }

    public static function assets(string $hook): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $mine = str_contains($hook, self::SLUG) || ($screen && in_array($screen->post_type, ['smem_project', 'smem_sponsor', 'smem_resource'], true));
        if (!$mine) {
            return;
        }
        wp_enqueue_style('sepo-membership-admin', SMEM_URL . 'assets/css/admin.css', [], Plugin::assetVersion('assets/css/admin.css'));
        wp_enqueue_script('sepo-membership-admin', SMEM_URL . 'assets/js/admin.js', [], Plugin::assetVersion('assets/js/admin.js'), true);
        wp_add_inline_script('sepo-membership-admin', 'window.SepoMembershipAdmin=' . wp_json_encode([
            'remove'       => __('Remove', 'sepo-membership'),
            'confirmDelete' => __('Delete this for good? This cannot be undone.', 'sepo-membership'),
            'chooseFile'   => __('Choose file', 'sepo-membership'),
            'pickTitle'    => __('Choose a file for this resource', 'sepo-membership'),
            'useFile'      => __('Use this file', 'sepo-membership'),
            'noFile'       => __('No file chosen.', 'sepo-membership'),
            'confirmRemoveFile' => __('Remove this file now? Members will no longer be able to download it.', 'sepo-membership'),
            'removeFailed' => __('The file could not be removed. Reload the page and try again.', 'sepo-membership'),
        ]) . ';', 'before');
    }

    /** One-off messages after an action, carried in the address. */
    public static function notices(): void
    {
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        if (!str_starts_with($page, self::SLUG)) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- display only
        $done = isset($_GET['smem_done']) ? sanitize_key((string) $_GET['smem_done']) : '';
        $err = isset($_GET['smem_error']) ? sanitize_text_field(wp_unslash((string) $_GET['smem_error'])) : '';
        // phpcs:enable
        $messages = [
            'confirmed' => __('Payment confirmed. The payer has been emailed.', 'sepo-membership'),
            'rejected'  => __('Payment marked as not received. The payer has been emailed.', 'sepo-membership'),
            'deleted'   => __('Deleted.', 'sepo-membership'),
            'recorded'  => __('Payment recorded and confirmed.', 'sepo-membership'),
            'plan'      => __('Plan updated.', 'sepo-membership'),
            'status'    => __('Status updated.', 'sepo-membership'),
            'added'     => __('Member added.', 'sepo-membership'),
            'saved'     => __('Settings saved.', 'sepo-membership'),
            'withdrawn' => __('Withdrawal approved. The payment is kept, marked withdrawn, and the payer has been emailed.', 'sepo-membership'),
            'kept'      => __('Withdrawal declined. The payment is waiting again and the payer has been emailed.', 'sepo-membership'),
        ];
        if ($done !== '' && isset($messages[$done])) {
            echo '<div class="notice notice-success is-dismissible smem-notice-tint"><p>' . esc_html($messages[$done]) . '</p></div>';
        }
        if ($err !== '') {
            echo '<div class="notice notice-error is-dismissible smem-notice-tint smem-notice-tint--bad"><p>' . esc_html($err) . '</p></div>';
        }
        if ($page === self::SLUG && Tiers::all() !== [] && Tiers::joinable() === []) {
            echo '<div class="notice notice-warning smem-notice-tint smem-notice-tint--warn"><p>' . esc_html__('No plan has a price yet, so the pricing table is closed. Set prices to open it.', 'sepo-membership') . ' <a href="' . esc_url(self::url('plans')) . '">' . esc_html__('Plans', 'sepo-membership') . '</a></p></div>';
        }
        if ($page === self::SLUG && Settings::methods() === []) {
            echo '<div class="notice notice-warning smem-notice-tint smem-notice-tint--warn"><p>' . esc_html__('Add at least one payment method so people can join and give.', 'sepo-membership') . ' <a href="' . esc_url(self::url('settings')) . '">' . esc_html__('Settings', 'sepo-membership') . '</a></p></div>';
        }
    }

    /** Small numbers for the overview. @return array<string,mixed> */
    public static function figures(): array
    {
        global $wpdb;
        $since = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
        $table = \SepoMembership\Schema::payments();
        $month = $wpdb->get_row($wpdb->prepare("SELECT SUM(CASE WHEN kind = 'membership' THEN amount ELSE 0 END) AS m, SUM(CASE WHEN kind <> 'membership' THEN amount ELSE 0 END) AS g FROM $table WHERE status = 'paid' AND confirmed_at >= %s", $since), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL
        return [
            'members' => Members::stats(),
            'byLevel' => Members::countByLevel(),
            'pending' => Payments::query(['status' => ['pending'], 'per_page' => 1]),
            'withdrawals' => Payments::query(['status' => ['withdrawal'], 'per_page' => 1])['total'],
            'month'   => ['membership' => (float) ($month['m'] ?? 0), 'gifts' => (float) ($month['g'] ?? 0)],
            'raised'  => Payments::raised(0),
            'projects' => Projects::all([], false),
            'tiers'   => Tiers::all(),
        ];
    }
}
