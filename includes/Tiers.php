<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Membership tiers and their plans.
 *
 * The tier list (id, name, rank) comes from Sepo WordPress Bridge when it is
 * active and has tiers, so a plan here and a gate there mean the same thing.
 * Without the Bridge the plugin keeps its own list. Either way this plugin
 * keeps the plan details per tier: prices per period, benefits, a short
 * description and whether to highlight it.
 */
final class Tiers
{
    public const OWN_OPTION = 'sepo_membership_tiers';
    public const PLANS_OPTION = 'sepo_membership_plans';
    public const PERIODS = ['monthly', 'quarterly', 'annual', 'one_time'];

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $cache = null;

    /** Whether the tier list comes from Sepo WordPress Bridge. */
    public static function fromBridge(): bool
    {
        return class_exists('\\SepoWB\\TierStore') && (new \SepoWB\TierStore())->tiers() !== [];
    }

    public static function bridgeActive(): bool
    {
        return class_exists('\\SepoWB\\TierStore');
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    /**
     * Every tier with its plan, lowest rank first.
     *
     * @return array<string,array{id:string,label:string,rank:int,prices:array<string,float>,benefits:list<string>,blurb:string,featured:bool,hidden:bool}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $base = [];
        if (self::fromBridge()) {
            foreach ((new \SepoWB\TierStore())->tiers() as $t) {
                $base[] = ['id' => (string) $t['id'], 'label' => (string) $t['label'], 'rank' => (int) $t['rank']];
            }
        } else {
            foreach ((array) get_option(self::OWN_OPTION, []) as $t) {
                if (is_array($t) && sanitize_key((string) ($t['id'] ?? '')) !== '') {
                    $base[] = ['id' => sanitize_key((string) $t['id']), 'label' => (string) ($t['label'] ?? ''), 'rank' => (int) ($t['rank'] ?? 0)];
                }
            }
        }
        $plans = (array) get_option(self::PLANS_OPTION, []);
        $out = [];
        foreach ($base as $t) {
            $p = is_array($plans[$t['id']] ?? null) ? $plans[$t['id']] : [];
            $prices = [];
            foreach (self::PERIODS as $period) {
                $v = $p['prices'][$period] ?? '';
                if ($v !== '' && $v !== null && is_numeric($v) && (float) $v >= 0) {
                    $prices[$period] = round((float) $v, 2);
                }
            }
            $out[$t['id']] = [
                'id'       => $t['id'],
                'label'    => $t['label'] !== '' ? $t['label'] : ucfirst($t['id']),
                'rank'     => $t['rank'],
                'prices'   => $prices,
                'benefits' => array_values(array_filter(array_map('trim', (array) ($p['benefits'] ?? [])))),
                'blurb'    => (string) ($p['blurb'] ?? ''),
                'featured' => !empty($p['featured']),
                'hidden'   => !empty($p['hidden']),
            ];
        }
        uasort($out, static fn(array $a, array $b): int => $a['rank'] <=> $b['rank']);
        return self::$cache = (array) apply_filters('sepo_membership_tiers', $out);
    }

    /** Tiers people can join: those with at least one price and not hidden. @return array<string,array<string,mixed>> */
    public static function joinable(): array
    {
        return array_filter(self::all(), static fn(array $t): bool => $t['prices'] !== [] && !$t['hidden']);
    }

    /** @return array<string,mixed>|null */
    public static function get(string $id): ?array
    {
        return self::all()[sanitize_key($id)] ?? null;
    }

    public static function rank(string $id): int
    {
        return (int) (self::get($id)['rank'] ?? 0);
    }

    public static function label(string $id): string
    {
        if ($id === '') {
            return __('All members', 'sepo-membership');
        }
        return (string) (self::get($id)['label'] ?? ucfirst($id));
    }

    /** The price of a tier for a period, or null when it is not offered. */
    public static function price(string $id, string $period): ?float
    {
        $t = self::get($id);
        return $t !== null && array_key_exists($period, $t['prices']) ? (float) $t['prices'][$period] : null;
    }

    public static function periodLabel(string $period): string
    {
        return match ($period) {
            'monthly'   => __('Monthly', 'sepo-membership'),
            'quarterly' => __('Every 3 months', 'sepo-membership'),
            'annual'    => __('Yearly', 'sepo-membership'),
            'one_time'  => __('One payment, no renewal', 'sepo-membership'),
            default     => $period,
        };
    }

    /** "/ month" and so on, after a price. */
    public static function per(string $period): string
    {
        return match ($period) {
            'monthly'   => __('/ month', 'sepo-membership'),
            'quarterly' => __('/ 3 months', 'sepo-membership'),
            'annual'    => __('/ year', 'sepo-membership'),
            default     => __('once', 'sepo-membership'),
        };
    }

    /** When a period paid from $from runs out, or null for a one-time plan that never does. */
    public static function addPeriod(int $from, string $period): ?int
    {
        $dt = (new \DateTimeImmutable('@' . $from));
        return match ($period) {
            'monthly'   => $dt->modify('+1 month')->getTimestamp(),
            'quarterly' => $dt->modify('+3 months')->getTimestamp(),
            'annual'    => $dt->modify('+1 year')->getTimestamp(),
            'one_time'  => null,
            default     => $dt->modify('+1 month')->getTimestamp(),
        };
    }

    /** @param list<array{id:string,label:string,rank:int}> $tiers */
    public static function saveOwn(array $tiers): void
    {
        $clean = [];
        $seen = [];
        foreach ($tiers as $t) {
            $id = sanitize_key((string) ($t['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $clean[] = ['id' => $id, 'label' => sanitize_text_field((string) ($t['label'] ?? '')) ?: ucfirst($id), 'rank' => (int) ($t['rank'] ?? 0)];
        }
        update_option(self::OWN_OPTION, $clean);
        self::flush();
    }

    /** @param array<string,array<string,mixed>> $plans */
    public static function savePlans(array $plans): void
    {
        $clean = [];
        foreach ($plans as $id => $p) {
            $id = sanitize_key((string) $id);
            if ($id === '' || !is_array($p)) {
                continue;
            }
            $prices = [];
            foreach (self::PERIODS as $period) {
                $v = trim((string) ($p['prices'][$period] ?? ''));
                if ($v !== '' && is_numeric($v) && (float) $v >= 0) {
                    $prices[$period] = round((float) $v, 2);
                }
            }
            $benefits = is_array($p['benefits'] ?? null) ? $p['benefits'] : (preg_split('~\R~', (string) ($p['benefits'] ?? '')) ?: []);
            $clean[$id] = [
                'prices'   => $prices,
                'benefits' => array_values(array_filter(array_map(static fn($b): string => sanitize_text_field((string) $b), $benefits))),
                'blurb'    => sanitize_text_field((string) ($p['blurb'] ?? '')),
                'featured' => !empty($p['featured']),
                'hidden'   => !empty($p['hidden']),
            ];
        }
        update_option(self::PLANS_OPTION, $clean);
        self::flush();
    }

    /** First run without the Bridge: three ranked tiers to start from. */
    public static function seedDefaults(): void
    {
        if (self::fromBridge() || get_option(self::OWN_OPTION, null) !== null) {
            return;
        }
        self::saveOwn([
            ['id' => 'silver', 'label' => 'Silver', 'rank' => 1],
            ['id' => 'gold', 'label' => 'Gold', 'rank' => 2],
            ['id' => 'premium', 'label' => 'Premium', 'rank' => 3],
        ]);
        if (get_option(self::PLANS_OPTION, null) === null) {
            self::savePlans([
                'silver'  => ['prices' => ['monthly' => 50, 'annual' => 500], 'benefits' => "Supporter badge\nMember-only updates\nCommunity access"],
                'gold'    => ['prices' => ['monthly' => 150, 'annual' => 1500], 'benefits' => "Everything in Silver\nEarly access to new courses\nMember suggestions and voting", 'featured' => 1],
                'premium' => ['prices' => ['monthly' => 400, 'annual' => 4000], 'benefits' => "Everything in Gold\nMembers' discussion board\nAll premium downloads"],
            ]);
        }
    }
}
