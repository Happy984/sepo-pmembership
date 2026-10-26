<?php

declare(strict_types=1);

namespace SepoMembership\Render;

use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Plugin;
use SepoMembership\Resources;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/**
 * The members area. Anyone without access gets a panel that says why and what
 * to do; nothing behind it is written into their page. Members see the
 * sections their plan includes, and what a higher plan would add, marked as
 * locked.
 */
final class AreaView
{
    /** @param array<string,string> $cfg normalised area settings */
    public static function render(array $cfg, int $pageId): string
    {
        $uid = get_current_user_id();
        $access = Resources::access($uid, $cfg['tier']);
        if ($access !== 'ok') {
            return Parts::frame(self::gate($access, $uid, $cfg['tier']), 'smem-area');
        }

        $sections = [];
        foreach (Resources::SECTIONS as $s) {
            if (Resources::shows($cfg, $s)) {
                $sections[] = $s;
            }
        }
        $tab = sanitize_key((string) ($_GET['smem_tab'] ?? ''));
        $tab = in_array($tab, $sections, true) ? $tab : ($sections[0] ?? 'downloads');

        $h = self::standing($uid, $cfg);
        $labels = [
            'downloads'   => [__('Downloads', 'sepo-membership'), 'download'],
            'links'       => [__('Links', 'sepo-membership'), 'link'],
            'discussions' => [__('Discussions', 'sepo-membership'), 'chat'],
            'suggestions' => [__('Suggestions', 'sepo-membership'), 'bulb'],
        ];
        $downloads = in_array('downloads', $sections, true) ? Resources::forArea($cfg, 'download') : [];
        $links = in_array('links', $sections, true) ? Resources::forArea($cfg, 'link') : [];

        if (count($sections) > 1) {
            $h .= '<div class="smem-tabs" role="tablist" aria-label="' . esc_attr__('Members area', 'sepo-membership') . '">';
            foreach ($sections as $s) {
                $count = $s === 'downloads' ? count($downloads) : ($s === 'links' ? count($links) : -1);
                $h .= '<button type="button" role="tab" class="smem-tab" id="smem-tab-' . esc_attr($s) . '" aria-controls="smem-panel-' . esc_attr($s) . '" aria-selected="' . ($s === $tab ? 'true' : 'false') . '" tabindex="' . ($s === $tab ? '0' : '-1') . '" data-tab="' . esc_attr($s) . '">'
                    . Icons::get($labels[$s][1]) . '<span>' . esc_html($labels[$s][0]) . '</span>'
                    . ($count >= 0 ? '<span class="smem-count">' . $count . '</span>' : '') . '</button>';
            }
            $h .= '</div>';
        }

        foreach ($sections as $s) {
            $h .= '<section class="smem-panel" role="' . (count($sections) > 1 ? 'tabpanel' : 'region') . '" id="smem-panel-' . esc_attr($s) . '" aria-labelledby="smem-tab-' . esc_attr($s) . '" data-panel="' . esc_attr($s) . '"' . ($s === $tab ? '' : ' hidden') . '>';
            if (count($sections) === 1) {
                $h .= '<h3 class="smem-section__title">' . esc_html($labels[$s][0]) . '</h3>';
            }
            $h .= match ($s) {
                'downloads'   => self::resources($downloads, $uid, $pageId, 'download'),
                'links'       => self::resources($links, $uid, $pageId, 'link'),
                'discussions' => self::board('post', $uid, $cfg['discussions_tier']),
                'suggestions' => self::board('suggestion', $uid, $cfg['suggestions_tier']),
            };
            $h .= '</section>';
        }

        return Parts::frame($h, 'smem-area', [
            'smem-view' => 'area',
            'area'      => $pageId,
            'tab'       => $tab,
            'item'      => (int) ($_GET['smem_item'] ?? 0),
            'manage'    => Plugin::canManage() ? 1 : 0,
        ]);
    }

