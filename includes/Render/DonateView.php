<?php

declare(strict_types=1);

namespace SepoMembership\Render;

use SepoMembership\Payments;
use SepoMembership\Projects;
use SepoMembership\Settings;

defined('ABSPATH') || exit;

/** The giving form, for general donations or one project, with its progress. */
final class DonateView
{
    /** @param array<string,string> $atts project, amounts, title, intro, progress */
    public static function render(array $atts): string
    {
        $projectId = (int) ($atts['project'] ?? 0);
        $project = $projectId > 0 ? Projects::get($projectId) : null;
        if ($projectId > 0 && ($project === null || $project['status'] !== 'publish')) {
            return '';
        }
        $h = '';
        $title = (string) ($atts['title'] ?? '');
        $intro = (string) ($atts['intro'] ?? '');
        if ($title !== '' || $intro !== '') {
            $h .= '<header class="smem-head">' . ($title !== '' ? '<h2 class="smem-title">' . esc_html($title) . '</h2>' : '')
                . ($intro !== '' ? '<p class="smem-lede">' . esc_html($intro) . '</p>' : '') . '</header>';
        }
        $showProgress = ($atts['progress'] ?? 'yes') !== 'no';
        if ($project !== null && $showProgress) {
            $h .= self::progressCard($project);
        } elseif ($project === null && $showProgress && (float) Settings::get('donate_goal') > 0) {
            $goal = (float) Settings::get('donate_goal');
            $raised = Payments::raised(0);
            $h .= self::progressCard(['goal' => $goal, 'raised' => $raised, 'percent' => (int) min(100, floor($raised / $goal * 100)), 'backers' => Payments::backers(0), 'deadline_ts' => 0, 'closed' => false]);
        }
        if ($project !== null && $project['closed']) {
            $h .= Parts::notice(esc_html__('This project has closed and is no longer taking gifts. Thank you to everyone who gave.', 'sepo-membership'), 'good', 'heart');
            return Parts::frame($h, 'smem-donate');
        }
        $h .= self::form($project, (string) ($atts['amounts'] ?? ''));
        return Parts::frame($h, 'smem-donate', ['smem-view' => 'donate']);
    }

    /** @param array<string,mixed> $p */
    public static function progressCard(array $p): string
    {
        $h = '<div class="smem-card smem-goal">';
        $h .= '<div class="smem-goal__nums"><span class="smem-goal__raised">' . esc_html(Settings::money((float) $p['raised'])) . '</span>';
        if ($p['goal'] > 0) {
            /* translators: %s: goal amount */
            $h .= '<span class="smem-goal__of">' . esc_html(sprintf(__('raised of %s', 'sepo-membership'), Settings::money((float) $p['goal']))) . '</span>';
        } else {
            $h .= '<span class="smem-goal__of">' . esc_html__('raised so far', 'sepo-membership') . '</span>';
        }
        $h .= '</div>';
        if ($p['goal'] > 0) {
            $h .= Parts::progress((int) $p['percent'], __('Progress towards the goal', 'sepo-membership'));
        }
        $meta = [];
        if ($p['goal'] > 0) {
            /* translators: %d: percent */
            $meta[] = sprintf(__('%d%% of the goal', 'sepo-membership'), (int) $p['percent']);
        }
        /* translators: %s: number of people */
        $meta[] = sprintf(_n('%s supporter', '%s supporters', (int) $p['backers'], 'sepo-membership'), number_format_i18n((int) $p['backers']));
        if ((int) $p['deadline_ts'] > 0 && !$p['closed']) {
            $days = (int) max(0, ceil(((int) $p['deadline_ts'] - time()) / DAY_IN_SECONDS));
            /* translators: %d: days */
            $meta[] = sprintf(_n('%d day left', '%d days left', $days, 'sepo-membership'), $days);
        }
        $h .= '<p class="smem-goal__meta">' . esc_html(implode(' · ', $meta)) . '</p>';
        return $h . '</div>';
    }

    /** @param array<string,mixed>|null $project */
    private static function form(?array $project, string $amounts): string
    {
        $uid = get_current_user_id();
        if ($uid < 1 && (int) Settings::get('guest_donations') !== 1) {
            return '<div class="smem-gate smem-gate--compact">' . Icons::get('heart', 24)
                . '<p class="smem-gate__text">' . esc_html__('Please sign in to give. It lets us send your receipt and thank you properly.', 'sepo-membership') . '</p>'
                . '<a class="smem-btn smem-btn--primary" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-membership') . '</a></div>';
        }
        $list = [];
        foreach (preg_split('~[,\s]+~', $amounts) ?: [] as $v) {
            if ((float) $v > 0) {
                $list[] = (float) $v;
            }
        }
        $list = $list !== [] ? array_slice(array_unique($list), 0, 8) : Settings::donateAmounts();
        $pick = $list[(int) floor((count($list) - 1) / 2)] ?? 0;
        $id = 'smem-give-' . wp_rand(1000, 99999);

