<?php

declare(strict_types=1);

namespace SepoMembership\Admin;

use SepoMembership\Log;
use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Plugin;
use SepoMembership\PostTypes;
use SepoMembership\Projects;
use SepoMembership\Render\Parts;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/** The dashboard screens. They only show; changes go through Actions. */
final class Screens
{
    private static function form(string $action, string $inner, string $class = ''): string
    {
        return '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"' . ($class !== '' ? ' class="' . esc_attr($class) . '"' : '') . '>'
            . '<input type="hidden" name="action" value="smem_' . esc_attr($action) . '">'
            . wp_nonce_field('smem_' . $action, '_wpnonce', true, false) . $inner . '</form>';
    }

    private static function statusBadge(string $status, int $uid = 0): string
    {
        $map = ['active' => 'good', 'pending' => 'warn', 'expired' => 'bad', 'cancelled' => 'bad', 'none' => ''];
        $label = [
            'active'    => __('Active', 'sepo-membership'),
            'pending'   => __('Waiting for payment', 'sepo-membership'),
            'expired'   => __('Expired', 'sepo-membership'),
            'cancelled' => __('Cancelled', 'sepo-membership'),
            'none'      => __('Not a member', 'sepo-membership'),
        ][$status] ?? $status;
        if ($uid > 0 && $status === 'active' && Members::isEnding($uid)) {
            $label = __('Active, cancelled', 'sepo-membership');
            $map['active'] = 'warn';
        }
        return '<span class="smem-pill smem-pill--' . esc_attr($map[$status] ?? '') . '">' . esc_html($label) . '</span>';
    }

    private static function payStatusBadge(string $s): string
    {
        $k = ['paid' => 'good', 'rejected' => 'bad', 'pending' => 'warn', 'withdrawal' => 'info'][$s] ?? '';
        return '<span class="smem-pill smem-pill--' . $k . '">' . esc_html(Payments::statusLabel($s)) . '</span>';
    }

    private static function tierOptions(string $selected, bool $any = false): string
    {
        $h = $any ? '<option value="">' . esc_html__('Any plan', 'sepo-membership') . '</option>' : '';
        foreach (Tiers::all() as $t) {
            $h .= '<option value="' . esc_attr($t['id']) . '"' . selected($selected, $t['id'], false) . '>' . esc_html($t['label']) . '</option>';
        }
        return $h;
    }

    private static function periodOptions(string $selected): string
    {
        $h = '';
        foreach (Tiers::PERIODS as $p) {
            $h .= '<option value="' . esc_attr($p) . '"' . selected($selected, $p, false) . '>' . esc_html(Tiers::periodLabel($p)) . '</option>';
        }
        return $h;
    }

    private static function methodOptions(string $for, string $selected = ''): string
    {
        $h = '<option value="">' . esc_html__('Recorded by hand', 'sepo-membership') . '</option>';
        foreach (Settings::methods($for) as $m) {
            $h .= '<option value="' . esc_attr($m['id']) . '"' . selected($selected, $m['id'], false) . '>' . esc_html($m['label']) . '</option>';
        }
        return $h;
    }

    private static function what(array $p): string
    {
        if ($p['kind'] === 'membership') {
            return Tiers::label((string) $p['level']) . ' · ' . Tiers::periodLabel((string) $p['period']);
        }
        if ($p['project_id'] > 0) {
            return Payments::kindLabel('sponsorship') . ': ' . get_the_title($p['project_id']);
        }
        return Payments::kindLabel('donation') . ($p['recurring'] ? ' · ' . __('monthly', 'sepo-membership') : '');
    }

    private static function payer(array $p): string
    {
        $name = esc_html((string) $p['name'] !== '' ? (string) $p['name'] : __('Unknown', 'sepo-membership'));
        $link = $p['user_id'] > 0 && $p['kind'] === 'membership' ? '<a href="' . esc_url(Menu::url('members', ['member' => $p['user_id']])) . '">' . $name . '</a>' : $name;
        return '<strong>' . $link . '</strong><br><span class="smem-muted">' . esc_html((string) $p['email']) . '</span>';
    }

    private static function proofs(array $p): string
    {
        if ($p['proofs'] === []) {
            return '<span class="smem-muted">—</span>';
        }
        $out = [];
        foreach ($p['proofs'] as $i => $f) {
            $out[] = '<a href="' . esc_url(Payments::proofUrl($p['id'], (int) $i)) . '" target="_blank" rel="noopener">' . esc_html(wp_html_excerpt((string) ($f['name'] ?? 'file'), 24, '…')) . '</a>';
        }
        return implode('<br>', $out);
    }

    // ---------------------------------------------------------- overview

