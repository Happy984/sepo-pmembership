<?php

declare(strict_types=1);

namespace SepoMembership\Render;

use SepoMembership\Members;
use SepoMembership\Payments;
use SepoMembership\Projects;
use SepoMembership\Settings;
use SepoMembership\Sponsors;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/**
 * The sponsors page: what can be sponsored, who sponsors it, and the members
 * who chose to be shown.
 */
final class SponsorsView
{
    public const SECTIONS = ['stats', 'projects', 'sponsors', 'members'];

    /** @param array<string,string> $atts show, project, projects, title, intro */
    public static function render(array $atts): string
    {
        $show = array_values(array_intersect(self::SECTIONS, array_map('trim', explode(',', strtolower((string) ($atts['show'] ?? ''))))));
        $show = $show !== [] ? $show : self::SECTIONS;
        $projectId = (int) ($atts['project'] ?? 0);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($atts['projects'] ?? '')))));

        $h = '';
        $title = (string) ($atts['title'] ?? '');
        $intro = (string) ($atts['intro'] ?? '');
        if ($title !== '' || $intro !== '') {
            $h .= '<header class="smem-head smem-head--center">' . ($title !== '' ? '<h2 class="smem-title">' . esc_html($title) . '</h2>' : '')
                . ($intro !== '' ? '<p class="smem-lede">' . esc_html($intro) . '</p>' : '') . '</header>';
        }

        $projects = $projectId > 0 ? [] : Projects::all($ids);
        $groups = Sponsors::grouped($projectId);

        if (in_array('stats', $show, true) && $projectId === 0) {
            $count = 0;
            foreach ($groups as $g) {
                $count += count($g['items']);
            }
            $stats = [
                [Settings::money(Payments::raised(0)), __('given by our supporters', 'sepo-membership')],
                [number_format_i18n($count), _n('sponsor', 'sponsors', $count, 'sepo-membership')],
            ];
            $open = count(array_filter($projects, static fn(array $p): bool => !$p['closed']));
            if ($projects !== []) {
                $stats[] = [number_format_i18n($open), _n('project open now', 'projects open now', $open, 'sepo-membership')];
            }
            $h .= '<div class="smem-stats">';
            foreach ($stats as [$n, $l]) {
                $h .= '<div class="smem-stat"><span class="smem-stat__n">' . esc_html($n) . '</span><span class="smem-stat__l">' . esc_html($l) . '</span></div>';
            }
            $h .= '</div>';
        }

        if (in_array('projects', $show, true) && $projects !== []) {
            $h .= '<section class="smem-section"><h3 class="smem-section__title">' . esc_html__('What you can sponsor', 'sepo-membership') . '</h3><div class="smem-projects">';
            foreach ($projects as $p) {
                $h .= self::projectCard($p);
            }
            $h .= '</div></section>';
        }

        if (in_array('sponsors', $show, true)) {
            $h .= '<section class="smem-section"><h3 class="smem-section__title">' . esc_html($projectId > 0 ? __('Sponsors of this project', 'sepo-membership') : __('Our sponsors', 'sepo-membership')) . '</h3>';
            if ($groups === []) {
                $donate = Settings::pageUrl('donate_page');
                $h .= '<p class="smem-empty">' . esc_html__('Be the first to sponsor.', 'sepo-membership')
                    . ($donate !== '' && $projectId === 0 ? ' <a href="' . esc_url($donate) . '">' . esc_html__('Give now', 'sepo-membership') . '</a>' : '') . '</p>';
            }
            $names = [];
            foreach ($groups as $id => $g) {
                $big = array_key_first($groups) === $id && count($groups) > 1;
                $h .= '<div class="smem-level"><h4 class="smem-level__name">' . Icons::get('award', 16) . esc_html($g['level']['label']) . '</h4>'
                    . '<ul class="smem-sponsors' . ($big ? ' smem-sponsors--lead' : '') . '">';
                foreach ($g['items'] as $s) {
                    $h .= self::sponsorCard($s, $projectId, $names);
                }
                $h .= '</ul></div>';
            }
            $h .= '</section>';
        }

        if (in_array('members', $show, true) && $projectId === 0) {
            $listed = Members::listed();
            if ($listed !== []) {
                $byTier = [];
                foreach ($listed as $m) {
                    $byTier[(string) $m['level']][] = $m;
                }
                uksort($byTier, static fn(string $a, string $b): int => Tiers::rank($b) <=> Tiers::rank($a));
                $h .= '<section class="smem-section"><h3 class="smem-section__title">' . esc_html__('Members who support us', 'sepo-membership') . '</h3>';
                foreach ($byTier as $tier => $ms) {
                    $h .= '<div class="smem-level"><h4 class="smem-level__name">' . esc_html(Tiers::label((string) $tier)) . ' <span class="smem-count">' . count($ms) . '</span></h4><ul class="smem-people">';
                    foreach ($ms as $m) {
                        $name = (string) ($m['display_name'] ?? '');
                        $h .= '<li class="smem-person"><img class="smem-person__avatar" src="' . esc_url((string) get_avatar_url((int) $m['user_id'], ['size' => 64])) . '" alt="" width="32" height="32" loading="lazy"><span>' . esc_html($name) . '</span></li>';
                    }
                    $h .= '</ul></div>';
                }
                $h .= '</section>';
            }
        }
        $plans = Settings::pageUrl('plans_page');
        $donate = Settings::pageUrl('donate_page');
        if ($projectId === 0 && ($plans !== '' || $donate !== '')) {
            $h .= '<aside class="smem-cta"><div><strong>' . esc_html__('Want to see your name here?', 'sepo-membership') . '</strong><p>' . esc_html__('Sponsor a project, give a gift, or become a member and choose to be listed.', 'sepo-membership') . '</p></div><div class="smem-cta__actions">'
                . ($donate !== '' ? '<a class="smem-btn smem-btn--primary" href="' . esc_url($donate) . '">' . esc_html__('Give', 'sepo-membership') . '</a>' : '')
                . ($plans !== '' ? '<a class="smem-btn smem-btn--outline" href="' . esc_url($plans) . '">' . esc_html__('Become a member', 'sepo-membership') . '</a>' : '')
                . '</div></aside>';
        }
        return Parts::frame($h, 'smem-sponsors-page' . ($projectId === 0 ? ' alignwide' : ''));
    }

    /** @param array<string,mixed> $p */
    public static function projectCard(array $p): string
    {
        $h = '<article class="smem-project' . ($p['closed'] ? ' is-closed' : '') . '">';
        $h .= '<a class="smem-project__media" href="' . esc_url($p['url']) . '" tabindex="-1" aria-hidden="true">'
            . ($p['image'] !== '' ? '<img src="' . esc_url($p['image']) . '" alt="" loading="lazy">' : '<span class="smem-project__ph">' . Icons::get('gift', 30) . '</span>')
            . '</a><div class="smem-project__body">';
        if ($p['closed']) {
            $h .= '<span class="smem-badge">' . esc_html__('Closed', 'sepo-membership') . '</span>';
        } elseif ($p['goal'] > 0 && $p['raised'] >= $p['goal']) {
            $h .= '<span class="smem-badge smem-badge--good">' . esc_html__('Goal reached', 'sepo-membership') . '</span>';
        }
        $h .= '<h4 class="smem-project__title"><a href="' . esc_url($p['url']) . '">' . esc_html($p['title']) . '</a></h4>';
        if ($p['excerpt'] !== '') {
            $h .= '<p class="smem-project__text">' . esc_html($p['excerpt']) . '</p>';
        }
        if ($p['goal'] > 0) {
            $h .= Parts::progress((int) $p['percent'], sprintf(/* translators: %s: project */ __('Progress for %s', 'sepo-membership'), $p['title']));
        }
        $h .= '<p class="smem-project__nums"><strong>' . esc_html(Settings::money((float) $p['raised'])) . '</strong> '
            . esc_html($p['goal'] > 0 ? sprintf(/* translators: %s: goal */ __('of %s', 'sepo-membership'), Settings::money((float) $p['goal'])) : __('raised', 'sepo-membership'))
            . ' · ' . esc_html(sprintf(/* translators: %s: number */ _n('%s supporter', '%s supporters', (int) $p['backers'], 'sepo-membership'), number_format_i18n((int) $p['backers']))) . '</p>';
        $h .= '<a class="smem-btn smem-btn--sm ' . ($p['closed'] ? 'smem-btn--outline' : 'smem-btn--primary') . '" href="' . esc_url($p['url']) . '">' . esc_html($p['closed'] ? __('See the project', 'sepo-membership') : __('Sponsor this', 'sepo-membership')) . '</a>';
        return $h . '</div></article>';
    }

    /**
     * @param array<string,mixed> $s
     * @param array<int,string> $names project titles, filled as needed
     */
    private static function sponsorCard(array $s, int $projectId, array &$names): string
    {
        $logo = $s['logo'] !== ''
            ? '<img class="smem-sponsor__logo" src="' . esc_url($s['logo']) . '" alt="" loading="lazy">'
            : '<span class="smem-sponsor__initials" aria-hidden="true">' . esc_html(Parts::initials($s['name'])) . '</span>';
        $name = esc_html($s['name']);
        $h = '<li class="smem-sponsor">' . $logo . '<div class="smem-sponsor__body">';
        $h .= $s['url'] !== '' ? '<a class="smem-sponsor__name" href="' . esc_url($s['url']) . '" rel="noopener sponsored" target="_blank">' . $name . '</a>' : '<span class="smem-sponsor__name">' . $name . '</span>';
        if ($s['note'] !== '') {
            $h .= '<span class="smem-sponsor__note">' . esc_html($s['note']) . '</span>';
        }
        if ($projectId === 0 && $s['projects'] !== []) {
            $links = [];
            foreach (array_slice($s['projects'], 0, 4) as $pid) {
                if (!isset($names[$pid])) {
                    $names[$pid] = get_post_status($pid) === 'publish' ? get_the_title($pid) : '';
                }
                if ($names[$pid] !== '') {
                    $links[] = '<a href="' . esc_url((string) get_permalink($pid)) . '">' . esc_html($names[$pid]) . '</a>';
                }
            }
            if ($links !== []) {
                $h .= '<span class="smem-sponsor__backs">' . esc_html__('Sponsors', 'sepo-membership') . ' ' . implode(', ', $links) . '</span>';
            }
        }
        return $h . '</div></li>';
    }
}
