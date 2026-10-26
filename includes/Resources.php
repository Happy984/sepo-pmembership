<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * The members area: who may see it, its resources, and the gated routes that
 * hand them out.
 *
 * The floor is an active plan. A guest, or a member whose plan is pending,
 * expired or cancelled, gets a panel and nothing else; the area's content
 * never reaches their HTML. On top of the floor:
 *
 *   the area      `tier` on the [sepo_members_area] shortcode or block
 *   each feature  `discussions_tier`, `suggestions_tier`
 *   each resource the "Plan needed" set on the resource
 *
 * Every download, link and board action checks again on the server, against
 * the area page it came from, so a copied address or a stale page gets
 * nothing. Every check fails closed. People who run the membership pass all
 * of them.
 *
 * Downloads are stored in the private folder and streamed by PHP. A link's
 * address is never written into the page: the member is sent on by a
 * redirect after the check.
 */
final class Resources
{
    public static function hook(): void
    {
        add_filter('query_vars', static function (array $v): array {
            $v[] = 'smem_get';
            $v[] = 'smem_area';
            return $v;
        });
        add_action('template_redirect', [self::class, 'serve'], 1);
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        $p = get_post($id);
        if (!$p instanceof \WP_Post || $p->post_type !== PostTypes::RESOURCE) {
            return null;
        }
        $file = get_post_meta($id, '_smem_file', true);
        $file = is_array($file) ? $file : null;
        // A download comes from the private folder or, when chosen, from the media library.
        $media = (int) get_post_meta($id, '_smem_attachment', true);
        if ((string) get_post_meta($id, '_smem_source', true) === 'media') {
            $file = null;
            $path = $media > 0 && get_post_type($media) === 'attachment' ? (string) get_attached_file($media) : '';
            if ($path !== '') {
                $file = ['media' => $media, 'name' => wp_basename($path), 'type' => (string) get_post_mime_type($media), 'size' => is_file($path) ? (int) filesize($path) : 0];
            }
        }
        $type = (string) get_post_meta($id, '_smem_type', true) === 'link' ? 'link' : 'download';
        return [
            'id'     => $id,
            'title'  => (string) $p->post_title,
            'note'   => (string) $p->post_excerpt,
            'status' => (string) $p->post_status,
            'type'   => $type,
            'tier'   => sanitize_key((string) get_post_meta($id, '_smem_tier', true)),
            'url'    => (string) get_post_meta($id, '_smem_url', true),
            'file'   => $file,
        ];
    }

    /**
     * Published resources for an area, in menu order.
     *
     * @return list<array<string,mixed>>
     */
    public static function forArea(array $cfg, string $type): array
    {
        $args = [
            'post_type'      => PostTypes::RESOURCE,
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => ['menu_order' => 'ASC', 'title' => 'ASC'],
            'fields'         => 'ids',
            'meta_query'     => [$type === 'link'
                ? ['key' => '_smem_type', 'value' => 'link']
                : ['relation' => 'OR', ['key' => '_smem_type', 'value' => 'download'], ['key' => '_smem_type', 'compare' => 'NOT EXISTS']]],
        ];
        if ($cfg['collection'] !== '') {
            $args['tax_query'] = [['taxonomy' => PostTypes::COLLECTION, 'field' => 'slug', 'terms' => array_map('trim', explode(',', $cfg['collection']))]];
        }
        $out = [];
        foreach ((new \WP_Query($args))->posts as $id) {
            $r = self::get((int) $id);
            if ($r !== null && ($type === 'link' ? $r['url'] !== '' : $r['file'] !== null)) {
                $out[] = $r;
            }
        }
        return $out;
    }

    // --------------------------------------------------------------- areas

    public const SECTIONS = ['downloads', 'links', 'discussions', 'suggestions'];

    /** @return array<string,string> */
    public static function defaults(): array
    {
        return ['tier' => '', 'discussions_tier' => '', 'suggestions_tier' => '', 'collection' => '', 'board' => '', 'show' => 'downloads,links,discussions,suggestions', 'title' => ''];
    }

    /** @param array<string,mixed> $atts @return array<string,string> */
    public static function normalise(array $atts, int $pageId): array
    {
        $cfg = array_merge(self::defaults(), array_intersect_key($atts, self::defaults()));
        foreach (['tier', 'discussions_tier', 'suggestions_tier'] as $k) {
            $v = sanitize_key((string) $cfg[$k]);
            $cfg[$k] = in_array($v, ['any', 'all', 'free', 'everyone'], true) ? '' : $v;
        }
        $cfg['collection'] = sanitize_text_field((string) $cfg['collection']);
        $cfg['title'] = sanitize_text_field((string) $cfg['title']);
        $show = array_values(array_intersect(self::SECTIONS, array_map('trim', explode(',', strtolower((string) $cfg['show'])))));
        $cfg['show'] = implode(',', $show !== [] ? $show : self::SECTIONS);
        $board = sanitize_key((string) $cfg['board']);
        $cfg['board'] = $board !== '' ? $board : 'p' . $pageId;
        return $cfg;
    }

