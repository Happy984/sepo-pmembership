<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Working with Sepo WordPress Bridge.
 *
 * The Bridge gates posts, menus and blocks by tier. This plugin tells it who
 * holds which tier through its own filter, so a gate there opens only for a
 * member whose plan here is active and paid. It also writes the member's tier
 * onto their account, so the Bridge's profile field and CSS classes show it,
 * and points the Bridge's join and account links at this plugin's pages.
 *
 * A tier given by hand in the Bridge, to someone with no membership here, is
 * left alone.
 */
final class Bridge
{
    public const SYNCED_META = '_smem_synced_tier';

    public static function hook(): void
    {
        add_filter('sepo_wb_user_has_tier', [self::class, 'hasTier'], 10, 4);
        add_filter('sepo_wb_join_url', static function ($url) {
            $own = Settings::pageUrl('plans_page');
            return $own !== '' ? $own : $url;
        });
        add_filter('sepo_wb_account_url', static function ($url) {
            $own = Settings::pageUrl('account_page');
            return $own !== '' ? $own : $url;
        });
    }

    /**
     * The Bridge asks whether a user holds a tier. A member of this plugin is
     * answered from their membership; anyone else keeps the Bridge's answer.
     *
     * @param mixed $ok
     */
    public static function hasTier($ok, $uid, $tier, $held = ''): bool
    {
        $uid = (int) $uid;
        if ($uid < 1 || Members::get($uid) === null) {
            return (bool) $ok;
        }
        if (Tiers::get((string) $tier) === null) {
            return (bool) $ok;
        }
        return Members::hasLevel($uid, (string) $tier) || ($held !== '' && $held !== (string) get_user_meta($uid, self::SYNCED_META, true) && (bool) $ok);
    }

    /** Keep the Bridge's tier on the user's account in step with their membership. */
    public static function sync(int $uid): void
    {
        if ($uid < 1 || !class_exists('\\SepoWB\\TierStore')) {
            return;
        }
        $store = new \SepoWB\TierStore();
        $level = Members::level($uid);
        $synced = (string) get_user_meta($uid, self::SYNCED_META, true);
        $held = $store->userTier($uid);
        if ($level !== '') {
            if ($held !== $level) {
                $store->setUserTier($uid, $level);
            }
            update_user_meta($uid, self::SYNCED_META, $level);
            return;
        }
        // Only clear a tier this plugin put there.
        if ($synced !== '' && $held === $synced) {
            $store->setUserTier($uid, '');
        }
        delete_user_meta($uid, self::SYNCED_META);
    }
}
