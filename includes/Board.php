<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * The members' board: discussions and suggestions, kept per members area.
 *
 *   post        a discussion thread
 *   suggestion  an idea members vote for; the people who run the membership
 *               mark it planned, done or declined
 *   reply       an answer under either, with `thread` pointing at it
 *
 * Text is stored as plain text and escaped when shown. Authors may edit or
 * remove their own items; the people who run the membership may edit, remove,
 * pin and set the status of any. Removing a thread removes its replies and
 * votes. Access is checked by the REST layer against the area page before
 * anything here is called.
 */
final class Board
{
    public const KINDS = ['post', 'suggestion', 'reply'];
    public const STATUSES = ['open', 'planned', 'done', 'declined'];
    public const TITLE_MAX = 140;
    public const BODY_MAX = 5000;
    public const PER_HOUR = 20;

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function row(array $r): array
    {
        foreach (['id', 'thread', 'user_id', 'replies', 'votes'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['pinned'] = (int) $r['pinned'] === 1;
        $r['created_ts'] = Schema::ts($r['created']);
        $r['updated_ts'] = Schema::ts($r['updated']);
        $r['last_ts'] = Schema::ts($r['last_at']);
        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::board() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($r) ? self::row($r) : null;
    }

    /**
     * Threads in an area, pinned first.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,pages:int}
     */
    public static function threads(string $area, string $kind, string $sort = 'active', int $page = 1, string $status = '', int $per = 15): array
    {
        global $wpdb;
        $kind = $kind === 'suggestion' ? 'suggestion' : 'post';
        $where = $wpdb->prepare('area = %s AND kind = %s', $area, $kind);
        if ($kind === 'suggestion' && in_array($status, self::STATUSES, true)) {
            $where .= $wpdb->prepare(' AND status = %s', $status);
        }
        $order = match ($sort) {
            'top' => 'pinned DESC, votes DESC, id DESC',
            'new' => 'pinned DESC, id DESC',
            default => 'pinned DESC, last_at DESC, id DESC',
        };
        $page = max(1, $page);
        // phpcs:disable WordPress.DB.PreparedSQL -- $where is prepared above, $order is from a fixed list
        $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::board() . " WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::board() . " WHERE $where ORDER BY $order LIMIT %d OFFSET %d", $per, ($page - 1) * $per), ARRAY_A);
        // phpcs:enable
        return ['rows' => array_map([self::class, 'row'], $rows ?: []), 'total' => $total, 'pages' => max(1, (int) ceil($total / $per))];
    }

    /** @return list<array<string,mixed>> */
    public static function replies(int $thread): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::board() . " WHERE thread = %d AND kind = 'reply' ORDER BY id ASC LIMIT 500", $thread), ARRAY_A);
        return array_map([self::class, 'row'], $rows ?: []);
    }

    /** Which of these items has the user voted for. @param list<int> $ids @return array<int,true> */
    public static function votedBy(int $uid, array $ids): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($uid < 1 || $ids === []) {
            return [];
        }
        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from the id count
        $got = $wpdb->get_col($wpdb->prepare('SELECT item_id FROM ' . Schema::votes() . ' WHERE user_id = %d AND item_id IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', $uid, ...$ids));
        return array_fill_keys(array_map('intval', $got ?: []), true);
    }

    public static function canEdit(array $item, int $uid): bool
    {
        return Plugin::canManage() || ($uid > 0 && $item['user_id'] === $uid);
    }

    /** @return array{title:string,body:string} */
    private static function clean(string $title, string $body, bool $needsTitle): array
    {
        $title = trim(mb_substr(sanitize_text_field($title), 0, self::TITLE_MAX));
        $body = trim(sanitize_textarea_field($body));
        if ($needsTitle && $title === '') {
            throw new \InvalidArgumentException(__('Give it a title.', 'sepo-membership'));
        }
        if ($body === '' && !$needsTitle) {
            throw new \InvalidArgumentException(__('Write your reply.', 'sepo-membership'));
        }
        if (mb_strlen($body) > self::BODY_MAX) {
            /* translators: %d: character limit */
            throw new \InvalidArgumentException(sprintf(__('Keep it under %d characters.', 'sepo-membership'), self::BODY_MAX));
        }
        return ['title' => $title, 'body' => $body];
    }

    private static function pace(int $uid): void
    {
        if (Plugin::canManage()) {
            return;
        }
        $key = 'smem_board_rate_' . $uid;
        $n = (int) get_transient($key);
        if ($n >= self::PER_HOUR) {
            throw new \InvalidArgumentException(__('You have posted a lot in the last hour. Please wait a little before posting again.', 'sepo-membership'));
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
    }

    /** Start a discussion or a suggestion. @return array<string,mixed> */
    public static function create(string $area, string $kind, int $uid, string $title, string $body): array
    {
        global $wpdb;
        if (!in_array($kind, ['post', 'suggestion'], true)) {
            throw new \InvalidArgumentException(__('Unknown kind of post.', 'sepo-membership'));
        }
        $c = self::clean($title, $body, true);
        self::pace($uid);
        $now = Schema::now();
        $wpdb->insert(Schema::board(), [
            'area' => $area, 'kind' => $kind, 'thread' => 0, 'user_id' => $uid,
            'title' => $c['title'], 'body' => $c['body'], 'status' => 'open',
            'created' => $now, 'updated' => $now, 'last_at' => $now,
        ]);
        $item = self::get((int) $wpdb->insert_id);
        if ($item === null) {
            throw new \RuntimeException(__('That could not be saved. Please try again.', 'sepo-membership'));
        }
        do_action('sepo_membership_board_created', $item);
        return $item;
    }

    /** Reply to a thread. @return array<string,mixed> */
    public static function reply(int $thread, int $uid, string $body): array
    {
        global $wpdb;
        $parent = self::get($thread);
        if ($parent === null || $parent['kind'] === 'reply') {
            throw new \InvalidArgumentException(__('That conversation is no longer here.', 'sepo-membership'));
        }
        $c = self::clean('', $body, false);
        self::pace($uid);
        $now = Schema::now();
        $wpdb->insert(Schema::board(), [
            'area' => $parent['area'], 'kind' => 'reply', 'thread' => $thread, 'user_id' => $uid,
            'title' => '', 'body' => $c['body'], 'status' => 'open',
            'created' => $now, 'updated' => $now, 'last_at' => $now,
        ]);
        $item = self::get((int) $wpdb->insert_id);
        if ($item === null) {
            throw new \RuntimeException(__('That could not be saved. Please try again.', 'sepo-membership'));
        }
        self::recount($thread);
        if ($parent['user_id'] !== $uid) {
            Mail::boardReply($parent, $item);
        }
        do_action('sepo_membership_board_replied', $item, $parent);
        return $item;
    }

    /** @return array<string,mixed> */
    public static function update(int $id, int $uid, string $title, string $body): array
    {
        global $wpdb;
        $item = self::get($id);
        if ($item === null) {
            throw new \InvalidArgumentException(__('That post is no longer here.', 'sepo-membership'));
        }
        if (!self::canEdit($item, $uid)) {
            throw new \DomainException(__('You can only change your own posts.', 'sepo-membership'));
        }
        $c = self::clean($title, $body, $item['kind'] !== 'reply');
        $wpdb->update(Schema::board(), ['title' => $item['kind'] === 'reply' ? '' : $c['title'], 'body' => $c['body'], 'updated' => Schema::now()], ['id' => $id]);
        return self::get($id) ?? $item;
    }

    public static function remove(int $id, int $uid): void
    {
        global $wpdb;
        $item = self::get($id);
        if ($item === null) {
            return;
        }
        if (!self::canEdit($item, $uid)) {
            throw new \DomainException(__('You can only remove your own posts.', 'sepo-membership'));
        }
        if ($item['kind'] === 'reply') {
            $wpdb->delete(Schema::board(), ['id' => $id]);
            self::recount($item['thread']);
            return;
        }
        $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::board() . ' WHERE id = %d OR thread = %d', $id, $id)) ?: []);
        $wpdb->delete(Schema::votes(), ['item_id' => $id]);
        $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::board() . ' WHERE id = %d OR thread = %d', $id, $id));
        do_action('sepo_membership_board_removed', $item, $ids);
    }

    /** Vote for a suggestion, or take the vote back. @return array{voted:bool,votes:int} */
    public static function vote(int $id, int $uid): array
    {
        global $wpdb;
        $item = self::get($id);
        if ($item === null || $item['kind'] !== 'suggestion') {
            throw new \InvalidArgumentException(__('That suggestion is no longer here.', 'sepo-membership'));
        }
        if ($item['user_id'] === $uid) {
            throw new \InvalidArgumentException(__('Your own suggestion already counts as yours. Ask others to vote for it.', 'sepo-membership'));
        }
        if (in_array($item['status'], ['done', 'declined'], true)) {
            throw new \InvalidArgumentException(__('Voting is closed on this suggestion.', 'sepo-membership'));
        }
        $had = self::votedBy($uid, [$id]) !== [];
        if ($had) {
            $wpdb->delete(Schema::votes(), ['item_id' => $id, 'user_id' => $uid]);
        } else {
            $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . Schema::votes() . ' (item_id, user_id, created) VALUES (%d, %d, %s)', $id, $uid, Schema::now()));
        }
        $n = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::votes() . ' WHERE item_id = %d', $id));
        $wpdb->update(Schema::board(), ['votes' => $n], ['id' => $id]);
        return ['voted' => !$had, 'votes' => $n];
    }

    /** People who run the membership: status of a suggestion, and pinning. */
    public static function moderate(int $id, ?string $status, ?bool $pinned): array
    {
        global $wpdb;
        $item = self::get($id);
        if ($item === null || $item['kind'] === 'reply') {
            throw new \InvalidArgumentException(__('That post is no longer here.', 'sepo-membership'));
        }
        $f = [];
        if ($status !== null && $item['kind'] === 'suggestion' && in_array($status, self::STATUSES, true)) {
            $f['status'] = $status;
        }
        if ($pinned !== null) {
            $f['pinned'] = $pinned ? 1 : 0;
        }
        if ($f !== []) {
            $wpdb->update(Schema::board(), $f, ['id' => $id]);
        }
        return self::get($id) ?? $item;
    }

    private static function recount(int $thread): void
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS n, MAX(created) AS last FROM ' . Schema::board() . " WHERE thread = %d AND kind = 'reply'", $thread), ARRAY_A);
        $t = self::get($thread);
        if ($t === null) {
            return;
        }
        $wpdb->update(Schema::board(), ['replies' => (int) ($r['n'] ?? 0), 'last_at' => (string) ($r['last'] ?? '') !== '' ? (string) $r['last'] : (string) $t['created']], ['id' => $thread]);
    }

    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'planned'  => __('Planned', 'sepo-membership'),
            'done'     => __('Done', 'sepo-membership'),
            'declined' => __('Not planned', 'sepo-membership'),
            default    => __('Open', 'sepo-membership'),
        };
    }

    /** Everything a user wrote, for export and erasure. @return list<array<string,mixed>> */
    public static function byUser(int $uid): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::board() . ' WHERE user_id = %d ORDER BY id ASC', $uid), ARRAY_A);
        return array_map([self::class, 'row'], $rows ?: []);
    }

    /** The item as the page shows it. @return array<string,mixed> */
    public static function present(array $item, int $uid, array $voted = []): array
    {
        $u = $item['user_id'] > 0 ? get_userdata($item['user_id']) : null;
        return [
            'id'       => $item['id'],
            'kind'     => $item['kind'],
            'thread'   => $item['thread'],
            'title'    => (string) $item['title'],
            'body'     => (string) $item['body'],
            'html'     => self::format((string) $item['body']),
            'author'   => $u ? (string) $u->display_name : __('Former member', 'sepo-membership'),
            'avatar'   => $u ? (string) get_avatar_url($u->ID, ['size' => 64]) : '',
            'mine'     => $uid > 0 && $item['user_id'] === $uid,
            'canEdit'  => self::canEdit($item, $uid),
            'status'   => (string) $item['status'],
            'statusLabel' => self::statusLabel((string) $item['status']),
            'pinned'   => $item['pinned'],
            'replies'  => $item['replies'],
            'votes'    => $item['votes'],
            'voted'    => isset($voted[$item['id']]),
            'created'  => $item['created_ts'],
            'edited'   => $item['updated_ts'] > $item['created_ts'] + 60,
            'when'     => Render\Parts::ago($item['created_ts']),
            'date'     => wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $item['created_ts']),
        ];
    }

    /** Plain text to safe HTML: paragraphs, line breaks and links. */
    public static function format(string $text): string
    {
        $paras = preg_split('~\n{2,}~', trim($text)) ?: [];
        $out = '';
        foreach ($paras as $p) {
            $out .= '<p>' . nl2br(make_clickable(esc_html($p)), false) . '</p>';
        }
        $out = (string) preg_replace('~\s+rel="[^"]*"~', '', $out);
        return str_replace('<a href=', '<a rel="nofollow ugc noopener" target="_blank" href=', $out);
    }
}
