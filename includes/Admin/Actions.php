<?php

declare(strict_types=1);

namespace SepoMembership\Admin;

use SepoMembership\Log;
use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Plugin;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/** The dashboard's form handlers. Each checks its nonce and the capability, then sends the browser back. */
final class Actions
{
    private static function check(string $action): void
    {
        if (!Plugin::canManage()) {
            wp_die(esc_html__('You cannot manage the membership.', 'sepo-membership'), '', ['response' => 403]);
        }
        check_admin_referer('smem_' . $action);
    }

    private static function back(array $args, string $fallback = ''): void
    {
        $to = wp_get_referer() ?: ($fallback !== '' ? $fallback : Menu::url());
        $to = remove_query_arg(['smem_done', 'smem_error'], $to);
        wp_safe_redirect(add_query_arg(array_map('rawurlencode', $args), $to));
        exit;
    }

    /** @return mixed */
    private static function in(string $key, $default = '')
    {
        return isset($_POST[$key]) ? wp_unslash($_POST[$key]) : $default; // phpcs:ignore WordPress.Security.NonceVerification -- checked in check()
    }

    private static function run(string $action, callable $cb, string $done): void
    {
        self::check($action);
        try {
            $cb();
        } catch (\Throwable $e) {
            self::back(['smem_error' => $e->getMessage()]);
        }
        self::back(['smem_done' => $done]);
    }