    private static function gate(string $access, int $uid, string $tier): string
    {
        $plans = Settings::pageUrl('plans_page');
        $account = Settings::pageUrl('account_page');
        $reg = Parts::register();
        $h = '<div class="smem-gate">' . Icons::get('lock', 28);
        if ($access === 'guest') {
            $h .= '<h2 class="smem-gate__title">' . esc_html__('This area is for members', 'sepo-membership') . '</h2>'
                . '<p class="smem-gate__text">' . esc_html__('Sign in if you are a member. If not, choose a plan to join.', 'sepo-membership') . '</p>'
                . '<div class="smem-gate__actions"><a class="smem-btn smem-btn--primary" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-membership') . '</a>'
                . ($plans !== '' ? '<a class="smem-btn smem-btn--outline" href="' . esc_url($plans) . '">' . esc_html__('See plans', 'sepo-membership') . '</a>' : ($reg !== '' ? '<a class="smem-btn smem-btn--outline" href="' . esc_url($reg) . '">' . esc_html__('Create an account', 'sepo-membership') . '</a>' : ''))
                . '</div>';
        } elseif ($access === 'inactive') {
            $status = Members::status($uid);
            $waiting = Payments::query(['user_id' => $uid, 'kind' => 'membership', 'status' => Payments::OPEN, 'per_page' => 1])['total'] > 0;
            if ($waiting) {
                $h .= '<h2 class="smem-gate__title">' . esc_html__('Your payment is being checked', 'sepo-membership') . '</h2>'
                    . '<p class="smem-gate__text">' . esc_html__('This area opens as soon as we confirm your payment. We will email you when it does. Adding proof of payment helps us confirm it sooner.', 'sepo-membership') . '</p>'
                    . ($account !== '' ? '<div class="smem-gate__actions"><a class="smem-btn smem-btn--primary" href="' . esc_url($account) . '">' . esc_html__('My membership', 'sepo-membership') . '</a></div>' : '');
            } elseif (in_array($status, ['expired', 'cancelled'], true)) {
                $h .= '<h2 class="smem-gate__title">' . esc_html__('Your membership has ended', 'sepo-membership') . '</h2>'
                    . '<p class="smem-gate__text">' . esc_html__('Renew your plan to open the members area again. Everything is where you left it.', 'sepo-membership') . '</p>'
                    . '<div class="smem-gate__actions">' . ($account !== '' ? '<a class="smem-btn smem-btn--primary" href="' . esc_url($account) . '">' . esc_html__('Renew', 'sepo-membership') . '</a>' : '')
                    . ($plans !== '' ? '<a class="smem-btn smem-btn--outline" href="' . esc_url($plans) . '">' . esc_html__('See plans', 'sepo-membership') . '</a>' : '') . '</div>';
            } else {
                $h .= '<h2 class="smem-gate__title">' . esc_html__('This area is for members', 'sepo-membership') . '</h2>'
                    . '<p class="smem-gate__text">' . esc_html__('Choose a plan to join. The area opens as soon as your payment is confirmed.', 'sepo-membership') . '</p>'
                    . ($plans !== '' ? '<div class="smem-gate__actions"><a class="smem-btn smem-btn--primary" href="' . esc_url($plans) . '">' . esc_html__('See plans', 'sepo-membership') . '</a></div>' : '');
            }
        } else {
            $h .= '<h2 class="smem-gate__title">' . esc_html(sprintf(/* translators: %s: plan */ __('This area is for %s members and above', 'sepo-membership'), Tiers::label($tier))) . '</h2>'
                . '<p class="smem-gate__text">' . esc_html(sprintf(/* translators: %s: plan */ __('Your plan is %s. Upgrade to open this area; the upgrade starts as soon as it is paid.', 'sepo-membership'), Tiers::label(Members::level($uid)))) . '</p>'
                . ($plans !== '' ? '<div class="smem-gate__actions"><a class="smem-btn smem-btn--primary" href="' . esc_url($plans) . '">' . esc_html__('Upgrade', 'sepo-membership') . '</a></div>' : '');
        }
        return $h . '</div>';
    }

    private static function standing(int $uid, array $cfg): string
    {
        $u = wp_get_current_user();
        $m = Members::get($uid);
        $level = Members::level($uid);
        $parts = [];
        if ($level !== '') {
            /* translators: %s: plan */
            $parts[] = sprintf(__('%s member', 'sepo-membership'), Tiers::label($level));
            if ($m !== null && $m['expires_ts'] > 0) {
                $parts[] = Members::isEnding($uid)
                    /* translators: %s: date */
                    ? sprintf(__('access ends %s', 'sepo-membership'), Parts::date($m['expires_ts']))
                    /* translators: %s: date */
                    : sprintf(__('renews by %s', 'sepo-membership'), Parts::date($m['expires_ts']));
            }
        } elseif (Plugin::canManage()) {
            $parts[] = __('You manage the membership, so you see everything.', 'sepo-membership');
        }
        $account = Settings::pageUrl('account_page');
        $title = $cfg['title'] !== '' ? $cfg['title'] : sprintf(/* translators: %s: first name */ __('Welcome, %s', 'sepo-membership'), (string) ($u->first_name ?: $u->display_name));
        return '<div class="smem-standbar"><img class="smem-standbar__avatar" src="' . esc_url((string) get_avatar_url($uid, ['size' => 96])) . '" alt="" width="44" height="44">'
            . '<div class="smem-standbar__text"><strong class="smem-standbar__name">' . esc_html($title) . '</strong>'
            . '<span class="smem-standbar__meta">' . esc_html(implode(' · ', $parts)) . '</span></div>'
            . ($account !== '' ? '<a class="smem-btn smem-btn--sm smem-btn--outline" href="' . esc_url($account) . '">' . esc_html__('My membership', 'sepo-membership') . '</a>' : '')
            . '</div>';
    }

    /** @param list<array<string,mixed>> $items */
    private static function resources(array $items, int $uid, int $pageId, string $type): string
    {
        if ($items === []) {
            return '<p class="smem-empty">' . esc_html($type === 'link' ? __('No links have been shared yet.', 'sepo-membership') : __('No downloads yet. New ones will appear here.', 'sepo-membership')) . '</p>';
        }
        $plans = Settings::pageUrl('plans_page');
        $h = '<ul class="smem-resources">';
        foreach ($items as $r) {
            $open = Resources::can($uid, $r['tier']);
            $isFile = $r['type'] !== 'link' && $r['file'] !== null;
            $h .= '<li class="smem-res' . ($open ? '' : ' is-locked') . '"><span class="smem-res__icon">' . Icons::get($open ? ($isFile ? 'file' : 'link') : 'lock', 20) . '</span>'
                . '<div class="smem-res__body"><strong class="smem-res__title">' . esc_html($r['title']) . '</strong>';
            if ($r['note'] !== '') {
                $h .= '<span class="smem-res__note">' . esc_html($r['note']) . '</span>';
            }
            $meta = [];
            if ($isFile) {
                $ext = strtoupper((string) pathinfo((string) ($r['file']['name'] ?? ''), PATHINFO_EXTENSION));
                if ($ext !== '') {
                    $meta[] = $ext;
                }
                if (!empty($r['file']['size'])) {
                    $meta[] = size_format((int) $r['file']['size'], 1);
                }
            } elseif ($r['url'] !== '') {
                $host = (string) wp_parse_url($r['url'], PHP_URL_HOST);
                if ($host !== '' && $open) {
                    $meta[] = preg_replace('~^www\.~', '', $host);
                }
            }
            if ($r['tier'] !== '') {
                /* translators: %s: plan */
                $meta[] = sprintf(__('%s and above', 'sepo-membership'), Tiers::label($r['tier']));
            }
            if ($meta !== []) {
                $h .= '<span class="smem-res__meta">' . esc_html(implode(' · ', $meta)) . '</span>';
            }
            $h .= '</div>';
            if ($open) {
                $h .= '<a class="smem-btn smem-btn--sm smem-btn--outline" href="' . esc_url(Resources::link($r['id'], $pageId)) . '"' . ($isFile ? '' : ' target="_blank" rel="noopener"') . '>'
                    . Icons::get($isFile ? 'download' : 'external', 14) . '<span>' . esc_html($isFile ? __('Download', 'sepo-membership') : __('Open', 'sepo-membership')) . '</span>'
                    . '<span class="screen-reader-text"> ' . esc_html($r['title']) . '</span></a>';
            } elseif ($plans !== '') {
                $h .= '<a class="smem-btn smem-btn--sm smem-btn--quiet" href="' . esc_url($plans) . '">' . esc_html__('Upgrade to open', 'sepo-membership') . '</a>';
            }
            $h .= '</li>';
        }
        return $h . '</ul>';
    }

    private static function board(string $kind, int $uid, string $tier): string
    {
        if (!Resources::can($uid, $tier)) {
            $plans = Settings::pageUrl('plans_page');
            return '<div class="smem-gate smem-gate--compact">' . Icons::get('lock', 22)
                . '<p class="smem-gate__text">' . esc_html(sprintf(
                    $kind === 'suggestion'
                        /* translators: %s: plan */
                        ? __('Suggestions and voting are for %s members and above.', 'sepo-membership')
                        /* translators: %s: plan */
                        : __('Discussions are for %s members and above.', 'sepo-membership'),
                    Tiers::label($tier)
                )) . '</p>'
                . ($plans !== '' ? '<a class="smem-btn smem-btn--primary smem-btn--sm" href="' . esc_url($plans) . '">' . esc_html__('Upgrade', 'sepo-membership') . '</a>' : '')
                . '</div>';
        }
        $isS = $kind === 'suggestion';
        $h = '<div class="smem-board" data-board="' . esc_attr($kind) . '">';
        $h .= '<div class="smem-board__bar"><p class="smem-board__intro">' . esc_html($isS
            ? __('Ideas for what we should do next. Vote for the ones you want most.', 'sepo-membership')
            : __('Ask questions and talk with other members.', 'sepo-membership')) . '</p>';
        $h .= '<div class="smem-board__tools">';
        if ($isS) {
            $h .= '<label class="screen-reader-text" for="smem-sf-' . esc_attr($kind) . '">' . esc_html__('Show', 'sepo-membership') . '</label>'
                . '<select class="smem-select smem-select--sm" id="smem-sf-' . esc_attr($kind) . '" data-board-status>'
                . '<option value="">' . esc_html__('All', 'sepo-membership') . '</option>'
                . '<option value="open">' . esc_html__('Open', 'sepo-membership') . '</option>'
                . '<option value="planned">' . esc_html__('Planned', 'sepo-membership') . '</option>'
                . '<option value="done">' . esc_html__('Done', 'sepo-membership') . '</option>'
                . '<option value="declined">' . esc_html__('Not planned', 'sepo-membership') . '</option></select>';
        }
        $h .= '<label class="screen-reader-text" for="smem-sort-' . esc_attr($kind) . '">' . esc_html__('Sort', 'sepo-membership') . '</label>'
            . '<select class="smem-select smem-select--sm" id="smem-sort-' . esc_attr($kind) . '" data-board-sort>'
            . ($isS ? '<option value="top">' . esc_html__('Most votes', 'sepo-membership') . '</option>' : '')
            . '<option value="active">' . esc_html__('Latest activity', 'sepo-membership') . '</option>'
            . '<option value="new">' . esc_html__('Newest', 'sepo-membership') . '</option></select>'
            . '<button type="button" class="smem-btn smem-btn--primary smem-btn--sm" data-board-new>' . Icons::get('plus', 14) . esc_html($isS ? __('Suggest', 'sepo-membership') : __('New discussion', 'sepo-membership')) . '</button>'
            . '</div></div>';
        $h .= '<div class="smem-board__list" data-board-list aria-live="polite"><p class="smem-empty">' . esc_html__('Loading…', 'sepo-membership') . '</p></div>';
        $h .= '<nav class="smem-pager" data-board-pager hidden></nav>';
        $h .= '<div class="smem-thread" data-board-thread hidden></div>';
        return $h . '</div>';
    }
}