        $h = '<form class="smem-form smem-card smem-giveform" data-smem-give novalidate>';
        if ($project !== null) {
            $h .= '<input type="hidden" name="project" value="' . (int) $project['id'] . '">';
        }
        $h .= '<fieldset class="smem-freq"><legend class="screen-reader-text">' . esc_html__('How often', 'sepo-membership') . '</legend>'
            . '<label class="smem-seg"><input type="radio" name="recurring" value="0" checked><span>' . esc_html__('Give once', 'sepo-membership') . '</span></label>'
            . '<label class="smem-seg"><input type="radio" name="recurring" value="1"><span>' . esc_html__('Monthly', 'sepo-membership') . '</span></label></fieldset>';

        $h .= '<fieldset class="smem-amounts"><legend class="smem-label">' . esc_html__('Amount', 'sepo-membership') . ' <span class="smem-optional">' . esc_html(Settings::currency()) . '</span></legend><div class="smem-chips">';
        foreach ($list as $a) {
            $h .= '<label class="smem-chip"><input type="radio" name="preset" value="' . esc_attr((string) $a) . '"' . checked($a, $pick, false) . '><span>' . esc_html(Settings::money($a)) . '</span></label>';
        }
        $h .= '<label class="smem-chip smem-chip--other"><input type="radio" name="preset" value="other"><span>' . esc_html__('Other', 'sepo-membership') . '</span></label></div>'
            . '<div class="smem-field smem-other" data-other hidden><label class="smem-label" for="' . esc_attr($id) . '-amt">' . esc_html__('Your amount', 'sepo-membership') . '</label>'
            . '<div class="smem-affix"><span class="smem-affix__pre">' . esc_html(Settings::currency()) . '</span><input class="smem-input" id="' . esc_attr($id) . '-amt" type="number" min="1" step="any" inputmode="decimal" data-other-input></div></div>'
            . '<input type="hidden" name="amount" value="' . esc_attr((string) $pick) . '"></fieldset>';
        $h .= '<p class="smem-help smem-help--monthly" data-monthly-help hidden>' . esc_html__('We will email you a reminder each month. Nothing is taken automatically.', 'sepo-membership') . '</p>';

        if ($uid < 1) {
            $h .= '<div class="smem-row">'
                . '<div class="smem-field"><label class="smem-label" for="' . esc_attr($id) . '-name">' . esc_html__('Your name', 'sepo-membership') . '</label><input class="smem-input" id="' . esc_attr($id) . '-name" name="name" type="text" autocomplete="name" required maxlength="190"></div>'
                . '<div class="smem-field"><label class="smem-label" for="' . esc_attr($id) . '-email">' . esc_html__('Email', 'sepo-membership') . '</label><input class="smem-input" id="' . esc_attr($id) . '-email" name="email" type="email" autocomplete="email" required maxlength="190"><span class="smem-help">' . esc_html__('For your receipt. Never shown.', 'sepo-membership') . '</span></div>'
                . '</div>';
        }
        $h .= Parts::payFields('donation', false);
        $h .= '<div class="smem-field"><label class="smem-label" for="' . esc_attr($id) . '-msg">' . esc_html__('Message', 'sepo-membership') . ' <span class="smem-optional">' . esc_html__('optional', 'sepo-membership') . '</span></label>'
            . '<textarea class="smem-input smem-textarea" id="' . esc_attr($id) . '-msg" name="message" rows="3" maxlength="1000"></textarea></div>';
        $h .= '<label class="smem-check"><input type="checkbox" name="listed" value="1" checked><span>' . esc_html__('Show my name on the sponsors page', 'sepo-membership') . '</span></label>';
        $h .= '<div class="smem-hp" aria-hidden="true"><label>' . esc_html__('Leave this empty', 'sepo-membership') . '<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>';
        $h .= '<div class="smem-form__error" data-form-error hidden></div>';
        $h .= '<button type="submit" class="smem-btn smem-btn--primary smem-btn--lg smem-btn--block" data-give-submit>' . Icons::get('heart')
            . '<span data-give-label>' . esc_html(sprintf(/* translators: %s: amount */ __('Give %s', 'sepo-membership'), Settings::money((float) $pick))) . '</span></button>';
        $h .= '<p class="smem-help smem-center">' . esc_html__('We confirm every gift by hand and email you when it has arrived.', 'sepo-membership') . '</p>';
        return $h . '</form>';
    }
}
