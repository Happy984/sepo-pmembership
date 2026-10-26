<?php

declare(strict_types=1);

namespace SepoMembership\Render;

use SepoMembership\Log;
use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/** The member's own page: their plan, payments waiting, history and choices. */
final class AccountView
{
    public static function render(): string
    {
        $uid = get_current_user_id();
        if ($uid < 1) {
            $reg = Parts::register();
            return Parts::frame('<div class="smem-gate">' . Icons::get('lock', 28)
                . '<h2 class="smem-gate__title">' . esc_html__('Sign in to see your membership', 'sepo-membership') . '</h2>'
                . '<p class="smem-gate__text">' . esc_html__('Your plan, payments and receipts are here once you sign in.', 'sepo-membership') . '</p>'
                . '<div class="smem-gate__actions"><a class="smem-btn smem-btn--primary" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-membership') . '</a>'
                . ($reg !== '' ? '<a class="smem-btn smem-btn--outline" href="' . esc_url($reg) . '">' . esc_html__('Create an account', 'sepo-membership') . '</a>' : '')
                . '</div></div>', 'smem-account');
        }

        $m = Members::get($uid);
        $status = Members::status($uid);
        $plans = Settings::pageUrl('plans_page');
        $h = '';

        if ($m === null) {
            $h .= '<section class="smem-card smem-standing smem-standing--none">'
                . '<div class="smem-standing__main"><span class="smem-badge">' . esc_html__('Not a member yet', 'sepo-membership') . '</span>'
                . '<p class="smem-standing__text">' . esc_html__('Choose a plan to support us and open the members area.', 'sepo-membership') . '</p></div>'
                . ($plans !== '' ? '<a class="smem-btn smem-btn--primary" href="' . esc_url($plans) . '">' . esc_html__('See plans', 'sepo-membership') . '</a>' : '')
                . '</section>';
        } else {
            $h .= self::standing($uid, $m, $status, $plans);
        }

        $waiting = Payments::query(['user_id' => $uid, 'status' => Payments::OPEN, 'per_page' => 20])['rows'];
        if ($waiting !== []) {
            $h .= '<section class="smem-section"><h3 class="smem-section__title">' . esc_html__('Waiting for confirmation', 'sepo-membership') . '</h3><div class="smem-waiting">';
            foreach ($waiting as $p) {
                $h .= self::waitingRow($p);
            }
            $h .= '</div></section>';
        }

        $history = Payments::query(['user_id' => $uid, 'status' => ['paid', 'rejected', 'withdrawn'], 'per_page' => 50])['rows'];
        $h .= '<section class="smem-section"><h3 class="smem-section__title">' . esc_html__('Payment history', 'sepo-membership') . '</h3>';
        if ($history === []) {
            $h .= '<p class="smem-empty">' . esc_html__('No confirmed payments yet.', 'sepo-membership') . '</p>';
        } else {
            $h .= '<div class="smem-tablewrap"><table class="smem-table"><thead><tr>'
                . '<th scope="col">' . esc_html__('Date', 'sepo-membership') . '</th>'
                . '<th scope="col">' . esc_html__('For', 'sepo-membership') . '</th>'
                . '<th scope="col">' . esc_html__('Method', 'sepo-membership') . '</th>'
                . '<th scope="col" class="smem-num">' . esc_html__('Amount', 'sepo-membership') . '</th>'
                . '<th scope="col">' . esc_html__('Status', 'sepo-membership') . '</th></tr></thead><tbody>';
            foreach ($history as $p) {
                $h .= '<tr><td>' . esc_html(Parts::date($p['confirmed_ts'] ?: $p['created_ts'])) . '</td>'
                    . '<td>' . esc_html(self::what($p)) . '</td>'
                    . '<td>' . esc_html(Payments::methodLabel((string) $p['method'])) . '</td>'
                    . '<td class="smem-num">' . esc_html(Settings::money((float) $p['amount'], (string) $p['currency'])) . '</td>'
                    . '<td><span class="smem-badge smem-badge--' . (['paid' => 'good', 'rejected' => 'bad'][$p['status']] ?? '') . '">' . esc_html(Payments::statusLabel((string) $p['status'])) . '</span>'
                    . ($p['status'] !== 'paid' && (string) $p['admin_note'] !== '' ? '<span class="smem-table__note">' . esc_html((string) $p['admin_note']) . '</span>' : '')
                    . '</td></tr>';
            }
            $h .= '</tbody></table></div>';
        }
        $h .= '</section>';

