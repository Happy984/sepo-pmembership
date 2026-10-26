<?php

declare(strict_types=1);

namespace SepoMembership\Render;

use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/** The pricing table, with a period switch and a join dialog. */
final class PlansView
{
    /** @param array<string,string> $atts tiers, period, title, intro */
    public static function render(array $atts): string
    {
        $tiers = Tiers::joinable();
        if (!empty($atts['tiers'])) {
            $only = array_filter(array_map('sanitize_key', explode(',', (string) $atts['tiers'])));
            $tiers = array_intersect_key($tiers, array_flip($only));
        }
        if ($tiers === []) {
            return Parts::frame(Parts::notice(esc_html__('Membership plans are not open yet. Please check back soon.', 'sepo-membership')), 'smem-plans');
        }

        $periods = [];
        foreach (Tiers::PERIODS as $p) {
            foreach ($tiers as $t) {
                if (isset($t['prices'][$p])) {
                    $periods[] = $p;
                    break;
                }
            }
        }
        $want = sanitize_key((string) ($_GET['smem_period'] ?? ($atts['period'] ?? '')));
        $period = in_array($want, $periods, true) ? $want : (in_array('monthly', $periods, true) ? 'monthly' : $periods[0]);

        $uid = get_current_user_id();
        $status = $uid > 0 ? Members::status($uid) : 'none';
        $m = $uid > 0 ? Members::get($uid) : null;
        $current = $status === 'active' ? (string) $m['level'] : '';
        $scheduled = $status === 'active' ? (string) ($m['pending_level'] ?? '') : '';

        $h = '';
        $title = (string) ($atts['title'] ?? '');
        $intro = (string) ($atts['intro'] ?? '');
        if ($title !== '' || $intro !== '') {
            $h .= '<header class="smem-head smem-head--center">'
                . ($title !== '' ? '<h2 class="smem-title">' . esc_html($title) . '</h2>' : '')
                . ($intro !== '' ? '<p class="smem-lede">' . esc_html($intro) . '</p>' : '')
                . '</header>';
        }

        if ($uid > 0) {
            $waiting = Payments::query(['user_id' => $uid, 'kind' => 'membership', 'status' => Payments::OPEN, 'per_page' => 1])['rows'];
            if ($waiting !== []) {
                $acc = Settings::pageUrl('account_page');
                $h .= Parts::notice(
                    esc_html(sprintf(
                        /* translators: 1: amount, 2: plan */
                        __('Your payment of %1$s for %2$s is waiting for confirmation. Your plan opens as soon as it is confirmed.', 'sepo-membership'),
                        Settings::money((float) $waiting[0]['amount'], (string) $waiting[0]['currency']),
                        Tiers::label((string) $waiting[0]['level'])
                    )) . ($acc !== '' ? ' <a href="' . esc_url($acc) . '">' . esc_html__('View or add proof', 'sepo-membership') . '</a>' : ''),
                    'warn',
                    'clock'
                );
            }
        }

        if (count($periods) > 1) {
            $saving = self::saving($tiers);
            $h .= '<div class="smem-periods" role="radiogroup" aria-label="' . esc_attr__('Billing period', 'sepo-membership') . '">';
            foreach ($periods as $p) {
                $h .= '<button type="button" class="smem-periods__opt" role="radio" aria-checked="' . ($p === $period ? 'true' : 'false') . '" data-period="' . esc_attr($p) . '">'
                    . esc_html(self::periodShort($p))
                    . ($p === 'annual' && $saving > 0 ? ' <span class="smem-periods__save">' . esc_html(sprintf(/* translators: %d: percent */ __('Save %d%%', 'sepo-membership'), $saving)) . '</span>' : '')
                    . '</button>';
            }
            $h .= '</div>';
        }

        $h .= '<div class="smem-plans__grid" style="--smem-cols:' . min(4, count($tiers)) . '">';
        foreach ($tiers as $t) {
            $h .= self::card($t, $period, $uid, $current, $scheduled);
        }
        $h .= '</div>';
        $h .= '<p class="smem-plans__foot">' . esc_html__('Pay by the methods shown when you join. Your plan opens as soon as we confirm your payment. You can cancel at any time and keep your access until the end of the time you paid for.', 'sepo-membership') . '</p>';
        $h .= self::payTemplate();

        return Parts::frame($h, 'smem-plans alignwide', ['smem-view' => 'plans', 'period' => $period]);
    }

    private static function periodShort(string $p): string
    {
        return match ($p) {
            'monthly'   => __('Monthly', 'sepo-membership'),
            'quarterly' => __('Quarterly', 'sepo-membership'),
            'annual'    => __('Yearly', 'sepo-membership'),
            default     => __('One-time', 'sepo-membership'),
        };
    }

    /** The best saving from paying yearly instead of monthly, in percent. */
    private static function saving(array $tiers): int
    {
        $best = 0;
        foreach ($tiers as $t) {
            if (!empty($t['prices']['monthly']) && isset($t['prices']['annual'])) {
                $best = max($best, (int) floor((1 - $t['prices']['annual'] / ($t['prices']['monthly'] * 12)) * 100));
            }
        }
        return $best;
    }