    public static function overview(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        $f = Menu::figures();
        echo '<div class="wrap smem-admin"><h1>' . esc_html__('Membership', 'sepo-membership') . '</h1>';
        echo '<div class="smem-cards">';
        $cards = [
            [number_format_i18n((int) $f['members']['active']), __('Active members', 'sepo-membership'), Menu::url('members', ['status' => 'active'])],
            [number_format_i18n((int) $f['pending']['total']), __('Payments to confirm', 'sepo-membership'), Menu::url('payments', ['status' => 'pending'])],
            [number_format_i18n((int) $f['withdrawals']), __('Withdrawal requests', 'sepo-membership'), Menu::url('payments', ['status' => 'withdrawal'])],
            [Settings::money($f['month']['membership']), __('Membership, last 30 days', 'sepo-membership'), Menu::url('payments', ['status' => 'paid', 'kind' => 'membership'])],
            [Settings::money($f['month']['gifts']), __('Gifts, last 30 days', 'sepo-membership'), Menu::url('payments', ['status' => 'paid', 'kind' => 'gifts'])],
        ];
        foreach ($cards as [$n, $l, $u]) {
            echo '<a class="smem-statcard" href="' . esc_url($u) . '"><span class="smem-statcard__n">' . esc_html($n) . '</span><span class="smem-statcard__l">' . esc_html($l) . '</span></a>';
        }
        echo '</div><div class="smem-cols">';

        echo '<div class="smem-panelbox"><h2>' . esc_html__('Waiting for confirmation', 'sepo-membership') . '</h2>';
        if ($f['pending']['total'] === 0) {
            echo '<p class="smem-muted">' . esc_html__('Nothing to confirm. New payments appear here as soon as people send them.', 'sepo-membership') . '</p>';
        } else {
            $rows = Payments::query(['status' => ['pending'], 'per_page' => 8])['rows'];
            echo '<table class="widefat striped smem-table"><tbody>';
            foreach ($rows as $p) {
                echo '<tr><td>' . self::payer($p) . '</td><td>' . esc_html(self::what($p)) . '<br><span class="smem-muted">' . esc_html(Payments::methodLabel((string) $p['method']) . ((string) $p['reference'] !== '' ? ' · ' . $p['reference'] : '')) . '</span></td>'
                    . '<td class="smem-num"><strong>' . esc_html(Settings::money((float) $p['amount'], (string) $p['currency'])) . '</strong><br><span class="smem-muted">' . esc_html(Parts::ago($p['created_ts'])) . '</span></td>'
                    . '<td>' . self::proofs($p) . '</td><td class="smem-actions">'
                    . self::form('confirm', '<input type="hidden" name="payment" value="' . (int) $p['id'] . '"><button class="button button-primary">' . esc_html__('Confirm', 'sepo-membership') . '</button>')
                    . '</td></tr>';
            }
            echo '</tbody></table><p><a href="' . esc_url(Menu::url('payments', ['status' => 'pending'])) . '">' . esc_html__('All payments waiting', 'sepo-membership') . ' →</a></p>';
        }
        echo '</div>';

        echo '<div class="smem-panelbox"><h2>' . esc_html__('Members by plan', 'sepo-membership') . '</h2>';
        if ($f['tiers'] === []) {
            echo '<p class="smem-muted">' . esc_html__('No plans yet.', 'sepo-membership') . ' <a href="' . esc_url(Menu::url('plans')) . '">' . esc_html__('Set up plans', 'sepo-membership') . '</a></p>';
        } else {
            echo '<table class="widefat smem-table"><tbody>';
            foreach ($f['tiers'] as $t) {
                $n = (int) ($f['byLevel'][$t['id']] ?? 0);
                echo '<tr><td><a href="' . esc_url(Menu::url('members', ['status' => 'active', 'level' => $t['id']])) . '">' . esc_html($t['label']) . '</a></td><td class="smem-num">' . esc_html(number_format_i18n($n)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '<p class="smem-muted">' . esc_html(sprintf(
            /* translators: 1: waiting, 2: expired, 3: cancelled */
            __('%1$d waiting for payment · %2$d expired · %3$d cancelled', 'sepo-membership'),
            (int) $f['members']['pending'],
            (int) $f['members']['expired'],
            (int) $f['members']['cancelled']
        )) . '</p>';

        echo '<h2>' . esc_html__('Open projects', 'sepo-membership') . '</h2>';
        if ($f['projects'] === []) {
            echo '<p class="smem-muted">' . esc_html__('No open projects.', 'sepo-membership') . ' <a href="' . esc_url(admin_url('post-new.php?post_type=' . PostTypes::PROJECT)) . '">' . esc_html__('Add a project', 'sepo-membership') . '</a></p>';
        } else {
            foreach (array_slice($f['projects'], 0, 5) as $p) {
                echo '<div class="smem-proj"><a href="' . esc_url((string) get_edit_post_link($p['id'])) . '">' . esc_html($p['title']) . '</a>'
                    . '<span class="smem-muted">' . esc_html(Settings::money($p['raised']) . ($p['goal'] > 0 ? ' / ' . Settings::money($p['goal']) : '')) . '</span>'
                    . ($p['goal'] > 0 ? '<span class="smem-bar"><span style="width:' . (int) $p['percent'] . '%"></span></span>' : '') . '</div>';
            }
        }
        echo '</div></div>';

        echo '<div class="smem-panelbox"><h2>' . esc_html__('Pages and shortcodes', 'sepo-membership') . '</h2><table class="widefat smem-table"><tbody>';
        $codes = [
            'plans_page'    => ['[sepo_membership_plans]', __('Pricing table and joining.', 'sepo-membership')],
            'account_page'  => ['[sepo_membership_account]', __('Each member\'s plan, payments and choices.', 'sepo-membership')],
            'area_page'     => ['[sepo_members_area tier="" discussions_tier="" suggestions_tier="" collection="" show=""]', __('Downloads, links, discussions and suggestions. Every attribute is optional.', 'sepo-membership')],
            'sponsors_page' => ['[sepo_sponsors show="stats,projects,sponsors,members"]', __('Projects, sponsors by level and listed members.', 'sepo-membership')],
            'donate_page'   => ['[sepo_donate project="" amounts=""]', __('Giving form, general or for one project.', 'sepo-membership')],
        ];
        foreach ($codes as $key => [$code, $what]) {
            $url = Settings::pageUrl($key);
            echo '<tr><td><code>' . esc_html($code) . '</code><br><span class="smem-muted">' . esc_html($what) . '</span></td><td>'
                . ($url !== '' ? '<a href="' . esc_url($url) . '">' . esc_html(get_the_title((int) Settings::get($key))) . '</a>' : '<span class="smem-muted">' . esc_html__('No page set', 'sepo-membership') . '</span>') . '</td></tr>';
        }
        echo '<tr><td><code>[sepo_members_only tier="gold"]…[/sepo_members_only]</code><br><span class="smem-muted">' . esc_html__('Part of any page, for members at a plan or above.', 'sepo-membership') . '</span></td><td></td></tr>';
        echo '</tbody></table>';
        echo '<p class="smem-muted">' . esc_html(Tiers::bridgeActive()
            ? (Tiers::fromBridge() ? __('Plans use the tiers from Sepo WordPress Bridge. Its tier gates open for members whose plan here is active and paid.', 'sepo-membership') : __('Sepo WordPress Bridge is active but has no tiers yet. Add tiers there to share them, or use the plans set up here.', 'sepo-membership'))
            : __('Plans use the tiers set up here. With Sepo WordPress Bridge active, they are shared with it.', 'sepo-membership')) . '</p></div>';
        echo '</div>';
    }

    // ---------------------------------------------------------- members

    public static function members(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $member = isset($_GET['member']) ? absint($_GET['member']) : 0;
        if ($member > 0) {
            self::member($member);
            return;
        }
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        $level = isset($_GET['level']) ? sanitize_key((string) $_GET['level']) : '';
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $res = Members::query(['status' => $status, 'level' => $level, 'q' => $q, 'page' => $page, 'per_page' => 25]);
        $stats = Members::stats();

        echo '<div class="wrap smem-admin"><h1 class="wp-heading-inline">' . esc_html__('Members', 'sepo-membership') . '</h1>'
            . ' <a href="#smem-add" class="page-title-action" data-smem-toggle="smem-add">' . esc_html__('Add member', 'sepo-membership') . '</a><hr class="wp-header-end">';

        echo '<div id="smem-add" class="smem-panelbox" hidden>' . self::form('add_member',
            '<h2>' . esc_html__('Add a member by hand', 'sepo-membership') . '</h2><p class="smem-muted">' . esc_html__('Puts an existing account on a plan, active at once, with no payment. Use Record a payment on the member\'s page when money has arrived.', 'sepo-membership') . '</p>'
            . '<div class="smem-inline"><label>' . esc_html__('Email or username', 'sepo-membership') . '<input type="text" name="user" required class="regular-text"></label>'
            . '<label>' . esc_html__('Plan', 'sepo-membership') . '<select name="level">' . self::tierOptions('') . '</select></label>'
            . '<label>' . esc_html__('Period', 'sepo-membership') . '<select name="period">' . self::periodOptions('monthly') . '</select></label>'
            . '<label>' . esc_html__('Runs until', 'sepo-membership') . '<input type="date" name="expires"></label>'
            . '<button class="button button-primary">' . esc_html__('Add member', 'sepo-membership') . '</button></div>'
            . '<p class="description">' . esc_html__('Leave the date empty to run for one period from today.', 'sepo-membership') . '</p>'
        ) . '</div>';

        $tabs = ['' => [__('All', 'sepo-membership'), $stats['total']], 'active' => [__('Active', 'sepo-membership'), $stats['active']], 'pending' => [__('Waiting for payment', 'sepo-membership'), $stats['pending']], 'expired' => [__('Expired', 'sepo-membership'), $stats['expired']], 'cancelled' => [__('Cancelled', 'sepo-membership'), $stats['cancelled']]];
        echo '<ul class="subsubsub">';
        $i = 0;
        foreach ($tabs as $k => [$l, $n]) {
            echo '<li>' . ($i++ > 0 ? ' | ' : '') . '<a href="' . esc_url(Menu::url('members', array_filter(['status' => $k, 'level' => $level]))) . '"' . ($status === $k ? ' class="current" aria-current="page"' : '') . '>' . esc_html($l) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
        }
        echo '</ul>';
        echo '<form method="get" class="smem-filter"><input type="hidden" name="page" value="' . esc_attr(Menu::SLUG . '-members') . '"><input type="hidden" name="status" value="' . esc_attr($status) . '">'
            . '<select name="level">' . self::tierOptions($level, true) . '</select>'
            . '<input type="search" name="s" value="' . esc_attr($q) . '" placeholder="' . esc_attr__('Name or email', 'sepo-membership') . '">'
            . '<button class="button">' . esc_html__('Filter', 'sepo-membership') . '</button></form>';

        echo '<table class="widefat striped smem-table"><thead><tr><th>' . esc_html__('Member', 'sepo-membership') . '</th><th>' . esc_html__('Plan', 'sepo-membership') . '</th><th>' . esc_html__('Status', 'sepo-membership') . '</th><th>' . esc_html__('Runs until', 'sepo-membership') . '</th><th class="smem-num">' . esc_html__('Paid', 'sepo-membership') . '</th><th>' . esc_html__('Listed', 'sepo-membership') . '</th></tr></thead><tbody>';
        if ($res['rows'] === []) {
            echo '<tr><td colspan="6" class="smem-muted">' . esc_html__('No members match.', 'sepo-membership') . '</td></tr>';
        }
        foreach ($res['rows'] as $m) {
            $uid = (int) $m['user_id'];
            $st = Members::status($uid);
            echo '<tr><td><a href="' . esc_url(Menu::url('members', ['member' => $uid])) . '"><strong>' . esc_html((string) ($m['display_name'] ?? ('#' . $uid))) . '</strong></a><br><span class="smem-muted">' . esc_html((string) ($m['user_email'] ?? '')) . '</span></td>'
                . '<td>' . esc_html(Tiers::label((string) $m['level'])) . ((string) $m['pending_level'] !== '' ? '<br><span class="smem-muted">→ ' . esc_html(Tiers::label((string) $m['pending_level'])) . '</span>' : '') . '<br><span class="smem-muted">' . esc_html(Tiers::periodLabel((string) $m['period'])) . '</span></td>'
                . '<td>' . self::statusBadge($st, $uid) . ((float) $m['pending_total'] > 0 ? '<br><span class="smem-muted">' . esc_html(sprintf(/* translators: %s: amount */ __('%s waiting', 'sepo-membership'), Settings::money((float) $m['pending_total']))) . '</span>' : '') . '</td>'
                . '<td>' . esc_html($m['expires_ts'] > 0 ? Parts::date($m['expires_ts']) : ($st === 'active' ? __('No end date', 'sepo-membership') : '—')) . '</td>'
                . '<td class="smem-num">' . esc_html(Settings::money((float) $m['total'])) . '</td>'
                . '<td>' . ($m['listed'] ? '✓' : '') . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pager($res['total'], 25, $page);
        echo '</div>';
    }

    private static function pager(int $total, int $per, int $page): void
    {
        $pages = (int) ceil($total / $per);
        if ($pages < 2) {
            return;
        }
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
            'base'    => add_query_arg('paged', '%#%'),
            'format'  => '',
            'current' => $page,
            'total'   => $pages,
        ]) . '</div></div>';
    }

    private static function member(int $uid): void
    {
        $u = get_userdata($uid);
        $m = Members::get($uid);
        echo '<div class="wrap smem-admin"><p><a href="' . esc_url(Menu::url('members')) . '">← ' . esc_html__('All members', 'sepo-membership') . '</a></p>';
        if (!$u) {
            echo '<p>' . esc_html__('That account no longer exists.', 'sepo-membership') . '</p></div>';
            return;
        }
        $st = Members::status($uid);
        echo '<h1>' . esc_html($u->display_name) . ' ' . self::statusBadge($st, $uid) . '</h1>';
        echo '<p class="smem-muted">' . esc_html($u->user_email) . ' · <a href="' . esc_url((string) get_edit_user_link($uid)) . '">' . esc_html__('Account', 'sepo-membership') . '</a> · <a href="' . esc_url(Menu::url('activity', ['member' => $uid])) . '">' . esc_html__('Activity', 'sepo-membership') . '</a></p>';

        echo '<div class="smem-cols">';
        echo '<div class="smem-panelbox"><h2>' . esc_html__('Plan', 'sepo-membership') . '</h2>';
        if ($m === null) {
            echo '<p class="smem-muted">' . esc_html__('No membership yet. Record a payment to start one, or add them from the Members list.', 'sepo-membership') . '</p>';
        } else {
            echo '<dl class="smem-dl"><dt>' . esc_html__('Member since', 'sepo-membership') . '</dt><dd>' . esc_html(Parts::date($m['started_ts']) ?: '—') . '</dd>'
                . '<dt>' . esc_html__('Paid in total', 'sepo-membership') . '</dt><dd>' . esc_html(Settings::money((float) $m['total'])) . '</dd>'
                . ((string) $m['pending_level'] !== '' ? '<dt>' . esc_html__('Moves to', 'sepo-membership') . '</dt><dd>' . esc_html(Tiers::label((string) $m['pending_level'])) . ' ' . esc_html__('at the next payment', 'sepo-membership') . '</dd>' : '')
                . '</dl>';
            echo self::form('set_plan',
                '<input type="hidden" name="user_id" value="' . $uid . '"><div class="smem-inline">'
                . '<label>' . esc_html__('Plan', 'sepo-membership') . '<select name="level">' . self::tierOptions((string) $m['level']) . '</select></label>'
                . '<label>' . esc_html__('Period', 'sepo-membership') . '<select name="period">' . self::periodOptions((string) $m['period']) . '</select></label>'
                . '<label>' . esc_html__('Runs until', 'sepo-membership') . '<input type="date" name="expires" value="' . esc_attr($m['expires_ts'] > 0 ? wp_date('Y-m-d', $m['expires_ts']) : '') . '"></label></div>'
                . '<p><label class="smem-check"><input type="checkbox" name="no_expiry" value="1"' . checked($m['expires_ts'] === 0 && $m['started_ts'] > 0, true, false) . '> ' . esc_html__('No end date', 'sepo-membership') . '</label>'
                . '<label class="smem-check"><input type="checkbox" name="listed" value="1"' . checked($m['listed'], true, false) . '> ' . esc_html__('Shown on the sponsors page', 'sepo-membership') . '</label></p>'
                . '<p><button class="button button-primary">' . esc_html__('Save plan', 'sepo-membership') . '</button></p>'
            );
            echo '<h3>' . esc_html__('Standing', 'sepo-membership') . '</h3><div class="smem-inline">';
            $choices = ['active' => __('Make active', 'sepo-membership'), 'pending' => __('Lock until paid', 'sepo-membership'), 'expired' => __('End now (expired)', 'sepo-membership'), 'cancelled' => __('End now (cancelled)', 'sepo-membership')];
            foreach ($choices as $k => $l) {
                if ($k === $st) {
                    continue;
                }
                echo self::form('set_status', '<input type="hidden" name="user_id" value="' . $uid . '"><input type="hidden" name="status" value="' . esc_attr($k) . '"><button class="button">' . esc_html($l) . '</button>');
            }
            echo '</div>';
        }
        echo '</div>';

        echo '<div class="smem-panelbox"><h2>' . esc_html__('Record a payment', 'sepo-membership') . '</h2><p class="smem-muted">' . esc_html__('For money that has already arrived. It is confirmed at once and extends the plan by one period.', 'sepo-membership') . '</p>'
            . self::form('record', '<input type="hidden" name="user_id" value="' . $uid . '"><div class="smem-inline">'
            . '<label>' . esc_html__('Plan', 'sepo-membership') . '<select name="level">' . self::tierOptions((string) ($m['level'] ?? '')) . '</select></label>'
            . '<label>' . esc_html__('Period', 'sepo-membership') . '<select name="period">' . self::periodOptions((string) ($m['period'] ?? 'monthly')) . '</select></label>'
            . '<label>' . esc_html__('Amount', 'sepo-membership') . '<input type="number" name="amount" step="any" min="0" placeholder="' . esc_attr__('Plan price', 'sepo-membership') . '"></label>'
            . '<label>' . esc_html__('Method', 'sepo-membership') . '<select name="method">' . self::methodOptions('membership') . '</select></label>'
            . '<label>' . esc_html__('Reference', 'sepo-membership') . '<input type="text" name="reference"></label>'
            . '<label>' . esc_html__('Note', 'sepo-membership') . '<input type="text" name="note"></label></div>'
            . '<p><button class="button button-primary">' . esc_html__('Record payment', 'sepo-membership') . '</button></p>')
            . '</div>';
        echo '</div>';

        $rows = Payments::query(['user_id' => $uid, 'per_page' => 100])['rows'];
        echo '<h2>' . esc_html__('Payments', 'sepo-membership') . '</h2>';
        self::paymentsTable($rows);
        if ($m !== null) {
            echo '<div class="smem-danger">' . self::form('delete_member', '<input type="hidden" name="user_id" value="' . $uid . '"><button class="button button-link-delete" data-smem-confirm>' . esc_html__('Delete this membership and its payments', 'sepo-membership') . '</button>') . '</div>';
        }
        echo '</div>';
    }

    // ---------------------------------------------------------- payments

    public static function payments(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'pending';
        $kind = isset($_GET['kind']) ? sanitize_key((string) $_GET['kind']) : '';
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $args = ['status' => $status !== 'all' ? [$status] : [], 'q' => $q, 'page' => $page, 'per_page' => 30];
        if ($kind === 'gifts') {
            $args['gifts'] = true;
        } else {
            $args['kind'] = $kind;
        }
        $res = Payments::query($args);

        echo '<div class="wrap smem-admin"><h1 class="wp-heading-inline">' . esc_html__('Payments', 'sepo-membership') . '</h1>'
            . ' <a href="#smem-gift" class="page-title-action" data-smem-toggle="smem-gift">' . esc_html__('Record a gift', 'sepo-membership') . '</a>'
            . ' <a href="' . esc_url(wp_nonce_url(add_query_arg(['action' => 'smem_export', 'status' => $status, 'kind' => $kind], admin_url('admin-post.php')), 'smem_export')) . '" class="page-title-action">' . esc_html__('Download CSV', 'sepo-membership') . '</a><hr class="wp-header-end">';

        $projects = '<option value="0">' . esc_html__('General donation', 'sepo-membership') . '</option>';
        foreach (Projects::all() as $p) {
            $projects .= '<option value="' . (int) $p['id'] . '">' . esc_html($p['title']) . '</option>';
        }
        echo '<div id="smem-gift" class="smem-panelbox" hidden>' . self::form('gift',
            '<h2>' . esc_html__('Record a gift that has arrived', 'sepo-membership') . '</h2><div class="smem-inline">'
            . '<label>' . esc_html__('Name', 'sepo-membership') . '<input type="text" name="name"></label>'
            . '<label>' . esc_html__('Email', 'sepo-membership') . '<input type="email" name="email"></label>'
            . '<label>' . esc_html__('Amount', 'sepo-membership') . '<input type="number" name="amount" step="any" min="0" required></label>'
            . '<label>' . esc_html__('For', 'sepo-membership') . '<select name="project">' . $projects . '</select></label>'
            . '<label>' . esc_html__('Method', 'sepo-membership') . '<select name="method">' . self::methodOptions('donation') . '</select></label>'
            . '<label>' . esc_html__('Reference', 'sepo-membership') . '<input type="text" name="reference"></label></div>'
            . '<p><label class="smem-check"><input type="checkbox" name="listed" value="1" checked> ' . esc_html__('Show on the sponsors page', 'sepo-membership') . '</label></p>'
            . '<p><button class="button button-primary">' . esc_html__('Record gift', 'sepo-membership') . '</button></p>'
        ) . '</div>';

        $tabs = ['pending' => __('Waiting', 'sepo-membership'), 'withdrawal' => __('Withdrawal requests', 'sepo-membership'), 'paid' => __('Confirmed', 'sepo-membership'), 'rejected' => __('Not received', 'sepo-membership'), 'withdrawn' => __('Withdrawn', 'sepo-membership'), 'all' => __('All', 'sepo-membership')];
        echo '<ul class="subsubsub">';
        $i = 0;
        foreach ($tabs as $k => $l) {
            $n = Payments::query(['status' => $k !== 'all' ? [$k] : [], 'per_page' => 1])['total'];
            echo '<li>' . ($i++ > 0 ? ' | ' : '') . '<a href="' . esc_url(Menu::url('payments', array_filter(['status' => $k, 'kind' => $kind]))) . '"' . ($status === $k ? ' class="current" aria-current="page"' : '') . '>' . esc_html($l) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
        }
        echo '</ul>';
        echo '<form method="get" class="smem-filter"><input type="hidden" name="page" value="' . esc_attr(Menu::SLUG . '-payments') . '"><input type="hidden" name="status" value="' . esc_attr($status) . '">'
            . '<select name="kind"><option value="">' . esc_html__('Everything', 'sepo-membership') . '</option>'
            . '<option value="membership"' . selected($kind, 'membership', false) . '>' . esc_html__('Membership', 'sepo-membership') . '</option>'
            . '<option value="gifts"' . selected($kind, 'gifts', false) . '>' . esc_html__('Donations and sponsorships', 'sepo-membership') . '</option></select>'
            . '<input type="search" name="s" value="' . esc_attr($q) . '" placeholder="' . esc_attr__('Name, email or reference', 'sepo-membership') . '">'
            . '<button class="button">' . esc_html__('Filter', 'sepo-membership') . '</button>'
            . '<span class="smem-muted smem-sum">' . esc_html(sprintf(/* translators: 1: count, 2: total */ _n('%1$d payment · %2$s', '%1$d payments · %2$s', $res['total'], 'sepo-membership'), $res['total'], Settings::money($res['sum']))) . '</span></form>';
        self::paymentsTable($res['rows']);
        self::pager($res['total'], 30, $page);
        echo '</div>';
    }

    /** @param list<array<string,mixed>> $rows */
    private static function paymentsTable(array $rows): void
    {
        echo '<table class="widefat striped smem-table"><thead><tr><th>' . esc_html__('Date', 'sepo-membership') . '</th><th>' . esc_html__('From', 'sepo-membership') . '</th><th>' . esc_html__('For', 'sepo-membership') . '</th><th class="smem-num">' . esc_html__('Amount', 'sepo-membership') . '</th><th>' . esc_html__('Method', 'sepo-membership') . '</th><th>' . esc_html__('Proof', 'sepo-membership') . '</th><th>' . esc_html__('Status', 'sepo-membership') . '</th><th></th></tr></thead><tbody>';
        if ($rows === []) {
            echo '<tr><td colspan="8" class="smem-muted">' . esc_html__('No payments here.', 'sepo-membership') . '</td></tr>';
        }
        foreach ($rows as $p) {
            $id = (int) $p['id'];
            $actions = '';
            $noteField = static fn(string $label, string $ph, bool $required = false): string => '<label class="screen-reader-text" for="smem-n-' . $id . '-' . md5($label) . '">' . esc_html($label) . '</label><input type="text" id="smem-n-' . $id . '-' . md5($label) . '" name="note" placeholder="' . esc_attr($ph) . '"' . ($required ? ' required' : '') . '>';
            if ($p['status'] === 'withdrawal') {
                $actions .= '<details class="smem-reject"><summary class="button button-primary button-small">' . esc_html__('Approve withdrawal', 'sepo-membership') . '</summary>'
                    . self::form('withdrawal', '<input type="hidden" name="payment" value="' . $id . '"><input type="hidden" name="decision" value="approve">' . $noteField(__('Note to the payer', 'sepo-membership'), __('Note to the payer, such as how the money is returned', 'sepo-membership')) . '<button class="button button-primary button-small">' . esc_html__('Approve and mark withdrawn', 'sepo-membership') . '</button>')
                    . '</details>'
                    . '<details class="smem-reject"><summary class="button button-small">' . esc_html__('Decline', 'sepo-membership') . '</summary>'
                    . self::form('withdrawal', '<input type="hidden" name="payment" value="' . $id . '"><input type="hidden" name="decision" value="decline">' . $noteField(__('Reason for declining', 'sepo-membership'), __('Why it stays open (sent to the payer)', 'sepo-membership'), true) . '<button class="button button-small">' . esc_html__('Decline and keep open', 'sepo-membership') . '</button>')
                    . '</details>';
            }
            if (!in_array($p['status'], ['paid', 'withdrawn'], true)) {
                $actions .= self::form('confirm', '<input type="hidden" name="payment" value="' . $id . '"><button class="button button-small' . ($p['status'] === 'withdrawal' ? '' : ' button-primary') . '">' . esc_html__('Confirm', 'sepo-membership') . '</button>');
            }
            if (in_array($p['status'], ['pending', 'paid'], true)) {
                $actions .= '<details class="smem-reject"><summary class="button button-small">' . esc_html__('Not received', 'sepo-membership') . '</summary>'
                    . self::form('reject', '<input type="hidden" name="payment" value="' . $id . '">' . $noteField(__('Note to the payer', 'sepo-membership'), __('Note to the payer (optional)', 'sepo-membership')) . '<button class="button button-small">' . esc_html__('Mark not received', 'sepo-membership') . '</button>')
                    . '</details>';
            }
            $actions .= '<details class="smem-reject"><summary class="button-link button-link-delete">' . esc_html__('Delete', 'sepo-membership') . '</summary>'
                . self::form('delete_payment', '<input type="hidden" name="payment" value="' . $id . '">' . $noteField(__('Why it is being deleted', 'sepo-membership'), __('Why, for the activity log (for example: duplicate)', 'sepo-membership'), true) . '<button class="button button-small button-link-delete" data-smem-confirm>' . esc_html__('Delete for good', 'sepo-membership') . '</button>')
                . '</details>';
            $extra = [];
            if ((string) $p['message'] !== '') {
                $extra[] = '“' . esc_html(wp_html_excerpt((string) $p['message'], 120, '…')) . '”';
            }
            if ($p['listed'] && $p['kind'] !== 'membership') {
                $extra[] = esc_html__('wants to be listed', 'sepo-membership');
            }
            if ((string) $p['admin_note'] !== '') {
                $extra[] = esc_html__('Note:', 'sepo-membership') . ' ' . esc_html((string) $p['admin_note']);
            }
            $reason = (string) ($p['withdraw_reason'] ?? '') !== '' && in_array($p['status'], ['withdrawal', 'withdrawn'], true)
                ? '<div class="smem-reason"><strong>' . esc_html__('Reason for withdrawing:', 'sepo-membership') . '</strong> ' . esc_html((string) $p['withdraw_reason']) . '</div>' : '';
            echo '<tr><td>' . esc_html(Parts::date($p['created_ts'])) . '<br><span class="smem-muted">#' . $id . '</span></td>'
                . '<td>' . self::payer($p) . '</td>'
                . '<td>' . esc_html(self::what($p)) . ($extra !== [] ? '<br><span class="smem-muted">' . implode(' · ', $extra) . '</span>' : '') . $reason . self::history($id) . '</td>'
                . '<td class="smem-num"><strong>' . esc_html(Settings::money((float) $p['amount'], (string) $p['currency'])) . '</strong></td>'
                . '<td>' . esc_html(Payments::methodLabel((string) $p['method'])) . ((string) $p['reference'] !== '' ? '<br><span class="smem-muted">' . esc_html((string) $p['reference']) . '</span>' : '') . '</td>'
                . '<td>' . self::proofs($p) . '</td>'
                . '<td>' . self::payStatusBadge((string) $p['status']) . ($p['confirmed_ts'] > 0 ? '<br><span class="smem-muted">' . esc_html(Parts::date($p['confirmed_ts'])) . '</span>' : '') . '</td>'
                . '<td class="smem-actions">' . $actions . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /** A payment's activity, folded under it. */
    private static function history(int $paymentId): string
    {
        $rows = Log::forPayment($paymentId);
        if ($rows === []) {
            return '';
        }
        $h = '<details class="smem-history"><summary>' . esc_html(sprintf(/* translators: %d: number of entries */ _n('History (%d)', 'History (%d)', count($rows), 'sepo-membership'), count($rows))) . '</summary><ol>';
        foreach ($rows as $r) {
            $h .= '<li><strong>' . esc_html($r['label']) . '</strong> · ' . esc_html($r['actor']) . ' · <span class="smem-muted">' . esc_html(wp_date('Y-m-d H:i', $r['created_ts'])) . '</span>'
                . ((string) $r['note'] !== '' ? '<br><span class="smem-muted">' . esc_html((string) $r['note']) . '</span>' : '') . '</li>';
        }
        return $h . '</ol></details>';
    }

    // ---------------------------------------------------------- activity

    public static function activity(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $action = isset($_GET['act']) ? sanitize_key((string) $_GET['act']) : '';
        $member = isset($_GET['member']) ? absint($_GET['member']) : 0;
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $res = Log::query(['action' => $action, 'user_id' => $member, 'page' => $page, 'per_page' => 50]);
        echo '<div class="wrap smem-admin"><h1>' . esc_html__('Activity log', 'sepo-membership') . '</h1>'
            . '<p class="smem-muted">' . esc_html__('Every step taken on payments and memberships, by whom and when. Entries cannot be edited or removed here, and stay after a payment is deleted.', 'sepo-membership') . '</p>';
        $opts = '<option value="">' . esc_html__('Everything', 'sepo-membership') . '</option>';
        foreach (Log::labels() as $k => $l) {
            $opts .= '<option value="' . esc_attr($k) . '"' . selected($action, $k, false) . '>' . esc_html($l) . '</option>';
        }
        echo '<form method="get" class="smem-filter"><input type="hidden" name="page" value="' . esc_attr(Menu::SLUG . '-activity') . '">'
            . ($member > 0 ? '<input type="hidden" name="member" value="' . $member . '">' : '')
            . '<select name="act">' . $opts . '</select><button class="button">' . esc_html__('Filter', 'sepo-membership') . '</button>'
            . '<span class="smem-muted smem-sum">' . esc_html(sprintf(/* translators: %d: entries */ _n('%d entry', '%d entries', $res['total'], 'sepo-membership'), $res['total'])) . '</span></form>';
        echo '<table class="widefat striped smem-table"><thead><tr><th>' . esc_html__('When', 'sepo-membership') . '</th><th>' . esc_html__('What', 'sepo-membership') . '</th><th>' . esc_html__('Payment or member', 'sepo-membership') . '</th><th>' . esc_html__('By', 'sepo-membership') . '</th><th>' . esc_html__('Note', 'sepo-membership') . '</th></tr></thead><tbody>';
        if ($res['rows'] === []) {
            echo '<tr><td colspan="5" class="smem-muted">' . esc_html__('Nothing has been logged yet.', 'sepo-membership') . '</td></tr>';
        }
        foreach ($res['rows'] as $r) {
            $subject = '';
            if ($r['payment_id'] > 0) {
                $subject = '#' . $r['payment_id'] . ((string) $r['summary'] !== '' ? ' · ' . esc_html((string) $r['summary']) : '');
            } elseif ($r['user_id'] > 0) {
                $u = get_userdata($r['user_id']);
                $subject = '<a href="' . esc_url(Menu::url('members', ['member' => $r['user_id']])) . '">' . esc_html($u ? (string) $u->display_name : '#' . $r['user_id']) . '</a>';
            }
            echo '<tr><td>' . esc_html(wp_date('Y-m-d H:i', $r['created_ts'])) . '</td><td><strong>' . esc_html($r['label']) . '</strong></td><td>' . $subject . '</td><td>' . esc_html($r['actor']) . '</td><td>' . esc_html((string) $r['note']) . '</td></tr>';
        }
        echo '</tbody></table>';
        self::pager($res['total'], 50, $page);
        echo '</div>';
    }

    // ---------------------------------------------------------- plans

    public static function plans(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        $bridge = Tiers::fromBridge();
        echo '<div class="wrap smem-admin"><h1>' . esc_html__('Plans and prices', 'sepo-membership') . '</h1>';
        if ($bridge) {
            echo '<div class="smem-notice-tint smem-box"><p>' . esc_html__('The tiers come from Sepo WordPress Bridge, so a plan here and a tier gate there mean the same thing. Rename, rank or add tiers in the Bridge; set prices and benefits here.', 'sepo-membership') . '</p></div>';
        }
        $inner = '<p class="smem-muted">' . esc_html__('Leave a price empty to not offer that period. A price of 0 makes the plan free: it opens at once with no payment.', 'sepo-membership') . '</p><div class="smem-plans-admin">';
        foreach (Tiers::all() as $t) {
            $inner .= self::planRow($t['id'], $t, $bridge);
        }
        if (!$bridge) {
            $inner .= '<fieldset class="smem-panelbox smem-planrow smem-planrow--new"><legend>' . esc_html__('Add a plan', 'sepo-membership') . '</legend><div class="smem-inline">'
                . '<label>' . esc_html__('Name', 'sepo-membership') . '<input type="text" name="new[label]"></label>'
                . '<label>' . esc_html__('ID', 'sepo-membership') . '<input type="text" name="new[id]" pattern="[a-z0-9_-]*" placeholder="' . esc_attr__('made from the name', 'sepo-membership') . '"></label>'
                . '<label>' . esc_html__('Rank', 'sepo-membership') . '<input type="number" name="new[rank]" class="small-text" value="' . (count(Tiers::all()) + 1) . '"></label>';
            foreach (Tiers::PERIODS as $p) {
                $inner .= '<label>' . esc_html(Tiers::periodLabel($p)) . '<input type="number" step="any" min="0" class="small-text" name="new[prices][' . esc_attr($p) . ']"></label>';
            }
            $inner .= '</div><label class="smem-block">' . esc_html__('Benefits, one per line', 'sepo-membership') . '<textarea name="new[benefits]" rows="3"></textarea></label></fieldset>';
        }
        $inner .= '</div><p><button class="button button-primary">' . esc_html__('Save plans', 'sepo-membership') . '</button></p>';
        echo self::form('plans', $inner);
        echo '</div>';
    }

    /** @param array<string,mixed> $t */
    private static function planRow(string $id, array $t, bool $bridge): string
    {
        $n = 'plan[' . esc_attr($id) . ']';
        $h = '<fieldset class="smem-panelbox smem-planrow"><legend>' . esc_html($t['label']) . ' <code>' . esc_html($id) . '</code></legend><div class="smem-inline">';
        if (!$bridge) {
            $h .= '<label>' . esc_html__('Name', 'sepo-membership') . '<input type="text" name="' . $n . '[label]" value="' . esc_attr($t['label']) . '"></label>'
                . '<label>' . esc_html__('Rank', 'sepo-membership') . '<input type="number" class="small-text" name="' . $n . '[rank]" value="' . (int) $t['rank'] . '"></label>';
        }
        foreach (Tiers::PERIODS as $p) {
            $v = isset($t['prices'][$p]) ? (string) $t['prices'][$p] : '';
            /* translators: %s: currency code */
            $h .= '<label>' . esc_html(Tiers::periodLabel($p)) . '<input type="number" step="any" min="0" class="small-text" name="' . $n . '[prices][' . esc_attr($p) . ']" value="' . esc_attr($v) . '" aria-label="' . esc_attr(sprintf(__('Price in %s', 'sepo-membership'), Settings::currency())) . '"></label>';
        }
        $h .= '</div><div class="smem-inline smem-inline--wide"><label class="smem-grow">' . esc_html__('Short description', 'sepo-membership') . '<input type="text" name="' . $n . '[blurb]" value="' . esc_attr($t['blurb']) . '"></label></div>'
            . '<label class="smem-block">' . esc_html__('Benefits, one per line', 'sepo-membership') . '<textarea name="' . $n . '[benefits]" rows="4">' . esc_textarea(implode("\n", $t['benefits'])) . '</textarea></label>'
            . '<p><label class="smem-check"><input type="checkbox" name="' . $n . '[featured]" value="1"' . checked($t['featured'], true, false) . '> ' . esc_html__('Highlight as most popular', 'sepo-membership') . '</label>'
            . '<label class="smem-check"><input type="checkbox" name="' . $n . '[hidden]" value="1"' . checked($t['hidden'], true, false) . '> ' . esc_html__('Hide from the pricing table (existing members keep it)', 'sepo-membership') . '</label>'
            . (!$bridge ? '<label class="smem-check smem-check--danger"><input type="checkbox" name="' . $n . '[remove]" value="1"> ' . esc_html__('Remove this plan', 'sepo-membership') . '</label>' : '')
            . '</p></fieldset>';
        return $h;
    }

    // ---------------------------------------------------------- settings

    public static function settings(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        $s = Settings::all();
        $allMethods = Settings::methods();
        $row = static function (string $label, string $field, string $help = ''): string {
            return '<tr><th scope="row">' . $label . '</th><td>' . $field . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
        };
        $inner = '<h2>' . esc_html__('Payment methods', 'sepo-membership') . '</h2>'
            . '<p class="smem-muted">' . esc_html__('People pay by these methods and then tell you. Every payment waits for you to confirm it before it opens anything. For a payment link, put the address of your payment page; {amount}, {currency} and {reference} in the address are filled in.', 'sepo-membership') . '</p>'
            . '<div class="smem-methods-admin" data-smem-repeat>';
        $i = 0;
        foreach (array_merge(array_values((array) $s['methods']), [['id' => '', 'label' => '', 'type' => 'manual', 'detail' => '', '__tpl' => true]]) as $m) {
            $tpl = !empty($m['__tpl']);
            $k = $tpl ? '__i__' : (string) $i++;
            $inner .= '<div class="smem-panelbox smem-methodrow"' . ($tpl ? ' data-smem-template hidden' : '') . '><div class="smem-inline">'
                . '<label>' . esc_html__('Name', 'sepo-membership') . '<input type="text" name="methods[' . $k . '][label]" value="' . esc_attr((string) ($m['label'] ?? '')) . '"' . ($tpl ? ' disabled' : '') . '></label>'
                . '<label>' . esc_html__('ID', 'sepo-membership') . '<input type="text" class="small-text" name="methods[' . $k . '][id]" value="' . esc_attr((string) ($m['id'] ?? '')) . '"' . ($tpl ? ' disabled' : '') . '></label>'
                . '<label>' . esc_html__('Kind', 'sepo-membership') . '<select name="methods[' . $k . '][type]"' . ($tpl ? ' disabled' : '') . '><option value="manual"' . selected($m['type'] ?? '', 'manual', false) . '>' . esc_html__('Pay, then tell us (bank, cash, mobile money)', 'sepo-membership') . '</option><option value="link"' . selected($m['type'] ?? '', 'link', false) . '>' . esc_html__('Payment link', 'sepo-membership') . '</option></select></label>'
                . '<button type="button" class="button-link button-link-delete" data-smem-remove-row>' . esc_html__('Remove', 'sepo-membership') . '</button></div>'
                . '<label class="smem-block">' . esc_html__('Details shown to the payer, or the payment link address', 'sepo-membership') . '<textarea rows="3" name="methods[' . $k . '][detail]"' . ($tpl ? ' disabled' : '') . '>' . esc_textarea((string) ($m['detail'] ?? '')) . '</textarea></label></div>';
        }
        $inner .= '<p><button type="button" class="button" data-smem-add-row>' . esc_html__('Add a method', 'sepo-membership') . '</button></p></div>';

        $checks = static function (string $name, array $selected) use ($allMethods): string {
            $h = '';
            foreach ($allMethods as $m) {
                $h .= '<label class="smem-check"><input type="checkbox" name="' . esc_attr($name) . '[]" value="' . esc_attr($m['id']) . '"' . checked(in_array($m['id'], $selected, true), true, false) . '> ' . esc_html($m['label']) . '</label> ';
            }
            return $h !== '' ? $h : '<span class="smem-muted">' . esc_html__('Save a method first.', 'sepo-membership') . '</span>';
        };

        $inner .= '<table class="form-table" role="presentation"><tbody>'
            . $row(esc_html__('Methods for membership', 'sepo-membership'), $checks('membership_methods', (array) $s['membership_methods']), __('Tick none to offer all of them.', 'sepo-membership'))
            . $row(esc_html__('Methods for gifts', 'sepo-membership'), $checks('donation_methods', (array) $s['donation_methods']), __('Tick none to offer all of them.', 'sepo-membership'))
            . $row('<label for="smem-proof">' . esc_html__('Proof of payment', 'sepo-membership') . '</label>', '<select id="smem-proof" name="proof"><option value="off"' . selected($s['proof'], 'off', false) . '>' . esc_html__('Do not ask', 'sepo-membership') . '</option><option value="optional"' . selected($s['proof'], 'optional', false) . '>' . esc_html__('Optional', 'sepo-membership') . '</option><option value="required"' . selected($s['proof'], 'required', false) . '>' . esc_html__('Required for pay-then-tell-us methods', 'sepo-membership') . '</option></select>')
            . $row('<label for="smem-mb">' . esc_html__('Largest proof file', 'sepo-membership') . '</label>', '<input id="smem-mb" type="number" class="small-text" min="1" max="50" name="proof_max_mb" value="' . (int) $s['proof_max_mb'] . '"> MB')
            . $row('<label for="smem-cur">' . esc_html__('Currency', 'sepo-membership') . '</label>', '<input id="smem-cur" type="text" class="small-text" maxlength="3" name="currency" value="' . esc_attr(Settings::currency()) . '">', __('Three letters, such as ZMW or USD.', 'sepo-membership'))
            . $row('<label for="smem-acc">' . esc_html__('Accent colour', 'sepo-membership') . '</label>', '<input id="smem-acc" type="text" class="small-text" name="accent" value="' . esc_attr((string) $s['accent']) . '" placeholder="#1f4e79">', __('Buttons and highlights. Leave empty for the default.', 'sepo-membership'))
            . '</tbody></table>';

        $inner .= '<h2>' . esc_html__('Giving and sponsors', 'sepo-membership') . '</h2><table class="form-table" role="presentation"><tbody>'
            . $row(esc_html__('Gifts from visitors', 'sepo-membership'), '<label class="smem-check"><input type="checkbox" name="guest_donations" value="1"' . checked((int) $s['guest_donations'], 1, false) . '> ' . esc_html__('Let people give without an account', 'sepo-membership') . '</label>')
            . $row('<label for="smem-amts">' . esc_html__('Suggested amounts', 'sepo-membership') . '</label>', '<input id="smem-amts" type="text" class="regular-text" name="donate_amounts" value="' . esc_attr((string) $s['donate_amounts']) . '">', __('Separated by commas.', 'sepo-membership'))
            . $row('<label for="smem-goal">' . esc_html__('General giving goal', 'sepo-membership') . '</label>', '<input id="smem-goal" type="number" step="any" min="0" name="donate_goal" value="' . esc_attr((string) $s['donate_goal']) . '">', __('Shown as progress on the donate page. 0 hides it. Projects have their own goals.', 'sepo-membership'))
            . $row('<label for="smem-lv">' . esc_html__('Sponsor levels', 'sepo-membership') . '</label>', '<textarea id="smem-lv" name="sponsor_levels" rows="5" class="large-text code">' . esc_textarea((string) $s['sponsor_levels']) . '</textarea>', __('One per line: id | Name | lowest total given. People listed from their gifts are placed by what they gave in total; sponsors added by hand take the level you choose.', 'sepo-membership'))
            . '</tbody></table>';

        $pages = '';
        foreach (Settings::PAGES as $key => [$title]) {
            $pages .= $row('<label for="smem-' . esc_attr($key) . '">' . esc_html($title) . '</label>', (string) wp_dropdown_pages(['name' => $key, 'id' => 'smem-' . $key, 'selected' => (int) $s[$key], 'show_option_none' => __('— None —', 'sepo-membership'), 'option_none_value' => '0', 'echo' => 0]));
        }
        $inner .= '<h2>' . esc_html__('Pages', 'sepo-membership') . '</h2><table class="form-table" role="presentation"><tbody>' . $pages . '</tbody></table>';

        $inner .= '<h2>' . esc_html__('Emails', 'sepo-membership') . '</h2><table class="form-table" role="presentation"><tbody>'
            . $row(esc_html__('New payments', 'sepo-membership'), '<label class="smem-check"><input type="checkbox" name="notify_payments" value="1"' . checked((int) $s['notify_payments'], 1, false) . '> ' . esc_html__('Email me when a payment needs confirming', 'sepo-membership') . '</label>')
            . $row('<label for="smem-ne">' . esc_html__('Send them to', 'sepo-membership') . '</label>', '<input id="smem-ne" type="email" class="regular-text" name="notify_email" value="' . esc_attr((string) $s['notify_email']) . '" placeholder="' . esc_attr((string) get_option('admin_email')) . '">')
            . $row('<label for="smem-rd">' . esc_html__('Renewal reminder', 'sepo-membership') . '</label>', '<input id="smem-rd" type="number" class="small-text" min="0" max="60" name="remind_days" value="' . (int) $s['remind_days'] . '"> ' . esc_html__('days before a plan runs out', 'sepo-membership'), __('0 sends no reminder.', 'sepo-membership'))
            . '</tbody></table>';

        $inner .= '<h2>' . esc_html__('Removing the plugin', 'sepo-membership') . '</h2><table class="form-table" role="presentation"><tbody>'
            . $row(esc_html__('Data', 'sepo-membership'), '<label class="smem-check"><input type="checkbox" name="delete_on_uninstall" value="1"' . checked((int) $s['delete_on_uninstall'], 1, false) . '> ' . esc_html__('Delete members, payments, board posts, files and settings when the plugin is deleted', 'sepo-membership') . '</label>')
            . '</tbody></table>';
        $inner .= '<p><button class="button button-primary">' . esc_html__('Save settings', 'sepo-membership') . '</button></p>';

        echo '<div class="wrap smem-admin"><h1>' . esc_html__('Membership settings', 'sepo-membership') . '</h1>' . self::form('settings', $inner, 'smem-settings') . '</div>';
    }
}