        $h .= '<section class="smem-section"><h3 class="smem-section__title">' . esc_html__('Your choices', 'sepo-membership') . '</h3><div class="smem-card smem-prefs">';
        if ($m !== null) {
            $h .= self::toggle('listing', 'listed', (bool) $m['listed'], __('Show me on the sponsors page', 'sepo-membership'), __('Your display name and plan, while your plan is active.', 'sepo-membership'));
        }
        $h .= self::toggle('preferences', 'board_mail', (int) get_user_meta($uid, '_smem_board_mail_off', true) !== 1, __('Email me when someone replies to my posts', 'sepo-membership'), __('For discussions and suggestions in the members area.', 'sepo-membership'));
        $h .= '</div></section>';

        $h .= PlansView::payTemplate();
        return Parts::frame($h, 'smem-account', ['smem-view' => 'account']);
    }

    /** @param array<string,mixed> $m */
    private static function standing(int $uid, array $m, string $status, string $plans): string
    {
        $label = Tiers::label((string) $m['level']);
        $ending = Members::isEnding($uid);
        $badge = match (true) {
            $ending              => ['warn', __('Cancelled — access until it ends', 'sepo-membership')],
            $status === 'active' => ['good', __('Active', 'sepo-membership')],
            $status === 'pending' => ['warn', __('Waiting for payment confirmation', 'sepo-membership')],
            $status === 'expired' => ['bad', __('Expired', 'sepo-membership')],
            default              => ['bad', __('Ended', 'sepo-membership')],
        };
        $h = '<section class="smem-card smem-standing smem-standing--' . esc_attr($status) . '">';
        $h .= '<div class="smem-standing__main">'
            . '<span class="smem-badge smem-badge--' . esc_attr($badge[0]) . '">' . esc_html($badge[1]) . '</span>'
            . '<h3 class="smem-standing__plan">' . esc_html($label) . '</h3>'
            . '<p class="smem-standing__period">' . esc_html(Tiers::periodLabel((string) $m['period'])) . '</p></div>';

        $facts = [];
        if ($m['started_ts'] > 0) {
            $facts[] = [__('Member since', 'sepo-membership'), Parts::date($m['started_ts'])];
        }
        if ($status === 'active') {
            if ($m['expires_ts'] > 0) {
                $facts[] = [$ending ? __('Access ends', 'sepo-membership') : __('Renew by', 'sepo-membership'), Parts::date($m['expires_ts']) . ' · ' . Parts::ago($m['expires_ts'])];
            } else {
                $facts[] = [__('Runs until', 'sepo-membership'), __('No end date', 'sepo-membership')];
            }
        } elseif ($m['expires_ts'] > 0 && $status !== 'pending') {
            $facts[] = [__('Ended', 'sepo-membership'), Parts::date($m['expires_ts'])];
        }
        if ((float) $m['total'] > 0) {
            $facts[] = [__('Paid in total', 'sepo-membership'), Settings::money((float) $m['total'], (string) $m['currency'])];
        }
        if ($facts !== []) {
            $h .= '<dl class="smem-facts">';
            foreach ($facts as [$k, $v]) {
                $h .= '<div><dt>' . esc_html($k) . '</dt><dd>' . esc_html($v) . '</dd></div>';
            }
            $h .= '</dl>';
        }

        if ($status === 'active' && (string) $m['pending_level'] !== '') {
            $h .= Parts::notice(esc_html(sprintf(
                /* translators: 1: new plan, 2: date */
                __('You are moving to %1$s. It starts with your next payment, after %2$s.', 'sepo-membership'),
                Tiers::label((string) $m['pending_level']),
                $m['expires_ts'] > 0 ? Parts::date($m['expires_ts']) : __('your current plan', 'sepo-membership')
            )), 'info', 'calendar');
        }

        $h .= '<div class="smem-standing__actions">';
        // A renewal pays for the plan the member is moving to, when a change is scheduled.
        $next = (string) $m['pending_level'] !== '' && Tiers::price((string) $m['pending_level'], (string) $m['period']) !== null ? (string) $m['pending_level'] : (string) $m['level'];
        $renewable = Tiers::price($next, (string) $m['period']) !== null && (float) Tiers::price($next, (string) $m['period']) > 0;
        if ($status === 'active' && !$ending && $renewable && $m['expires_ts'] > 0) {
            $h .= '<button type="button" class="smem-btn smem-btn--primary" data-join="' . esc_attr($next) . '" data-period="' . esc_attr((string) $m['period']) . '" data-renew="1" data-label="' . esc_attr(Tiers::label($next)) . '">' . esc_html__('Renew now', 'sepo-membership') . '</button>';
        }
        if (in_array($status, ['expired', 'cancelled'], true) && $renewable) {
            $h .= '<button type="button" class="smem-btn smem-btn--primary" data-join="' . esc_attr((string) $m['level']) . '" data-period="' . esc_attr((string) $m['period']) . '" data-label="' . esc_attr($label) . '">' . esc_html__('Renew my plan', 'sepo-membership') . '</button>';
        }
        if ($plans !== '') {
            $h .= '<a class="smem-btn smem-btn--outline" href="' . esc_url($plans) . '">' . esc_html($status === 'active' ? __('Change plan', 'sepo-membership') : __('See all plans', 'sepo-membership')) . '</a>';
        }
        $area = Settings::pageUrl('area_page');
        if ($status === 'active' && $area !== '') {
            $h .= '<a class="smem-btn smem-btn--outline" href="' . esc_url($area) . '">' . esc_html__('Members area', 'sepo-membership') . '</a>';
        }
        if ($ending) {
            $h .= '<button type="button" class="smem-btn smem-btn--quiet" data-smem-resume>' . esc_html__('Keep my plan', 'sepo-membership') . '</button>';
        } elseif ($status === 'active' && $m['expires_ts'] > 0) {
            $h .= '<button type="button" class="smem-btn smem-btn--quiet smem-btn--danger-text" data-smem-cancel data-until="' . esc_attr(Parts::date($m['expires_ts'])) . '">' . esc_html__('Cancel plan', 'sepo-membership') . '</button>';
        }
        $h .= '</div></section>';
        return $h;
    }

    /** @param array<string,mixed> $p */
    private static function waitingRow(array $p): string
    {
        $proofs = count($p['proofs']);
        $h = '<div class="smem-card smem-wait smem-wait--' . esc_attr((string) $p['status']) . '" data-payment="' . (int) $p['id'] . '">'
            . '<div class="smem-wait__main"><strong class="smem-wait__amount">' . esc_html(Settings::money((float) $p['amount'], (string) $p['currency'])) . '</strong>'
            . '<span class="smem-wait__what">' . esc_html(self::what($p)) . '</span>'
            . '<span class="smem-wait__meta">' . esc_html(sprintf(
                /* translators: 1: payment method, 2: time ago */
                __('%1$s · sent %2$s', 'sepo-membership'),
                Payments::methodLabel((string) $p['method']),
                Parts::ago($p['created_ts'])
            )) . ((string) $p['reference'] !== '' ? ' · ' . esc_html__('Ref.', 'sepo-membership') . ' ' . esc_html((string) $p['reference']) : '') . '</span>'
            . '<span class="smem-wait__proofs" data-proof-count>' . esc_html(sprintf(
                /* translators: %d: number of files */
                _n('%d proof file attached', '%d proof files attached', $proofs, 'sepo-membership'),
                $proofs
            )) . '</span></div><div class="smem-wait__actions">';
        if ((string) Settings::get('proof') !== 'off' && $proofs < 5) {
            $id = 'smem-proof-' . (int) $p['id'];
            $h .= '<label class="smem-btn smem-btn--sm smem-btn--outline" for="' . esc_attr($id) . '">' . Icons::get('upload', 14) . esc_html__('Add proof', 'sepo-membership') . '</label>'
                . '<input class="smem-file smem-file--hidden" id="' . esc_attr($id) . '" type="file" multiple data-smem-proof="' . (int) $p['id'] . '">';
        }
        if ($p['status'] === 'withdrawal') {
            $h .= '<button type="button" class="smem-btn smem-btn--sm smem-btn--quiet" data-smem-keep="' . (int) $p['id'] . '">' . esc_html__('Keep this payment', 'sepo-membership') . '</button>';
        } else {
            $h .= '<button type="button" class="smem-btn smem-btn--sm smem-btn--quiet" data-smem-withdraw="' . (int) $p['id'] . '">' . esc_html__('Ask to withdraw', 'sepo-membership') . '</button>';
        }
        $h .= '</div>';
        if ($p['status'] === 'withdrawal') {
            $h .= '<div class="smem-wait__request"><span class="smem-badge smem-badge--info">' . esc_html__('Withdrawal requested, waiting for review', 'sepo-membership') . '</span>'
                . '<p><strong>' . esc_html__('Your reason:', 'sepo-membership') . '</strong> ' . esc_html((string) $p['withdraw_reason']) . '</p></div>';
        } elseif ((string) $p['admin_note'] !== '') {
            $h .= '<div class="smem-wait__request"><p><strong>' . esc_html__('Note from us:', 'sepo-membership') . '</strong> ' . esc_html((string) $p['admin_note']) . '</p></div>';
        }
        $h .= self::trail((int) $p['id']);
        return $h . '</div>';
    }

    /** What has happened to a payment so far, for the payer. */
    private static function trail(int $paymentId): string
    {
        $rows = Log::forPayment($paymentId);
        if ($rows === []) {
            return '';
        }
        $h = '<details class="smem-trail"><summary>' . esc_html__('What has happened so far', 'sepo-membership') . '</summary><ol>';
        foreach ($rows as $r) {
            $h .= '<li><span>' . esc_html($r['label']) . '</span><time datetime="' . esc_attr(gmdate('c', $r['created_ts'])) . '">' . esc_html(wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $r['created_ts'])) . '</time></li>';
        }
        return $h . '</ol></details>';
    }

    /** @param array<string,mixed> $p */
    private static function what(array $p): string
    {
        if ($p['kind'] === 'membership') {
            return sprintf(
                /* translators: 1: plan, 2: period */
                __('%1$s membership, %2$s', 'sepo-membership'),
                Tiers::label((string) $p['level']),
                mb_strtolower(Tiers::periodLabel((string) $p['period']))
            );
        }
        if ($p['project_id'] > 0) {
            /* translators: %s: project */
            return sprintf(__('Sponsoring %s', 'sepo-membership'), get_the_title((int) $p['project_id']));
        }
        return $p['recurring'] ? __('Monthly gift', 'sepo-membership') : __('Gift', 'sepo-membership');
    }

    private static function toggle(string $route, string $field, bool $on, string $label, string $help): string
    {
        $id = 'smem-t-' . $field;
        return '<div class="smem-toggle"><input type="checkbox" class="smem-switch" id="' . esc_attr($id) . '" data-smem-pref="' . esc_attr($route) . '" name="' . esc_attr($field) . '"' . checked($on, true, false) . '>'
            . '<label for="' . esc_attr($id) . '"><span class="smem-toggle__label">' . esc_html($label) . '</span><span class="smem-help">' . esc_html($help) . '</span></label></div>';
    }
}