    /**
     * The members-area settings on a page, read from its shortcode or block.
     * Null when the page has no members area, so a request naming any other
     * page is refused.
     *
     * @return array<string,string>|null
     */
    public static function areaConfig(int $pageId): ?array
    {
        $post = get_post($pageId);
        if (!$post instanceof \WP_Post || $post->post_status !== 'publish') {
            return null;
        }
        $content = (string) $post->post_content;
        if (has_block('sepo-membership/members-area', $post)) {
            foreach (parse_blocks($content) as $b) {
                $found = self::findBlock($b);
                if ($found !== null) {
                    return self::normalise([
                        'tier'             => $found['tier'] ?? '',
                        'discussions_tier' => $found['discussionsTier'] ?? '',
                        'suggestions_tier' => $found['suggestionsTier'] ?? '',
                        'collection'       => $found['collection'] ?? '',
                        'show'             => $found['show'] ?? '',
                        'title'            => $found['title'] ?? '',
                    ], $pageId);
                }
            }
        }
        if (has_shortcode($content, 'sepo_members_area') && preg_match('/' . get_shortcode_regex(['sepo_members_area']) . '/s', $content, $m)) {
            $atts = shortcode_parse_atts($m[3]);
            return self::normalise(is_array($atts) ? $atts : [], $pageId);
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    private static function findBlock(array $b): ?array
    {
        if (($b['blockName'] ?? '') === 'sepo-membership/members-area') {
            return (array) ($b['attrs'] ?? []);
        }
        foreach ((array) ($b['innerBlocks'] ?? []) as $inner) {
            $f = self::findBlock($inner);
            if ($f !== null) {
                return $f;
            }
        }
        return null;
    }

    /** Is a section switched on in this area? */
    public static function shows(array $cfg, string $section): bool
    {
        return in_array($section, explode(',', (string) $cfg['show']), true);
    }

    /**
     * May this person see the area at all? 'ok', 'guest', 'inactive' or 'tier'.
     */
    public static function access(int $uid, string $minTier): string
    {
        if (Plugin::canManage()) {
            return 'ok';
        }
        if ($uid < 1) {
            return 'guest';
        }
        if (Members::status($uid) !== 'active') {
            return 'inactive';
        }
        return $minTier === '' || Members::hasLevel($uid, $minTier) ? 'ok' : 'tier';
    }

    /** May this person use something that needs $tier, inside an area they can already see? */
    public static function can(int $uid, string $tier): bool
    {
        if (Plugin::canManage()) {
            return true;
        }
        return $uid > 0 && Members::hasLevel($uid, $tier);
    }

    /** The gated address of a resource, from one area page. */
    public static function link(int $resourceId, int $areaId): string
    {
        return add_query_arg(['smem_get' => $resourceId, 'smem_area' => $areaId], home_url('/'));
    }

    /** ?smem_get=<resource>&smem_area=<page>: check, then stream the file or redirect to the link. */
    public static function serve(): void
    {
        $rid = (int) get_query_var('smem_get');
        if ($rid < 1) {
            return;
        }
        $area = (int) get_query_var('smem_area');
        $uid = get_current_user_id();
        nocache_headers();
        if ($uid < 1) {
            wp_safe_redirect(wp_login_url(self::link($rid, $area)));
            exit;
        }
        $cfg = self::areaConfig($area);
        $r = self::get($rid);
        $deny = static function (string $msg, int $code = 403): void {
            wp_die(esc_html($msg), esc_html__('Members only', 'sepo-membership'), ['response' => $code, 'back_link' => true]);
        };
        if ($cfg === null || $r === null || $r['status'] !== 'publish') {
            $deny(__('That resource is not available.', 'sepo-membership'), 404);
            return;
        }
        if (self::access($uid, $cfg['tier']) !== 'ok' || !self::can($uid, $r['tier'])) {
            $deny(__('This is for members with an active plan that includes it. If you have just paid, it opens as soon as your payment is confirmed.', 'sepo-membership'));
            return;
        }
        do_action('sepo_membership_resource_opened', $r, $uid);
        if ($r['type'] === 'download' && $r['file'] === null) {
            $deny(__('That file is no longer available.', 'sepo-membership'), 404);
            return;
        }
        if ($r['type'] === 'link') {
            if (!preg_match('#^https?://#i', $r['url'])) {
                $deny(__('That link is not valid.', 'sepo-membership'), 422);
                return;
            }
            header('Referrer-Policy: no-referrer');
            wp_redirect($r['url'], 302, 'Sepo Membership');
            exit;
        }
        if (!empty($r['file']['media'])) {
            Files::sendAttachment((int) $r['file']['media']);
        }
        Files::send('resources', (array) $r['file'], false);
    }
}
