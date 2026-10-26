<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * The REST routes behind the pages: joining and paying, the member's own
 * account, donations, and the members' board.
 *
 * Signed-in requests carry the REST nonce. A guest donation has no session to
 * protect, so it is accepted without one, behind a hidden form field that
 * people never fill and a per-address rate limit.
 */
final class Rest
{
    public const NS = 'sepo-membership/v1';

    public static function hook(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $user = static fn(): bool => is_user_logged_in();
        $open = '__return_true';

        $r = static function (string $path, string $methods, callable $cb, callable|string $perm) {
            register_rest_route(self::NS, $path, ['methods' => $methods, 'callback' => $cb, 'permission_callback' => $perm]);
        };

        $r('/join', 'POST', [self::class, 'join'], $user);
        $r('/cancel', 'POST', [self::class, 'cancel'], $user);
        $r('/resume', 'POST', [self::class, 'resume'], $user);
        $r('/listing', 'POST', [self::class, 'listing'], $user);
        $r('/preferences', 'POST', [self::class, 'preferences'], $user);
        $r('/payments/(?P<id>\d+)/proof', 'POST', [self::class, 'proof'], $user);
        $r('/payments/(?P<id>\d+)/withdraw', 'POST', [self::class, 'withdraw'], $user);
        $r('/payments/(?P<id>\d+)/keep', 'POST', [self::class, 'keep'], $user);
        $r('/donate', 'POST', [self::class, 'donate'], $open);

        $r('/board', 'GET', [self::class, 'boardList'], $user);
        $r('/board', 'POST', [self::class, 'boardCreate'], $user);
        $r('/board/(?P<id>\d+)', 'GET', [self::class, 'boardThread'], $user);
        $r('/board/(?P<id>\d+)', 'POST', [self::class, 'boardUpdate'], $user);
        $r('/board/(?P<id>\d+)', 'DELETE', [self::class, 'boardRemove'], $user);
        $r('/board/(?P<id>\d+)/reply', 'POST', [self::class, 'boardReply'], $user);
        $r('/board/(?P<id>\d+)/vote', 'POST', [self::class, 'boardVote'], $user);
        $r('/board/(?P<id>\d+)/moderate', 'POST', [self::class, 'boardModerate'], static fn(): bool => Plugin::canManage());
    }