    /** A date field (Y-m-d) as the end of that day in the site's time zone; 0 when empty. */
    private static function date(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('~^\d{4}-\d{2}-\d{2}$~', $value)) {
            return 0;
        }
        $dt = date_create_immutable($value . ' 23:59:59', wp_timezone());
        return $dt ? $dt->getTimestamp() : 0;
    }

    public static function confirm(): void
    {
        self::run('confirm', static function (): void {
            Payments::confirm((int) self::in('payment'), get_current_user_id());
        }, 'confirmed');
    }

    public static function reject(): void
    {
        self::run('reject', static function (): void {
            Payments::reject((int) self::in('payment'), get_current_user_id(), (string) self::in('note'));
        }, 'rejected');
    }

    /** Approve or decline a payer's request to withdraw a payment. */
    public static function withdrawal(): void
    {
        self::check('withdrawal');
        $approve = (string) self::in('decision') === 'approve';
        try {
            Payments::decideWithdrawal((int) self::in('payment'), $approve, get_current_user_id(), (string) self::in('note'));
        } catch (\Throwable $e) {
            self::back(['smem_error' => $e->getMessage()]);
        }
        self::back(['smem_done' => $approve ? 'withdrawn' : 'kept']);
    }

    public static function delete_payment(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        self::run('delete_payment', static function (): void {
            Payments::delete((int) self::in('payment'), (string) self::in('note'));
        }, 'deleted');
    }

    /** A membership payment that has already arrived, for one member. */
    public static function record(): void
    {
        self::run('record', static function (): void {
            $uid = (int) self::in('user_id');
            $level = sanitize_key((string) self::in('level'));
            $period = sanitize_key((string) self::in('period'));
            if (Tiers::get($level) === null || !in_array($period, Tiers::PERIODS, true)) {
                throw new \InvalidArgumentException(__('Choose a plan and a period.', 'sepo-membership'));
            }
            $amount = (string) self::in('amount') === '' ? (float) Tiers::price($level, $period) : (float) self::in('amount');
            Members::request($uid, $level, $period, true);
            Payments::record([
                'kind' => 'membership', 'user_id' => $uid, 'level' => $level, 'period' => $period,
                'amount' => $amount, 'method' => (string) self::in('method'), 'reference' => (string) self::in('reference'),
                'note' => (string) self::in('note'),
            ], get_current_user_id());
        }, 'recorded');
    }

    /** A donation or sponsorship that arrived outside the site. */
    public static function gift(): void
    {
        self::run('gift', static function (): void {
            $project = (int) self::in('project');
            $email = sanitize_email((string) self::in('email'));
            $user = $email !== '' ? get_user_by('email', $email) : false;
            if ((float) self::in('amount') <= 0) {
                throw new \InvalidArgumentException(__('Enter the amount.', 'sepo-membership'));
            }
            if (trim((string) self::in('name')) === '' && !$user) {
                throw new \InvalidArgumentException(__('Enter the giver\'s name.', 'sepo-membership'));
            }
            Payments::record([
                'kind' => $project > 0 ? 'sponsorship' : 'donation', 'user_id' => $user ? (int) $user->ID : 0,
                'project_id' => $project, 'amount' => (float) self::in('amount'), 'method' => (string) self::in('method'),
                'reference' => (string) self::in('reference'), 'name' => (string) self::in('name'), 'email' => $email,
                'listed' => (string) self::in('listed') === '1', 'note' => (string) self::in('note'),
            ], get_current_user_id());
        }, 'recorded');
    }

    public static function set_plan(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        self::run('set_plan', static function (): void {
            $expires = (string) self::in('no_expiry') === '1' ? 0 : self::date((string) self::in('expires'));
            Members::adminSetPlan((int) self::in('user_id'), sanitize_key((string) self::in('level')), sanitize_key((string) self::in('period')), (string) self::in('no_expiry') === '1' || $expires > 0 ? $expires : null);
            Members::setListed((int) self::in('user_id'), (string) self::in('listed') === '1');
            $m = Members::get((int) self::in('user_id'));
            Log::add('plan_set', null, $m ? Tiers::label((string) $m['level']) . ' · ' . Tiers::periodLabel((string) $m['period']) . ($m['expires_ts'] > 0 ? ' · ' . wp_date('Y-m-d', $m['expires_ts']) : '') : '', (int) self::in('user_id'));
        }, 'plan');
    }

    public static function set_status(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        self::run('set_status', static function (): void {
            Members::adminSetStatus((int) self::in('user_id'), sanitize_key((string) self::in('status')));
            Log::add('status_set', null, sanitize_key((string) self::in('status')), (int) self::in('user_id'));
        }, 'status');
    }

    /** Put an existing account on a plan, active at once, without a payment. */
    public static function add_member(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        self::check('add_member');
        $who = trim((string) self::in('user'));
        $u = is_email($who) ? get_user_by('email', $who) : get_user_by('login', $who);
        if (!$u) {
            self::back(['smem_error' => __('No account has that email address or username.', 'sepo-membership')]);
        }
        $level = sanitize_key((string) self::in('level'));
        $period = sanitize_key((string) self::in('period'));
        try {
            Members::adminSetPlan((int) $u->ID, $level, $period, null);
            $expires = self::date((string) self::in('expires'));
            Members::adminSetStatus((int) $u->ID, 'active', $expires > 0 ? $expires : null);
            Log::add('member_added', null, Tiers::label($level) . ' · ' . Tiers::periodLabel($period), (int) $u->ID);
        } catch (\Throwable $e) {
            self::back(['smem_error' => $e->getMessage()]);
        }
        wp_safe_redirect(Menu::url('members', ['member' => (int) $u->ID, 'smem_done' => 'added']));
        exit;
    }

    public static function delete_member(): void // phpcs:ignore PSR1.Methods.CamelCapsMethodName
    {
        self::check('delete_member');
        Log::add('member_deleted', null, '', (int) self::in('user_id'));
        Members::delete((int) self::in('user_id'));
        wp_safe_redirect(Menu::url('members', ['smem_done' => 'deleted']));
        exit;
    }

    public static function plans(): void
    {
        self::check('plans');
        $rows = (array) self::in('plan', []);
        if (!Tiers::fromBridge()) {
            $own = [];
            foreach ($rows as $id => $r) {
                if (!is_array($r) || !empty($r['remove'])) {
                    continue;
                }
                $own[] = ['id' => (string) $id, 'label' => (string) ($r['label'] ?? ''), 'rank' => (int) ($r['rank'] ?? 0)];
            }
            $new = (array) self::in('new', []);
            if (trim((string) ($new['label'] ?? '')) !== '') {
                $id = sanitize_key((string) ($new['id'] ?? '')) ?: sanitize_title((string) $new['label']);
                $own[] = ['id' => $id, 'label' => (string) $new['label'], 'rank' => (int) ($new['rank'] ?? (count($own) + 1))];
                $rows[$id] = $new;
            }
            Tiers::saveOwn($own);
        }
        $plans = [];
        foreach ($rows as $id => $r) {
            if (is_array($r) && empty($r['remove'])) {
                $plans[(string) $id] = $r;
            }
        }
        Tiers::savePlans($plans);
        self::back(['smem_done' => 'saved']);
    }

    public static function settings(): void
    {
        self::check('settings');
        $methods = [];
        foreach ((array) self::in('methods', []) as $m) {
            if (!is_array($m) || trim((string) ($m['label'] ?? '')) === '') {
                continue;
            }
            $type = ($m['type'] ?? '') === 'link' ? 'link' : 'manual';
            $detail = $type === 'link' ? esc_url_raw(trim((string) ($m['detail'] ?? ''))) : sanitize_textarea_field((string) ($m['detail'] ?? ''));
            $id = sanitize_key((string) ($m['id'] ?? '')) ?: sanitize_key(sanitize_title((string) $m['label']));
            $methods[] = ['id' => $id, 'label' => sanitize_text_field((string) $m['label']), 'type' => $type, 'detail' => $detail];
        }
        $accent = sanitize_hex_color((string) self::in('accent'));
        $proof = (string) self::in('proof');
        $values = [
            'currency'            => strtoupper(substr(preg_replace('~[^A-Za-z]~', '', (string) self::in('currency')) ?: 'ZMW', 0, 3)),
            'accent'              => $accent ?: '',
            'methods'             => $methods,
            'membership_methods'  => array_map('sanitize_key', (array) self::in('membership_methods', [])),
            'donation_methods'    => array_map('sanitize_key', (array) self::in('donation_methods', [])),
            'proof'               => in_array($proof, ['off', 'optional', 'required'], true) ? $proof : 'optional',
            'proof_max_mb'        => max(1, min(50, (int) self::in('proof_max_mb'))),
            'guest_donations'     => (string) self::in('guest_donations') === '1' ? 1 : 0,
            'donate_amounts'      => sanitize_text_field((string) self::in('donate_amounts')),
            'donate_goal'         => max(0, (float) self::in('donate_goal')),
            'sponsor_levels'      => sanitize_textarea_field((string) self::in('sponsor_levels')),
            'remind_days'         => max(0, min(60, (int) self::in('remind_days'))),
            'notify_email'        => sanitize_email((string) self::in('notify_email')),
            'notify_payments'     => (string) self::in('notify_payments') === '1' ? 1 : 0,
            'delete_on_uninstall' => (string) self::in('delete_on_uninstall') === '1' ? 1 : 0,
        ];
        foreach (array_keys(Settings::PAGES) as $k) {
            $values[$k] = absint(self::in($k));
        }
        Settings::update($values);
        self::back(['smem_done' => 'saved']);
    }

    /** Payments as a spreadsheet, with the list's filters. */
    public static function export(): void
    {
        if (!Plugin::canManage()) {
            wp_die('', '', ['response' => 403]);
        }
        check_admin_referer('smem_export');
        // phpcs:disable WordPress.Security.NonceVerification -- checked above
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $kind = sanitize_key((string) ($_GET['kind'] ?? ''));
        // phpcs:enable
        $rows = Payments::query(['status' => $status !== '' && $status !== 'all' ? [$status] : [], 'kind' => $kind, 'per_page' => 1000])['rows'];
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="payments-' . gmdate('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        $cell = static fn($v): string => preg_match('~^[=+\-@\t\r]~', (string) $v) ? "'" . $v : (string) $v;
        fputcsv($out, ['ID', 'Date', 'Kind', 'Name', 'Email', 'Plan', 'Period', 'Project', 'Amount', 'Currency', 'Method', 'Reference', 'Status', 'Confirmed', 'Listed', 'Monthly', 'Note', 'Withdrawal reason']);
        foreach ($rows as $p) {
            fputcsv($out, array_map($cell, [
                $p['id'], $p['created'], $p['kind'], $p['name'], $p['email'], $p['level'], $p['period'],
                $p['project_id'] > 0 ? get_the_title($p['project_id']) : '', number_format((float) $p['amount'], 2, '.', ''), $p['currency'],
                Payments::methodLabel((string) $p['method']), $p['reference'], $p['status'], (string) $p['confirmed_at'],
                $p['listed'] ? 'yes' : 'no', $p['recurring'] ? 'yes' : 'no', $p['admin_note'], (string) ($p['withdraw_reason'] ?? ''),
            ]));
        }
        fclose($out);
        exit;
    }
}
