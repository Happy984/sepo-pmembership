<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Sponsorship projects: a goal, an optional closing date, and what has come in.
 *
 * Only confirmed gifts count towards the goal. A project closes on its closing
 * date, or when marked closed by hand; a project that reaches its goal stays
 * open unless it is set to close then.
 */
final class Projects
{
    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        $p = get_post($id);
        if (!$p instanceof \WP_Post || $p->post_type !== PostTypes::PROJECT) {
            return null;
        }
        $goal = max(0.0, (float) get_post_meta($id, '_smem_goal', true));
        $raised = Payments::raised($id);
        $deadline = (string) get_post_meta($id, '_smem_deadline', true);
        $deadlineTs = $deadline !== '' ? (int) strtotime($deadline . ' 23:59:59 ' . wp_timezone_string()) : 0;
        return [
            'id'          => $id,
            'title'       => get_the_title($p),
            'excerpt'     => has_excerpt($p) ? get_the_excerpt($p) : wp_trim_words(wp_strip_all_tags((string) $p->post_content), 28),
            'url'         => (string) get_permalink($p),
            'image'       => (string) get_the_post_thumbnail_url($p, 'medium_large'),
            'status'      => (string) $p->post_status,
            'goal'        => $goal,
            'raised'      => $raised,
            'percent'     => $goal > 0 ? (int) min(100, floor($raised / $goal * 100)) : 0,
            'backers'     => Payments::backers($id),
            'deadline'    => $deadline,
            'deadline_ts' => $deadlineTs,
            'close_at_goal' => (int) get_post_meta($id, '_smem_close_at_goal', true) === 1,
            'closed'      => self::isClosed($id, $goal, $raised, $deadlineTs),
        ];
    }

    public static function isClosed(int $id, ?float $goal = null, ?float $raised = null, ?int $deadlineTs = null): bool
    {
        if ((int) get_post_meta($id, '_smem_closed', true) === 1) {
            return true;
        }
        if ($deadlineTs === null) {
            $d = (string) get_post_meta($id, '_smem_deadline', true);
            $deadlineTs = $d !== '' ? (int) strtotime($d . ' 23:59:59 ' . wp_timezone_string()) : 0;
        }
        if ($deadlineTs > 0 && $deadlineTs < time()) {
            return true;
        }
        if ((int) get_post_meta($id, '_smem_close_at_goal', true) === 1) {
            $goal ??= (float) get_post_meta($id, '_smem_goal', true);
            $raised ??= Payments::raised($id);
            return $goal > 0 && $raised >= $goal;
        }
        return false;
    }

    /**
     * Published projects, open ones first.
     *
     * @param list<int> $ids only these, in this order, when given
     * @return list<array<string,mixed>>
     */
    public static function all(array $ids = [], bool $includeClosed = true): array
    {
        $q = new \WP_Query([
            'post_type'      => PostTypes::PROJECT,
            'post_status'    => 'publish',
            'posts_per_page' => 60,
            'fields'         => 'ids',
            'orderby'        => $ids !== [] ? 'post__in' : ['menu_order' => 'ASC', 'date' => 'DESC'],
            'post__in'       => $ids,
        ]);
        $out = [];
        foreach ($q->posts as $id) {
            $p = self::get((int) $id);
            if ($p !== null && ($includeClosed || !$p['closed'])) {
                $out[] = $p;
            }
        }
        if ($ids === []) {
            usort($out, static fn(array $a, array $b): int => (int) $a['closed'] <=> (int) $b['closed']);
        }
        return $out;
    }
}
