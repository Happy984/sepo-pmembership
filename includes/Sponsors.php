<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Everyone shown on the sponsors page, in one list.
 *
 *   added by hand   Sponsor entries in the dashboard, with a level, a logo,
 *                   a link and the projects they back
 *   from payments   people whose confirmed gifts asked to be listed; their
 *                   level follows what they gave in total
 *
 * Members who chose to be listed are shown separately, by plan.
 */
final class Sponsors
{
    /**
     * @return list<array{key:string,name:string,url:string,logo:string,note:string,level:string,amount:float,projects:list<int>,source:string,order:int}>
     */
    public static function all(int $projectId = 0): array
    {
        $out = [];
        $q = new \WP_Query([
            'post_type'      => PostTypes::SPONSOR,
            'post_status'    => 'publish',
            'posts_per_page' => 300,
            'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
        ]);
        $levels = Settings::sponsorLevels();
        foreach ($q->posts as $p) {
            $projects = array_values(array_filter(array_map('intval', (array) get_post_meta($p->ID, '_smem_projects', true))));
            if ($projectId > 0 && !in_array($projectId, $projects, true)) {
                continue;
            }
            $level = (string) get_post_meta($p->ID, '_smem_level', true);
            $out[] = [
                'key'      => 's' . $p->ID,
                'name'     => get_the_title($p),
                'url'      => esc_url_raw((string) get_post_meta($p->ID, '_smem_url', true)),
                'logo'     => (string) get_the_post_thumbnail_url($p, 'medium'),
                'note'     => (string) $p->post_excerpt,
                'level'    => isset($levels[$level]) ? $level : (string) array_key_last($levels),
                'amount'   => 0.0,
                'projects' => $projects,
                'source'   => 'manual',
                'order'    => (int) $p->menu_order,
            ];
        }
        foreach (Payments::listedGivers($projectId) as $g) {
            $out[] = [
                'key'      => $g['key'],
                'name'     => $g['name'],
                'url'      => '',
                'logo'     => '',
                'note'     => '',
                'level'    => Settings::levelForAmount($g['amount']),
                'amount'   => $g['amount'],
                'projects' => $g['projects'],
                'source'   => 'gift',
                'order'    => 1000,
            ];
        }
        return (array) apply_filters('sepo_membership_sponsors', $out, $projectId);
    }

    /** Sponsors grouped by level, highest level first; empty levels left out. @return array<string,array{level:array<string,mixed>,items:list<array<string,mixed>>}> */
    public static function grouped(int $projectId = 0): array
    {
        $groups = [];
        foreach (Settings::sponsorLevels() as $id => $l) {
            $groups[$id] = ['level' => $l, 'items' => []];
        }
        foreach (self::all($projectId) as $s) {
            $groups[$s['level']]['items'][] = $s;
        }
        foreach ($groups as $id => $g) {
            if ($g['items'] === []) {
                unset($groups[$id]);
                continue;
            }
            usort($groups[$id]['items'], static fn(array $a, array $b): int => [$a['order'], -$a['amount'], $a['name']] <=> [$b['order'], -$b['amount'], $b['name']]);
        }
        return $groups;
    }
}
