<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * The activity log: who did what to a payment or a membership, and when.
 *
 * Every step a payment goes through is written here: sent, proof added,
 * confirmed, marked not received, withdrawal asked for, approved or declined,
 * recorded by hand, deleted. Changes to a membership made from the dashboard
 * are written too. Entries are only added, never edited, so the record stays
 * whole even when a payment is deleted: each entry carries a short summary of
 * the payment it was about.
 */
final class Log
{
    /**
     * @param array<string,mixed>|null $payment the payment the entry is about, for its summary
     */
    public static function add(string $action, ?array $payment, string $note = '', int $userId = 0): void
    {
        global $wpdb;
        $wpdb->insert(Schema::log(), [
            'payment_id' => (int) ($payment['id'] ?? 0),
            'user_id'    => $userId > 0 ? $userId : (int) ($payment['user_id'] ?? 0),
            'actor_id'   => get_current_user_id(),
            'action'     => sanitize_key($action),
            'note'       => mb_substr(sanitize_textarea_field($note), 0, 1000),
            'summary'    => $payment !== null ? mb_substr(self::summary($payment), 0, 255) : '',
            'created'    => Schema::now(),
        ]);
        do_action('sepo_membership_logged', $action, $payment, $note);
    }

    /** "ZMW 50 · Silver membership · Alice", so the entry still reads after the payment is gone. */
    public static function summary(array $p): string
    {
        $what = (string) ($p['kind'] ?? '') === 'membership'
            ? Tiers::label((string) $p['level']) . ' ' . __('membership', 'sepo-membership')
            : ((int) ($p['project_id'] ?? 0) > 0 ? get_the_title((int) $p['project_id']) : Payments::kindLabel((string) ($p['kind'] ?? 'donation')));
        return Settings::money((float) ($p['amount'] ?? 0), (string) ($p['currency'] ?? '')) . ' · ' . $what . ((string) ($p['name'] ?? '') !== '' ? ' · ' . $p['name'] : '');
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function row(array $r): array
    {
        foreach (['id', 'payment_id', 'user_id', 'actor_id'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['created_ts'] = Schema::ts($r['created']);
        $actor = $r['actor_id'] > 0 ? get_userdata($r['actor_id']) : null;
        $r['actor'] = $actor ? (string) $actor->display_name : ($r['actor_id'] > 0 ? __('Removed account', 'sepo-membership') : __('Site', 'sepo-membership'));
        $r['label'] = self::label((string) $r['action']);
        return $r;
    }

    /** @return list<array<string,mixed>> oldest first */
    public static function forPayment(int $paymentId): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::log() . ' WHERE payment_id = %d ORDER BY id ASC', $paymentId), ARRAY_A);
        return array_map([self::class, 'row'], $rows ?: []);
    }

    /**
     * @param array{payment_id?:int,user_id?:int,action?:string,page?:int,per_page?:int} $a
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $a = []): array
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        foreach (['payment_id', 'user_id'] as $k) {
            if (!empty($a[$k])) {
                $where[] = "$k = %d";
                $params[] = (int) $a[$k];
            }
        }
        if (!empty($a['action'])) {
            $where[] = 'action = %s';
            $params[] = sanitize_key((string) $a['action']);
        }
        $per = max(1, min(500, (int) ($a['per_page'] ?? 50)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $sqlWhere = implode(' AND ', $where);
        // phpcs:disable WordPress.DB.PreparedSQL -- clauses use placeholders
        $total = (int) ($params ? $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::log() . " WHERE $sqlWhere", ...$params)) : $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::log() . " WHERE $sqlWhere"));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::log() . " WHERE $sqlWhere ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per, ($page - 1) * $per])), ARRAY_A);
        // phpcs:enable
        return ['rows' => array_map([self::class, 'row'], $rows ?: []), 'total' => $total];
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            'submitted'           => __('Payment sent', 'sepo-membership'),
            'recorded'            => __('Recorded by hand', 'sepo-membership'),
            'proof_added'         => __('Proof added', 'sepo-membership'),
            'confirmed'           => __('Confirmed', 'sepo-membership'),
            'rejected'            => __('Marked not received', 'sepo-membership'),
            'withdraw_requested'  => __('Withdrawal asked for', 'sepo-membership'),
            'withdraw_cancelled'  => __('Withdrawal request taken back', 'sepo-membership'),
            'withdraw_approved'   => __('Withdrawal approved', 'sepo-membership'),
            'withdraw_declined'   => __('Withdrawal declined', 'sepo-membership'),
            'deleted'             => __('Payment deleted', 'sepo-membership'),
            'plan_set'            => __('Plan changed by hand', 'sepo-membership'),
            'status_set'          => __('Standing changed by hand', 'sepo-membership'),
            'member_added'        => __('Member added by hand', 'sepo-membership'),
            'member_deleted'      => __('Membership deleted', 'sepo-membership'),
            'cancelled'           => __('Plan cancelled by member', 'sepo-membership'),
            'resumed'             => __('Cancellation undone by member', 'sepo-membership'),
        ];
    }

    public static function label(string $action): string
    {
        return self::labels()[$action] ?? $action;
    }

    /** For personal-data erasure: keep the entries, drop what identifies the person. */
    public static function anonymise(int $userId, array $paymentIds): void
    {
        global $wpdb;
        if ($userId > 0) {
            $wpdb->update(Schema::log(), ['user_id' => 0, 'note' => ''], ['user_id' => $userId]);
        }
        foreach ($paymentIds as $id) {
            $wpdb->update(Schema::log(), ['note' => '', 'summary' => ''], ['payment_id' => (int) $id]);
        }
    }
}
