<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * One row per member: their plan, standing and money.
 *
 * Standing, as members and gates see it:
 *   none       never joined
 *   pending    joined, nothing confirmed yet (or a lapsed member's new
 *              payment is waiting to be confirmed). No access.
 *   active     confirmed and in date. A member who cancelled keeps access
 *              until the end of the time they paid for.
 *   expired    the paid time has run out. No access.
 *   cancelled  ended, with no paid time left. No access.
 *
 * Only a confirmed payment turns into access: it activates the plan, extends
 * the expiry by its period and lands any scheduled change of plan. A payment
 * waiting for confirmation unlocks nothing, and never takes access away from
 * someone who is already active.
 *
 * Upgrades take effect when the upgrade is paid for. Downgrades are scheduled:
 * the member keeps their plan until it runs out, and the lower plan starts
 * with their next confirmed payment.
 */
final class Members
{
    /** @var array<int,array<string,mixed>|null> */
    private static array $memo = [];

    public static function forget(int $uid): void
    {
        unset(self::$memo[$uid]);
    }

    /** @return array<string,mixed>|null */
    public static function get(int $uid): ?array
    {
        if ($uid < 1) {
            return null;
        }
        if (array_key_exists($uid, self::$memo)) {
            return self::$memo[$uid];
        }
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::members() . ' WHERE user_id = %d', $uid), ARRAY_A);
        return self::$memo[$uid] = is_array($r) ? self::row($r) : null;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function row(array $r): array
    {
        $r['user_id'] = (int) $r['user_id'];
        $r['total'] = (float) $r['total'];
        $r['pending_total'] = (float) $r['pending_total'];
        $r['listed'] = (int) $r['listed'] === 1;
        $r['since_ts'] = Schema::ts($r['since']);
        $r['started_ts'] = Schema::ts($r['started']);
        $r['expires_ts'] = Schema::ts($r['expires']);
        return $r;
    }

    public static function status(int $uid): string
    {
        $m = self::get($uid);
        if ($m === null) {
            return 'none';
        }
        $stored = (string) $m['status'];
        $left = $m['expires_ts'] === 0 || $m['expires_ts'] > time();
        if ($stored === 'pending') {
            return 'pending';
        }
        if ($stored === 'cancelled') {
            return $m['expires_ts'] > time() ? 'active' : 'cancelled';
        }
        if ($stored === 'active' && $m['started_ts'] === 0 && $m['expires_ts'] === 0) {
            return 'pending';
        }
        return $left ? 'active' : 'expired';
    }

    /** Active, but cancelled: access ends when the paid time does. */
    public static function isEnding(int $uid): bool
    {
        $m = self::get($uid);
        return $m !== null && $m['status'] === 'cancelled' && $m['expires_ts'] > time();
    }

    /** The tier this member may use right now, or '' when they have no active plan. */
    public static function level(int $uid): string
    {
        $m = self::get($uid);
        return $m !== null && self::status($uid) === 'active' ? (string) $m['level'] : '';
    }

    /** Does the member hold an active plan at $tier or above? An empty $tier means any active plan. */
    public static function hasLevel(int $uid, string $tier): bool
    {
        $level = self::level($uid);
        if ($level === '') {
            return false;
        }
        if ($tier === '') {
            return true;
        }
        if (Tiers::get($tier) === null) {
            return false;
        }
        return $level === $tier || Tiers::rank($level) >= Tiers::rank($tier);
    }

    /** @param array<string,mixed> $fields */
    private static function write(int $uid, array $fields): void
    {
        global $wpdb;
        $fields['updated'] = Schema::now();
        if (self::get($uid) === null) {
            $wpdb->insert(Schema::members(), $fields + ['user_id' => $uid, 'since' => Schema::now(), 'currency' => Settings::currency()]);
        } else {
            $wpdb->update(Schema::members(), $fields, ['user_id' => $uid]);
        }
        self::forget($uid);
        Bridge::sync($uid);
        do_action('sepo_membership_member_changed', $uid);
    }

    /**
     * What choosing a plan means for this member right now.
     *
     *   payment    a payment is needed (new member, renewal, or upgrade)
     *   scheduled  a downgrade, recorded to start with the next payment
     *   unchanged  the member asked for the plan they already have and are not renewing
     *   free       a free plan, active at once
     *
     * @return array{effect:string,amount:float}
     */
    public static function request(int $uid, string $level, string $period, bool $renew = false, bool $dry = false): array
    {
        $tier = Tiers::get($level);
        $price = Tiers::price($level, $period);
        if ($tier === null || $price === null) {
            throw new \InvalidArgumentException(__('That plan is not available.', 'sepo-membership'));
        }
        $status = self::status($uid);
        $m = self::get($uid);
        $current = $status === 'active' ? (string) $m['level'] : '';

        if ($current !== '' && !$renew) {
            if ($level === $current) {
                if (!$dry && (string) $m['pending_level'] !== '') {
                    self::write($uid, ['pending_level' => '']);
                }
                return ['effect' => 'unchanged', 'amount' => 0.0];
            }
            if (Tiers::rank($level) < Tiers::rank($current)) {
                if (!$dry) {
                    self::write($uid, ['pending_level' => $level]);
                }
                return ['effect' => 'scheduled', 'amount' => 0.0];
            }
        }

        if ($dry) {
            return ['effect' => $price <= 0.0 ? 'free' : 'payment', 'amount' => $price];
        }
        if ($price <= 0.0) {
            self::write($uid, [
                'level'         => $level,
                'pending_level' => '',
                'period'        => $period,
                'status'        => 'active',
                'started'       => $m !== null && $m['started_ts'] > 0 ? (string) $m['started'] : Schema::now(),
                'expires'       => null,
            ]);
            return ['effect' => 'free', 'amount' => 0.0];
        }

        if ($m === null) {
            self::write($uid, ['level' => $level, 'period' => $period, 'status' => 'pending']);
        } elseif ($status !== 'active') {
            // A lapsed or never-paid member choosing a plan: the record follows the choice and stays locked
            // until the payment is confirmed. Its standing is left as it is, so withdrawing the payment
            // leaves the member where they were.
            self::write($uid, ['level' => $level, 'period' => $period, 'pending_level' => '']);
        }
        return ['effect' => 'payment', 'amount' => $price];
    }

    /** A confirmed membership payment: activate, extend, land the paid-for plan. */
    public static function applyPayment(array $p): void
    {
        $uid = (int) $p['user_id'];
        if ($uid < 1) {
            return;
        }
        $m = self::get($uid);
        $level = (string) $p['level'] !== '' ? (string) $p['level'] : (string) ($m['level'] ?? '');
        $period = (string) $p['period'] !== '' ? (string) $p['period'] : (string) ($m['period'] ?? 'monthly');
        $base = max(time(), (int) ($m['expires_ts'] ?? 0));
        $expires = Tiers::addPeriod($base, $period);
        self::write($uid, [
            'level'         => $level,
            'pending_level' => '',
            'period'        => $period,
            'status'        => 'active',
            'started'       => $m !== null && $m['started_ts'] > 0 ? (string) $m['started'] : Schema::now(),
            'expires'       => $expires === null ? null : Schema::utc($expires),
            'method'        => (string) $p['method'],
            'currency'      => (string) $p['currency'],
            'reminded'      => null,
        ]);
        self::retotal($uid);
    }

    /** Recount confirmed and waiting money from the payments. */
    public static function retotal(int $uid): void
    {
        global $wpdb;
        if (self::get($uid) === null) {
            return;
        }
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT SUM(CASE WHEN status = %s THEN amount ELSE 0 END) AS paid, SUM(CASE WHEN status IN (%s, %s) THEN amount ELSE 0 END) AS waiting FROM ' . Schema::payments() . ' WHERE user_id = %d AND kind = %s',
            'paid',
            'pending',
            'withdrawal',
            $uid,
            'membership'
        ), ARRAY_A);
        $wpdb->update(Schema::members(), ['total' => (float) ($r['paid'] ?? 0), 'pending_total' => (float) ($r['waiting'] ?? 0)], ['user_id' => $uid]);
        self::forget($uid);
    }

    /** The member ends their plan; access lasts until the paid time runs out. */
    public static function cancel(int $uid): void
    {
        $m = self::get($uid);
        if ($m === null) {
            return;
        }
        self::write($uid, ['status' => 'cancelled', 'pending_level' => '']);
        do_action('sepo_membership_cancelled', $uid);
    }

    /**
     * Someone who joined but never paid, and whose payment has been withdrawn,
     * goes back to having no membership. Members with any confirmed or open
     * payment are left as they are.
     */
    public static function dropIfUnpaid(int $uid): void
    {
        global $wpdb;
        if (self::get($uid) === null || self::status($uid) !== 'pending') {
            return;
        }
        $left = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::payments() . " WHERE user_id = %d AND kind = 'membership' AND status IN ('paid','pending','withdrawal')", $uid));
        if ($left === 0) {
            $wpdb->delete(Schema::members(), ['user_id' => $uid]);
            self::forget($uid);
            Bridge::sync($uid);
        }
    }

    /** Undo a cancellation while the paid time is still running. */
    public static function resume(int $uid): void
    {
        if (self::isEnding($uid)) {
            self::write($uid, ['status' => 'active']);
        }
    }

    public static function setListed(int $uid, bool $listed): void
    {
        if (self::get($uid) !== null) {
            self::write($uid, ['listed' => $listed ? 1 : 0]);
        }
    }

    /**
     * Put a member on a plan by hand: no payment and no upgrade rules.
     *
     * @param int|null $expires timestamp, 0 for no expiry, null to keep the current one
     */
    public static function adminSetPlan(int $uid, string $level, string $period, ?int $expires = null): void
    {
        if (Tiers::get($level) === null) {
            throw new \InvalidArgumentException(__('Unknown plan.', 'sepo-membership'));
        }
        $fields = ['level' => $level, 'pending_level' => '', 'period' => in_array($period, Tiers::PERIODS, true) ? $period : 'monthly'];
        if ($expires !== null) {
            $fields['expires'] = $expires > 0 ? Schema::utc($expires) : null;
        }
        self::write($uid, $fields);
    }

    /**
     * Set a member's standing by hand.
     *
     *   active     access on; a past expiry moves one period from today
     *   pending    access off until a payment is confirmed
     *   expired    access off now, record kept
     *   cancelled  access off now
     */
    public static function adminSetStatus(int $uid, string $status, ?int $expires = null): void
    {
        $m = self::get($uid);
        if ($m === null) {
            throw new \InvalidArgumentException(__('That member has no membership.', 'sepo-membership'));
        }
        $now = time();
        switch ($status) {
            case 'active':
                $fields = ['status' => 'active'];
                if ($expires !== null) {
                    $fields['expires'] = $expires > 0 ? Schema::utc($expires) : null;
                } elseif ($m['expires_ts'] > 0 && $m['expires_ts'] < $now) {
                    $next = Tiers::addPeriod($now, (string) $m['period']);
                    $fields['expires'] = $next === null ? null : Schema::utc($next);
                } elseif ($m['expires_ts'] === 0 && $m['started_ts'] === 0) {
                    $next = Tiers::addPeriod($now, (string) $m['period']);
                    $fields['expires'] = $next === null ? null : Schema::utc($next);
                }
                if ($m['started_ts'] === 0) {
                    $fields['started'] = Schema::now();
                }
                break;
            case 'pending':
                $fields = ['status' => 'pending'];
                break;
            case 'expired':
                $fields = ['status' => 'active', 'expires' => Schema::utc($now - 1)];
                break;
            case 'cancelled':
                $fields = ['status' => 'cancelled', 'expires' => Schema::utc($now - 1), 'pending_level' => ''];
                break;
            default:
                throw new \InvalidArgumentException(__('Unknown status.', 'sepo-membership'));
        }
        self::write($uid, $fields);
    }

    /** Remove a member's record and their membership payments. */
    public static function delete(int $uid): void
    {
        global $wpdb;
        foreach (Payments::query(['user_id' => $uid, 'kind' => 'membership', 'per_page' => 500])['rows'] as $p) {
            Payments::delete((int) $p['id']);
        }
        $wpdb->delete(Schema::members(), ['user_id' => $uid]);
        self::forget($uid);
        Bridge::sync($uid);
    }

    /**
     * @param array{status?:string,level?:string,q?:string,page?:int,per_page?:int} $a
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $a = []): array
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        $now = Schema::now();
        switch ((string) ($a['status'] ?? '')) {
            case 'active':
                $where[] = "(m.status IN ('active','cancelled') AND (m.expires IS NULL OR m.expires > %s) AND NOT (m.status = 'cancelled' AND m.expires IS NULL) AND NOT (m.status = 'active' AND m.started IS NULL AND m.expires IS NULL))";
                $params[] = $now;
                break;
            case 'pending':
                $where[] = "(m.status = 'pending' OR (m.status = 'active' AND m.started IS NULL AND m.expires IS NULL))";
                break;
            case 'expired':
                $where[] = "(m.status = 'active' AND m.expires IS NOT NULL AND m.expires <= %s)";
                $params[] = $now;
                break;
            case 'cancelled':
                $where[] = "(m.status = 'cancelled' AND (m.expires IS NULL OR m.expires <= %s))";
                $params[] = $now;
                break;
            case 'awaiting':
                $where[] = 'm.pending_total > 0';
                break;
        }
        if (!empty($a['level'])) {
            $where[] = 'm.level = %s';
            $params[] = sanitize_key((string) $a['level']);
        }
        if (!empty($a['q'])) {
            $like = '%' . $wpdb->esc_like((string) $a['q']) . '%';
            $where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR u.user_login LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        $per = max(1, min(200, (int) ($a['per_page'] ?? 25)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $sqlWhere = implode(' AND ', $where);
        $from = Schema::members() . " m LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id";
        // phpcs:disable WordPress.DB.PreparedSQL -- clauses use placeholders
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $from WHERE $sqlWhere", ...$params)) : $wpdb->get_var("SELECT COUNT(*) FROM $from WHERE $sqlWhere"));
        $rows = $wpdb->get_results($wpdb->prepare("SELECT m.*, u.display_name, u.user_email FROM $from WHERE $sqlWhere ORDER BY m.updated DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per, ($page - 1) * $per])), ARRAY_A);
        // phpcs:enable
        return ['rows' => array_map([self::class, 'row'], $rows ?: []), 'total' => $total];
    }

    /** @return array<string,int|float> */
    public static function stats(): array
    {
        $out = ['total' => 0, 'active' => 0, 'pending' => 0, 'expired' => 0, 'cancelled' => 0];
        foreach (['active', 'pending', 'expired', 'cancelled'] as $s) {
            $out[$s] = self::query(['status' => $s, 'per_page' => 1])['total'];
        }
        $out['total'] = self::query(['per_page' => 1])['total'];
        return $out;
    }

    /** @return array<string,int> level => active members */
    public static function countByLevel(): array
    {
        $out = [];
        foreach (self::query(['status' => 'active', 'per_page' => 200])['rows'] as $m) {
            $out[$m['level']] = ($out[$m['level']] ?? 0) + 1;
        }
        return $out;
    }

    /** Members who chose to appear on the sponsors page and are in good standing. @return list<array<string,mixed>> */
    public static function listed(): array
    {
        $out = [];
        foreach (self::query(['status' => 'active', 'per_page' => 200])['rows'] as $m) {
            if ($m['listed']) {
                $out[] = $m;
            }
        }
        return $out;
    }

    /**
     * Once a day: remind members whose plan runs out soon, tell those whose
     * plan has just run out, and keep the Bridge's tiers in step.
     */
    public static function daily(): void
    {
        global $wpdb;
        $days = max(0, (int) Settings::get('remind_days'));
        $now = time();
        if ($days > 0) {
            $soon = $wpdb->get_col($wpdb->prepare(
                'SELECT user_id FROM ' . Schema::members() . " WHERE status = 'active' AND expires IS NOT NULL AND expires > %s AND expires <= %s AND reminded IS NULL",
                Schema::utc($now),
                Schema::utc($now + $days * DAY_IN_SECONDS)
            ));
            foreach ($soon as $uid) {
                Mail::expiringSoon((int) $uid);
                $wpdb->update(Schema::members(), ['reminded' => Schema::now()], ['user_id' => (int) $uid]);
            }
        }
        $lapsed = $wpdb->get_col($wpdb->prepare(
            'SELECT user_id FROM ' . Schema::members() . " WHERE status IN ('active','cancelled') AND expires IS NOT NULL AND expires <= %s AND expires > %s",
            Schema::utc($now),
            Schema::utc($now - 2 * DAY_IN_SECONDS)
        ));
        foreach ($lapsed as $uid) {
            $uid = (int) $uid;
            $m = self::get($uid);
            if ($m !== null && (string) get_user_meta($uid, '_smem_lapse_notice', true) !== (string) $m['expires']) {
                update_user_meta($uid, '_smem_lapse_notice', (string) $m['expires']);
                if ($m['status'] === 'active') {
                    Mail::expired($uid);
                }
            }
            Bridge::sync($uid);
        }
        Payments::remindRecurring();
    }
}