    private static function fail(string $message, int $status = 400, string $code = 'smem_error'): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }

    /** Run a callback, turning the plugin's exceptions into REST errors. */
    private static function guard(callable $cb)
    {
        try {
            return $cb();
        } catch (\DomainException $e) {
            return self::fail($e->getMessage(), 403);
        } catch (\InvalidArgumentException $e) {
            return self::fail($e->getMessage(), 422);
        } catch (\Throwable $e) {
            return self::fail(__('Something went wrong. Please try again.', 'sepo-membership'), 500);
        }
    }

    /** Uploaded proof files, under proof[]. @return list<array<string,mixed>> */
    private static function files(\WP_REST_Request $req): array
    {
        $f = $req->get_file_params();
        return isset($f['proof']) && is_array($f['proof']) ? Files::fromUpload($f['proof']) : [];
    }

    private static function flag(\WP_REST_Request $req, string $key): bool
    {
        $v = $req->get_param($key);
        return in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    // ---------------------------------------------------------- membership

    /**
     * Choose a plan. Without a payment method this only says what choosing it
     * means (payment, scheduled, unchanged or free) and changes nothing that
     * needs paying for; free plans and downgrades take effect here.
     */
    public static function join(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $uid = get_current_user_id();
            $level = sanitize_key((string) $req->get_param('level'));
            $period = sanitize_key((string) $req->get_param('period'));
            $renew = self::flag($req, 'renew');
            $method = sanitize_key((string) $req->get_param('method'));
            $tier = Tiers::joinable()[$level] ?? null;
            if ($tier === null) {
                throw new \InvalidArgumentException(__('That plan is not available.', 'sepo-membership'));
            }

            $look = Members::request($uid, $level, $period, $renew, true);
            if ($look['effect'] !== 'payment') {
                $done = Members::request($uid, $level, $period, $renew);
                return self::joined($uid, $done['effect']);
            }
            if ($method === '') {
                return ['ok' => true, 'effect' => 'payment', 'amount' => $look['amount'], 'money' => Settings::money($look['amount'])];
            }
            $waiting = Payments::query(['user_id' => $uid, 'kind' => 'membership', 'status' => Payments::OPEN, 'per_page' => 1])['rows'];
            if ($waiting !== []) {
                throw new \InvalidArgumentException(__('You already have a membership payment waiting for confirmation. Add proof to it, or withdraw it, from your membership page.', 'sepo-membership'));
            }
            Members::request($uid, $level, $period, $renew);
            $p = Payments::create([
                'kind'      => 'membership',
                'user_id'   => $uid,
                'level'     => $level,
                'period'    => $period,
                'amount'    => $look['amount'],
                'method'    => $method,
                'reference' => (string) $req->get_param('reference'),
                'message'   => (string) $req->get_param('message'),
                'listed'    => self::flag($req, 'listed'),
            ], self::files($req));
            return self::joined($uid, 'payment', $p);
        });
    }

    /** @return array<string,mixed> */
    private static function joined(int $uid, string $effect, array $payment = []): array
    {
        $m = Members::get($uid);
        $messages = [
            'payment'   => __('Thank you. Your payment is recorded and waiting for confirmation. Your plan opens as soon as it is confirmed, and we will email you.', 'sepo-membership'),
            'scheduled' => __('Your plan will change when your current one runs out. Until then you keep everything you have now.', 'sepo-membership'),
            'unchanged' => __('That is already your plan.', 'sepo-membership'),
            'free'      => __('Welcome. Your membership is active.', 'sepo-membership'),
        ];
        return [
            'ok'       => true,
            'effect'   => $effect,
            'message'  => $messages[$effect] ?? '',
            'status'   => Members::status($uid),
            'level'    => $m['level'] ?? '',
            'pending'  => $m['pending_level'] ?? '',
            'payment'  => $payment !== [] ? (int) $payment['id'] : 0,
            'redirect' => Settings::pageUrl('account_page'),
        ];
    }

    public static function cancel(\WP_REST_Request $req)
    {
        $uid = get_current_user_id();
        if (Members::status($uid) !== 'active' || Members::isEnding($uid)) {
            return self::fail(__('There is no active plan to cancel.', 'sepo-membership'), 422);
        }
        Members::cancel($uid);
        Log::add('cancelled', null, '', $uid);
        return ['ok' => true, 'message' => __('Your plan is cancelled. You keep your access until the end of the time you paid for.', 'sepo-membership')];
    }

    public static function resume(\WP_REST_Request $req)
    {
        $uid = get_current_user_id();
        if (!Members::isEnding($uid)) {
            return self::fail(__('There is nothing to resume.', 'sepo-membership'), 422);
        }
        Members::resume($uid);
        Log::add('resumed', null, '', $uid);
        return ['ok' => true, 'message' => __('Your plan is back on.', 'sepo-membership')];
    }

    public static function listing(\WP_REST_Request $req)
    {
        $uid = get_current_user_id();
        if (Members::get($uid) === null) {
            return self::fail(__('Join first, then you can choose to be listed.', 'sepo-membership'), 422);
        }
        $on = self::flag($req, 'listed');
        Members::setListed($uid, $on);
        return ['ok' => true, 'listed' => $on, 'message' => $on ? __('You will be shown on the sponsors page while your plan is active.', 'sepo-membership') : __('You are no longer shown on the sponsors page.', 'sepo-membership')];
    }

    public static function preferences(\WP_REST_Request $req)
    {
        $uid = get_current_user_id();
        $off = !self::flag($req, 'board_mail');
        update_user_meta($uid, '_smem_board_mail_off', $off ? 1 : 0);
        return ['ok' => true, 'message' => __('Saved.', 'sepo-membership')];
    }

    /** The payer adds proof to their own waiting payment. */
    public static function proof(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $p = Payments::get((int) $req['id']);
            if ($p === null || $p['user_id'] !== get_current_user_id()) {
                return self::fail(__('That payment could not be found.', 'sepo-membership'), 404);
            }
            if (!in_array($p['status'], Payments::OPEN, true)) {
                throw new \InvalidArgumentException(__('That payment has already been dealt with.', 'sepo-membership'));
            }
            $files = self::files($req);
            if ($files === []) {
                throw new \InvalidArgumentException(__('Choose a file to attach.', 'sepo-membership'));
            }
            $p = Payments::addProofs($p['id'], $files);
            return ['ok' => true, 'count' => count($p['proofs']), 'message' => __('Proof attached. Thank you.', 'sepo-membership')];
        });
    }

    /**
     * The payer asks to withdraw a payment that is still waiting, with a
     * reason. Nothing is removed: the request waits for a decision.
     */
    public static function withdraw(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $p = Payments::get((int) $req['id']);
            if ($p === null || $p['user_id'] !== get_current_user_id()) {
                return self::fail(__('That payment could not be found.', 'sepo-membership'), 404);
            }
            Payments::requestWithdrawal($p['id'], (string) $req->get_param('reason'));
            return ['ok' => true, 'message' => __('Your request is sent. We will email you when it has been dealt with.', 'sepo-membership')];
        });
    }

    /** The payer takes back their withdrawal request; the payment waits for confirmation again. */
    public static function keep(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $p = Payments::get((int) $req['id']);
            if ($p === null || $p['user_id'] !== get_current_user_id()) {
                return self::fail(__('That payment could not be found.', 'sepo-membership'), 404);
            }
            Payments::cancelWithdrawal($p['id']);
            return ['ok' => true, 'message' => __('Your payment is waiting for confirmation again.', 'sepo-membership')];
        });
    }

    // ---------------------------------------------------------- donations

    public static function donate(\WP_REST_Request $req)
    {
        return self::guard(static function () use ($req) {
            $uid = get_current_user_id();
            if ($uid < 1 && (int) Settings::get('guest_donations') !== 1) {
                return self::fail(__('Please sign in to give.', 'sepo-membership'), 401);
            }
            if (trim((string) $req->get_param('website')) !== '') {
                // The hidden field was filled, so this is not a person. Answer as if accepted.
                return ['ok' => true, 'message' => __('Thank you.', 'sepo-membership')];
            }
            $ip = preg_replace('~[^0-9a-f:.]~i', '', (string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: 'x';
            $key = 'smem_give_' . ($uid > 0 ? 'u' . $uid : md5($ip));
            $n = (int) get_transient($key);
            if ($n >= ($uid > 0 ? 20 : 6)) {
                return self::fail(__('You have sent several gifts in a short time. Please wait an hour and try again.', 'sepo-membership'), 429);
            }
            $project = (int) $req->get_param('project');
            if ($project > 0) {
                $post = get_post($project);
                if (!$post instanceof \WP_Post || $post->post_type !== PostTypes::PROJECT || $post->post_status !== 'publish') {
                    throw new \InvalidArgumentException(__('That project is not taking gifts.', 'sepo-membership'));
                }
                if (Projects::isClosed($project)) {
                    throw new \InvalidArgumentException(__('This project has closed. Thank you for thinking of it.', 'sepo-membership'));
                }
            }
            $p = Payments::create([
                'kind'       => $project > 0 ? 'sponsorship' : 'donation',
                'user_id'    => $uid,
                'project_id' => $project,
                'amount'     => (float) $req->get_param('amount'),
                'method'     => (string) $req->get_param('method'),
                'reference'  => (string) $req->get_param('reference'),
                'message'    => (string) $req->get_param('message'),
                'name'       => (string) $req->get_param('name'),
                'email'      => (string) $req->get_param('email'),
                'listed'     => self::flag($req, 'listed'),
                'recurring'  => self::flag($req, 'recurring'),
            ], self::files($req));
            set_transient($key, $n + 1, HOUR_IN_SECONDS);
            return ['ok' => true, 'payment' => (int) $p['id'], 'message' => __('Thank you. Your gift is recorded and will be counted as soon as we confirm it arrived. We have emailed you a copy.', 'sepo-membership')];
        });
    }

    // ---------------------------------------------------------- board

    /**
     * The area a board request belongs to, and whether this person may use
     * the section. Fails closed.
     *
     * @return array{cfg:array<string,string>,page:int}|\WP_Error
     */
    private static function boardArea(\WP_REST_Request $req, string $kind)
    {
        $page = (int) $req->get_param('area');
        $cfg = Resources::areaConfig($page);
        if ($cfg === null) {
            return self::fail(__('That members area could not be found.', 'sepo-membership'), 404);
        }
        $section = $kind === 'suggestion' ? 'suggestions' : 'discussions';
        $uid = get_current_user_id();
        if (!Resources::shows($cfg, $section) || Resources::access($uid, $cfg['tier']) !== 'ok' || !Resources::can($uid, $cfg[$section . '_tier'])) {
            return self::fail(__('This part of the members area is not included in your plan.', 'sepo-membership'), 403);
        }
        return ['cfg' => $cfg, 'page' => $page];
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>}|\WP_Error the thread item (or reply) and its area */
    private static function boardItem(\WP_REST_Request $req)
    {
        $item = Board::get((int) $req['id']);
        if ($item === null) {
            return self::fail(__('That post is no longer here.', 'sepo-membership'), 404);
        }
        $top = $item['kind'] === 'reply' ? Board::get($item['thread']) : $item;
        if ($top === null) {
            return self::fail(__('That post is no longer here.', 'sepo-membership'), 404);
        }
        $a = self::boardArea($req, $top['kind']);
        if ($a instanceof \WP_Error) {
            return $a;
        }
        if ($item['area'] !== $a['cfg']['board']) {
            return self::fail(__('That post is no longer here.', 'sepo-membership'), 404);
        }
        return [$item, $a['cfg']];
    }

    public static function boardList(\WP_REST_Request $req)
    {
        $kind = (string) $req->get_param('kind') === 'suggestion' ? 'suggestion' : 'post';
        $a = self::boardArea($req, $kind);
        if ($a instanceof \WP_Error) {
            return $a;
        }
        $uid = get_current_user_id();
        $res = Board::threads($a['cfg']['board'], $kind, sanitize_key((string) $req->get_param('sort')), (int) $req->get_param('page'), sanitize_key((string) $req->get_param('status')));
        $voted = $kind === 'suggestion' ? Board::votedBy($uid, array_column($res['rows'], 'id')) : [];
        return [
            'ok'    => true,
            'items' => array_map(static fn(array $i): array => Board::present($i, $uid, $voted), $res['rows']),
            'total' => $res['total'],
            'pages' => $res['pages'],
        ];
    }

    public static function boardThread(\WP_REST_Request $req)
    {
        $got = self::boardItem($req);
        if ($got instanceof \WP_Error) {
            return $got;
        }
        [$item] = $got;
        if ($item['kind'] === 'reply') {
            $item = Board::get($item['thread']) ?? $item;
        }
        $uid = get_current_user_id();
        $voted = Board::votedBy($uid, [$item['id']]);
        return [
            'ok'      => true,
            'item'    => Board::present($item, $uid, $voted),
            'replies' => array_map(static fn(array $r): array => Board::present($r, $uid), Board::replies($item['id'])),
        ];
    }

    public static function boardCreate(\WP_REST_Request $req)
    {
        $kind = (string) $req->get_param('kind') === 'suggestion' ? 'suggestion' : 'post';
        $a = self::boardArea($req, $kind);
        if ($a instanceof \WP_Error) {
            return $a;
        }
        return self::guard(static function () use ($req, $a, $kind) {
            $uid = get_current_user_id();
            $item = Board::create($a['cfg']['board'], $kind, $uid, (string) $req->get_param('title'), (string) $req->get_param('body'));
            return ['ok' => true, 'item' => Board::present($item, $uid)];
        });
    }

    public static function boardReply(\WP_REST_Request $req)
    {
        $got = self::boardItem($req);
        if ($got instanceof \WP_Error) {
            return $got;
        }
        return self::guard(static function () use ($req, $got) {
            [$item] = $got;
            $uid = get_current_user_id();
            $reply = Board::reply($item['kind'] === 'reply' ? $item['thread'] : $item['id'], $uid, (string) $req->get_param('body'));
            return ['ok' => true, 'item' => Board::present($reply, $uid)];
        });
    }

    public static function boardUpdate(\WP_REST_Request $req)
    {
        $got = self::boardItem($req);
        if ($got instanceof \WP_Error) {
            return $got;
        }
        return self::guard(static function () use ($req, $got) {
            [$item] = $got;
            $uid = get_current_user_id();
            $item = Board::update($item['id'], $uid, (string) $req->get_param('title'), (string) $req->get_param('body'));
            return ['ok' => true, 'item' => Board::present($item, $uid, Board::votedBy($uid, [$item['id']]))];
        });
    }

    public static function boardRemove(\WP_REST_Request $req)
    {
        $got = self::boardItem($req);
        if ($got instanceof \WP_Error) {
            return $got;
        }
        return self::guard(static function () use ($got) {
            [$item] = $got;
            Board::remove($item['id'], get_current_user_id());
            return ['ok' => true, 'message' => __('Removed.', 'sepo-membership')];
        });
    }

    public static function boardVote(\WP_REST_Request $req)
    {
        $got = self::boardItem($req);
        if ($got instanceof \WP_Error) {
            return $got;
        }
        return self::guard(static function () use ($got) {
            [$item] = $got;
            return ['ok' => true] + Board::vote($item['id'], get_current_user_id());
        });
    }

    public static function boardModerate(\WP_REST_Request $req)
    {
        $got = self::boardItem($req);
        if ($got instanceof \WP_Error) {
            return $got;
        }
        return self::guard(static function () use ($req, $got) {
            [$item] = $got;
            $status = $req->has_param('status') ? sanitize_key((string) $req->get_param('status')) : null;
            $pinned = $req->has_param('pinned') ? self::flag($req, 'pinned') : null;
            $item = Board::moderate($item['id'], $status, $pinned);
            $uid = get_current_user_id();
            return ['ok' => true, 'item' => Board::present($item, $uid, Board::votedBy($uid, [$item['id']]))];
        });
    }
}