    /** @param array<string,mixed> $t */
    private static function card(array $t, string $period, int $uid, string $current, string $scheduled): string
    {
        $prices = [];
        foreach ($t['prices'] as $p => $amount) {
            $prices[$p] = ['money' => $amount > 0 ? Settings::money((float) $amount) : __('Free', 'sepo-membership'), 'per' => $amount > 0 ? Tiers::per($p) : '', 'amount' => $amount];
        }
        $has = isset($prices[$period]);
        $isCurrent = $current !== '' && $current === $t['id'];
        $cls = 'smem-plan' . ($t['featured'] ? ' smem-plan--featured' : '') . ($isCurrent ? ' smem-plan--current' : '');

        $h = '<article class="' . esc_attr($cls) . '" data-plan="' . esc_attr($t['id']) . '" data-label="' . esc_attr($t['label']) . '" data-prices="' . esc_attr((string) wp_json_encode($prices)) . '">';
        if ($isCurrent) {
            $h .= '<span class="smem-plan__badge smem-plan__badge--current">' . esc_html__('Your plan', 'sepo-membership') . '</span>';
        } elseif ($t['featured']) {
            $h .= '<span class="smem-plan__badge">' . esc_html__('Most popular', 'sepo-membership') . '</span>';
        }
        $h .= '<h3 class="smem-plan__name">' . esc_html($t['label']) . '</h3>';
        if ($t['blurb'] !== '') {
            $h .= '<p class="smem-plan__blurb">' . esc_html($t['blurb']) . '</p>';
        }
        $h .= '<div class="smem-plan__price" aria-live="polite"><span class="smem-plan__amount" data-amount>' . esc_html($has ? $prices[$period]['money'] : '—') . '</span>'
            . '<span class="smem-plan__per" data-per>' . esc_html($has ? $prices[$period]['per'] : '') . '</span></div>';
        $h .= '<p class="smem-plan__na" data-na' . ($has ? ' hidden' : '') . '>' . esc_html__('Not offered for this period', 'sepo-membership') . '</p>';

        $h .= '<div class="smem-plan__action">' . self::button($t, $uid, $current, $scheduled, $has) . '</div>';

        if ($t['benefits'] !== []) {
            $h .= '<ul class="smem-plan__benefits">';
            foreach ($t['benefits'] as $b) {
                $h .= '<li>' . Icons::get('check', 16) . '<span>' . esc_html($b) . '</span></li>';
            }
            $h .= '</ul>';
        }
        return $h . '</article>';
    }

    /** @param array<string,mixed> $t */
    private static function button(array $t, int $uid, string $current, string $scheduled, bool $has): string
    {
        $primary = $t['featured'] ? 'smem-btn--primary' : 'smem-btn--outline';
        if ($uid < 1) {
            $back = add_query_arg(['smem_join' => $t['id']], Parts::here());
            return '<a class="smem-btn smem-btn--block ' . $primary . '" href="' . esc_url(Parts::login($back)) . '" data-join-guest>' . esc_html__('Sign in to join', 'sepo-membership') . '</a>';
        }
        $attrs = ' data-join="' . esc_attr($t['id']) . '"' . ($has ? '' : ' disabled');
        if ($current === '') {
            return '<button type="button" class="smem-btn smem-btn--block ' . $primary . '"' . $attrs . '>' . esc_html__('Join', 'sepo-membership') . '</button>';
        }
        if ($current === $t['id']) {
            return '<button type="button" class="smem-btn smem-btn--block smem-btn--outline"' . $attrs . ' data-renew="1">' . esc_html__('Renew', 'sepo-membership') . '</button>';
        }
        if ($scheduled === $t['id']) {
            return '<button type="button" class="smem-btn smem-btn--block smem-btn--quiet" disabled>' . esc_html__('Starts at your next renewal', 'sepo-membership') . '</button>';
        }
        $up = Tiers::rank($t['id']) > Tiers::rank($current);
        return '<button type="button" class="smem-btn smem-btn--block ' . ($up ? 'smem-btn--primary' : 'smem-btn--outline') . '"' . $attrs . ($up ? ' data-upgrade="1"' : ' data-downgrade="1"') . '>'
            . esc_html($up ? __('Upgrade', 'sepo-membership') : __('Switch at renewal', 'sepo-membership')) . '</button>';
    }

    /** The payment form the join and renew dialogs are built from. */
    public static function payTemplate(): string
    {
        if (!is_user_logged_in()) {
            return '';
        }
        return '<template data-smem-payform><form class="smem-form smem-payform" novalidate>'
            . '<input type="hidden" name="level"><input type="hidden" name="period"><input type="hidden" name="renew">'
            . '<div class="smem-summary" data-pay-summary></div>'
            . Parts::payFields('membership', true, __('Show me on the sponsors page as a supporting member', 'sepo-membership'))
            . '</form></template>';
    }
}
