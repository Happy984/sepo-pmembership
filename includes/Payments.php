<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Every payment: membership, donation or sponsorship.
 *
 * A payment starts as pending: the payer says they paid by bank, cash, mobile
 * money or a payment link, and may attach proof. It unlocks nothing until
 * someone who runs the membership confirms it. Confirming a membership payment
 * activates the plan; confirming a donation or sponsorship counts it towards
 * goals and puts the payer on the sponsors page if they asked to be listed.
 * A payment that never arrived is rejected, which keeps the record.
 *
 * A payer who sent a payment by mistake asks to withdraw it, with a reason.
 * The payment stays open until someone who runs the membership approves the
 * withdrawal (it is then marked withdrawn and kept) or declines it (it goes
 * back to waiting). Every step is written to the activity log.
 */
final class Payments
{
    public const KINDS = ['membership', 'donation', 'sponsorship'];
    public const STATUSES = ['pending', 'withdrawal', 'paid', 'rejected', 'withdrawn'];
    /** Still open: waiting for confirmation, or waiting for a decision on a withdrawal. */
    public const OPEN = ['pending', 'withdrawal'];

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function row(array $r): array
    {
        foreach (['id', 'user_id', 'project_id', 'confirmed_by'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['amount'] = (float) $r['amount'];
        $r['listed'] = (int) $r['listed'] === 1;
        $r['recurring'] = (int) $r['recurring'] === 1;
        $proofs = json_decode((string) ($r['proofs'] ?? ''), true);
        $r['proofs'] = is_array($proofs) ? $proofs : [];
        $r['created_ts'] = Schema::ts($r['created']);
        $r['confirmed_ts'] = Schema::ts($r['confirmed_at']);
        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::payments() . ' WHERE id = %d', $id), ARRAY_A);
        return is_array($r) ? self::row($r) : null;
    }

    /**
     * Record a payment the payer says they made.
     *
     * @param array<string,mixed> $in kind, user_id, level, period, project_id, amount, method, reference, message, name, email, listed, recurring
     * @param list<array{name:string,tmp_name:string,error:int,size:int}> $files proof of payment
     * @return array<string,mixed>
     */
    public static function create(array $in, array $files = []): array
    {
        global $wpdb;
        $kind = in_array($in['kind'] ?? '', self::KINDS, true) ? (string) $in['kind'] : 'donation';
        $methods = Settings::methods($kind === 'membership' ? 'membership' : 'donation');
        $method = sanitize_key((string) ($in['method'] ?? ''));
        if (!isset($methods[$method])) {
            throw new \InvalidArgumentException(__('Choose how you paid.', 'sepo-membership'));
        }
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException(__('Enter the amount.', 'sepo-membership'));
        }
        if ($amount > 100000000) {
            throw new \InvalidArgumentException(__('That amount is too large.', 'sepo-membership'));
        }
        $proofRule = (string) Settings::get('proof');
        $files = $proofRule === 'off' ? [] : $files;
        if ($proofRule === 'required' && $methods[$method]['type'] === 'manual' && $files === []) {
            throw new \InvalidArgumentException(__('Attach proof of payment, such as a screenshot or a receipt.', 'sepo-membership'));
        }
        $email = sanitize_email((string) ($in['email'] ?? ''));
        $name = mb_substr(sanitize_text_field((string) ($in['name'] ?? '')), 0, 190);
        $uid = (int) ($in['user_id'] ?? 0);
        if ($uid > 0) {
            $u = get_userdata($uid);
            if ($u) {
                $email = $email !== '' ? $email : (string) $u->user_email;
                $name = $name !== '' ? $name : (string) $u->display_name;
            }
        }
        if ($uid < 1 && ($name === '' || !is_email($email))) {
            throw new \InvalidArgumentException(__('Enter your name and a valid email address.', 'sepo-membership'));
        }

        $wpdb->insert(Schema::payments(), [
            'user_id'    => max(0, $uid),
            'kind'       => $kind,
            'level'      => sanitize_key((string) ($in['level'] ?? '')),
            'period'     => sanitize_key((string) ($in['period'] ?? '')),
            'project_id' => max(0, (int) ($in['project_id'] ?? 0)),
            'amount'     => $amount,
            'currency'   => Settings::currency(),
            'method'     => $method,
            'reference'  => mb_substr(sanitize_text_field((string) ($in['reference'] ?? '')), 0, 190),
            'message'    => mb_substr(sanitize_textarea_field((string) ($in['message'] ?? '')), 0, 1000),
            'name'       => $name,
            'email'      => $email,
            'listed'     => !empty($in['listed']) ? 1 : 0,
            'recurring'  => !empty($in['recurring']) && $kind !== 'membership' ? 1 : 0,
            'status'     => 'pending',
            'proofs'     => '[]',
            'created'    => Schema::now(),
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id < 1) {
            throw new \RuntimeException(__('Your payment could not be recorded. Please try again.', 'sepo-membership'));
        }
        if ($files !== []) {
            try {
                self::addProofs($id, $files);
            } catch (\Throwable $e) {
                $wpdb->delete(Schema::payments(), ['id' => $id]);
                throw $e;
            }
        }
        if ($kind === 'membership' && $uid > 0) {
            Members::retotal($uid);
        }
        $p = self::get($id) ?? [];
        Log::add('submitted', $p);
        Mail::received($p);
        do_action('sepo_membership_payment_recorded', $p);
        return $p;
    }

    /**
     * Admin: record a payment that has already arrived, confirmed at once.
     *
     * @param array<string,mixed> $in
     */
    public static function record(array $in, int $by): array
    {
        global $wpdb;
        $kind = in_array($in['kind'] ?? '', self::KINDS, true) ? (string) $in['kind'] : 'membership';
        $amount = round((float) ($in['amount'] ?? 0), 2);
        if ($amount < 0) {
            throw new \InvalidArgumentException(__('Enter the amount.', 'sepo-membership'));
        }
        $uid = (int) ($in['user_id'] ?? 0);
        $u = $uid > 0 ? get_userdata($uid) : null;
        $wpdb->insert(Schema::payments(), [
            'user_id'      => $uid,
            'kind'         => $kind,
            'level'        => sanitize_key((string) ($in['level'] ?? '')),
            'period'       => sanitize_key((string) ($in['period'] ?? '')),
            'project_id'   => max(0, (int) ($in['project_id'] ?? 0)),
            'amount'       => $amount,
            'currency'     => Settings::currency(),
            'method'       => sanitize_key((string) ($in['method'] ?? '')),
            'reference'    => mb_substr(sanitize_text_field((string) ($in['reference'] ?? '')), 0, 190),
            'message'      => '',
            'name'         => $u ? (string) $u->display_name : mb_substr(sanitize_text_field((string) ($in['name'] ?? '')), 0, 190),
            'email'        => $u ? (string) $u->user_email : sanitize_email((string) ($in['email'] ?? '')),
            'listed'       => !empty($in['listed']) ? 1 : 0,
            'status'       => 'pending',
            'proofs'       => '[]',
            'admin_note'   => mb_substr(sanitize_text_field((string) ($in['note'] ?? '')), 0, 255),
            'created'      => Schema::now(),
        ]);
        return self::confirm((int) $wpdb->insert_id, $by, 'recorded', (string) ($in['note'] ?? ''));
    }

    /** The money arrived: mark it paid and give the payer what it pays for. */
    public static function confirm(int $id, int $by, string $logAs = 'confirmed', string $note = ''): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null) {
            throw new \InvalidArgumentException(__('That payment could not be found.', 'sepo-membership'));
        }
        if ($p['status'] === 'paid') {
            throw new \InvalidArgumentException(__('That payment is already confirmed.', 'sepo-membership'));
        }
        $wpdb->update(Schema::payments(), ['status' => 'paid', 'confirmed_at' => Schema::now(), 'confirmed_by' => $by], ['id' => $id]);
        $p = self::get($id) ?? $p;
        Log::add($logAs, $p, $note);
        if ($p['kind'] === 'membership' && $p['user_id'] > 0) {
            Members::applyPayment($p);
            if ($p['listed']) {
                Members::setListed($p['user_id'], true);
            }
        }
        Mail::confirmed($p);
        do_action('sepo_membership_payment_confirmed', $p);
        return $p;
    }

    /** The money never arrived. The record stays, marked rejected, with a note for the payer. */
    public static function reject(int $id, int $by, string $note = ''): void
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null) {
            throw new \InvalidArgumentException(__('That payment could not be found.', 'sepo-membership'));
        }
        $wasPaid = $p['status'] === 'paid';
        $wpdb->update(Schema::payments(), ['status' => 'rejected', 'confirmed_by' => $by, 'admin_note' => mb_substr(sanitize_text_field($note), 0, 255)], ['id' => $id]);
        if ($p['kind'] === 'membership' && $p['user_id'] > 0) {
            Members::retotal($p['user_id']);
            if ($wasPaid) {
                self::afterRemovingPaid($p['user_id']);
            }
        }
        $p = self::get($id) ?? $p;
        Log::add('rejected', $p, $note);
        Mail::rejected($p);
        do_action('sepo_membership_payment_rejected', $p);
    }

    /** Remove a payment record and its proof files (for duplicates and mistakes). */
    public static function delete(int $id, string $note = ''): void
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null) {
            return;
        }
        Log::add('deleted', $p, $note);
        Files::remove('proofs', $p['proofs']);
        $wpdb->delete(Schema::payments(), ['id' => $id]);
        if ($p['kind'] === 'membership' && $p['user_id'] > 0) {
            Members::retotal($p['user_id']);
            if ($p['status'] === 'paid') {
                self::afterRemovingPaid($p['user_id']);
            }
        }
    }

    /** With no confirmed membership payment left, a member is back to joined-but-unpaid. */
    private static function afterRemovingPaid(int $uid): void
    {
        global $wpdb;
        $paid = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::payments() . ' WHERE user_id = %d AND kind = %s AND status = %s', $uid, 'membership', 'paid'));
        if ($paid === 0 && Members::get($uid) !== null) {
            $wpdb->update(Schema::members(), ['status' => 'pending', 'started' => null, 'expires' => null, 'updated' => Schema::now()], ['user_id' => $uid]);
            Members::forget($uid);
            Bridge::sync($uid);
        }
    }

    /** The payer asks to withdraw a payment that is still waiting, and says why. */
    public static function requestWithdrawal(int $id, string $reason): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null) {
            throw new \InvalidArgumentException(__('That payment could not be found.', 'sepo-membership'));
        }
        if ($p['status'] !== 'pending') {
            throw new \InvalidArgumentException(__('Only a payment that is still waiting can be withdrawn.', 'sepo-membership'));
        }
        $reason = trim(mb_substr(sanitize_textarea_field($reason), 0, 1000));
        if (mb_strlen($reason) < 5) {
            throw new \InvalidArgumentException(__('Tell us briefly why you want to withdraw this payment.', 'sepo-membership'));
        }
        $wpdb->update(Schema::payments(), ['status' => 'withdrawal', 'withdraw_reason' => $reason], ['id' => $id]);
        $p = self::get($id) ?? $p;
        Log::add('withdraw_requested', $p, $reason);
        Mail::withdrawalRequested($p);
        do_action('sepo_membership_withdrawal_requested', $p);
        return $p;
    }

    /** The payer changes their mind before a decision: the payment waits again. */
    public static function cancelWithdrawal(int $id): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null || $p['status'] !== 'withdrawal') {
            throw new \InvalidArgumentException(__('There is no withdrawal request on that payment.', 'sepo-membership'));
        }
        $wpdb->update(Schema::payments(), ['status' => 'pending'], ['id' => $id]);
        $p = self::get($id) ?? $p;
        Log::add('withdraw_cancelled', $p);
        return $p;
    }

    /** Approve or decline a withdrawal request. The payment is kept either way. */
    public static function decideWithdrawal(int $id, bool $approve, int $by, string $note = ''): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null || $p['status'] !== 'withdrawal') {
            throw new \InvalidArgumentException(__('There is no withdrawal request on that payment.', 'sepo-membership'));
        }
        $note = mb_substr(sanitize_text_field($note), 0, 255);
        $wpdb->update(Schema::payments(), ['status' => $approve ? 'withdrawn' : 'pending', 'confirmed_by' => $by, 'admin_note' => $note], ['id' => $id]);
        $p = self::get($id) ?? $p;
        Log::add($approve ? 'withdraw_approved' : 'withdraw_declined', $p, $note);
        if ($p['kind'] === 'membership' && $p['user_id'] > 0) {
            Members::retotal($p['user_id']);
            if ($approve) {
                Members::dropIfUnpaid($p['user_id']);
            }
        }
        Mail::withdrawalDecided($p, $approve);
        do_action('sepo_membership_withdrawal_decided', $p, $approve);
        return $p;
    }

    /**
     * Attach proof to a payment.
     *
     * @param list<array{name:string,tmp_name:string,error:int,size:int}> $files
     */
    public static function addProofs(int $id, array $files): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null) {
            throw new \InvalidArgumentException(__('That payment could not be found.', 'sepo-membership'));
        }
        $stored = Files::store('proofs', $files, Files::proofMimes(), Files::maxMb(), max(1, 5 - count($p['proofs'])));
        $all = array_merge($p['proofs'], $stored);
        $wpdb->update(Schema::payments(), ['proofs' => (string) wp_json_encode($all)], ['id' => $id]);
        if ($stored !== []) {
            /* translators: %d: number of files */
            Log::add('proof_added', $p, sprintf(_n('%d file', '%d files', count($stored), 'sepo-membership'), count($stored)));
        }
        if (in_array($p['status'], self::OPEN, true) && $stored !== []) {
            Mail::proofAdded($p);
        }
        return self::get($id) ?? $p;
    }

    /**
     * @param array{kind?:string,status?:string|list<string>,user_id?:int,project_id?:int,q?:string,page?:int,per_page?:int,from?:string,to?:string} $a
     * @return array{rows:list<array<string,mixed>>,total:int,sum:float}
     */
    public static function query(array $a = []): array
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        if (!empty($a['kind']) && in_array($a['kind'], self::KINDS, true)) {
            $where[] = 'kind = %s';
            $params[] = $a['kind'];
        }
        if (!empty($a['gifts'])) {
            $where[] = "kind IN ('donation','sponsorship')";
        }
        $status = array_values(array_intersect((array) ($a['status'] ?? []), self::STATUSES));
        if ($status !== []) {
            $where[] = 'status IN (' . implode(',', array_fill(0, count($status), '%s')) . ')';
            array_push($params, ...$status);
        }
        foreach (['user_id', 'project_id'] as $k) {
            if (!empty($a[$k])) {
                $where[] = "$k = %d";
                $params[] = (int) $a[$k];
            }
        }
        if (!empty($a['q'])) {
            $like = '%' . $wpdb->esc_like((string) $a['q']) . '%';
            $where[] = '(name LIKE %s OR email LIKE %s OR reference LIKE %s)';
            array_push($params, $like, $like, $like);
        }
        if (!empty($a['from'])) {
            $where[] = 'created >= %s';
            $params[] = (string) $a['from'];
        }
        $per = max(1, min(1000, (int) ($a['per_page'] ?? 25)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $sqlWhere = implode(' AND ', $where);
        // phpcs:disable WordPress.DB.PreparedSQL -- clauses use placeholders
        $agg = $params
            ? $wpdb->get_row($wpdb->prepare('SELECT COUNT(*) AS n, SUM(amount) AS s FROM ' . Schema::payments() . " WHERE $sqlWhere", ...$params), ARRAY_A)
            : $wpdb->get_row('SELECT COUNT(*) AS n, SUM(amount) AS s FROM ' . Schema::payments() . " WHERE $sqlWhere", ARRAY_A);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::payments() . " WHERE $sqlWhere ORDER BY id DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per, ($page - 1) * $per])), ARRAY_A);
        // phpcs:enable
        return ['rows' => array_map([self::class, 'row'], $rows ?: []), 'total' => (int) ($agg['n'] ?? 0), 'sum' => (float) ($agg['s'] ?? 0)];
    }

    /** Confirmed money for a project, or for all donations and sponsorships when $projectId is 0. */
    public static function raised(int $projectId = 0): float
    {
        global $wpdb;
        if ($projectId > 0) {
            return (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(amount) FROM ' . Schema::payments() . ' WHERE project_id = %d AND status = %s', $projectId, 'paid'));
        }
        return (float) $wpdb->get_var($wpdb->prepare('SELECT SUM(amount) FROM ' . Schema::payments() . " WHERE kind IN ('donation','sponsorship') AND status = %s", 'paid'));
    }

    /** How many people have given to a project (or to donations overall). */
    public static function backers(int $projectId = 0): int
    {
        global $wpdb;
        $where = $projectId > 0 ? $wpdb->prepare('project_id = %d', $projectId) : "kind IN ('donation','sponsorship')";
        // phpcs:ignore WordPress.DB.PreparedSQL -- $where is prepared above
        return (int) $wpdb->get_var("SELECT COUNT(DISTINCT CASE WHEN user_id > 0 THEN CONCAT('u', user_id) ELSE CONCAT('e', email) END) FROM " . Schema::payments() . " WHERE $where AND status = 'paid'");
    }

    /**
     * People who gave and asked to be listed, with what they gave in total.
     *
     * @return list<array{key:string,user_id:int,name:string,amount:float,projects:list<int>,last:int}>
     */
    public static function listedGivers(int $projectId = 0): array
    {
        global $wpdb;
        $where = $projectId > 0 ? $wpdb->prepare('project_id = %d', $projectId) : "kind IN ('donation','sponsorship')";
        // phpcs:ignore WordPress.DB.PreparedSQL -- $where is prepared above
        $rows = $wpdb->get_results('SELECT user_id, email, name, amount, project_id, created FROM ' . Schema::payments() . " WHERE $where AND status = 'paid' AND listed = 1 ORDER BY id ASC", ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $r) {
            $key = (int) $r['user_id'] > 0 ? 'u' . (int) $r['user_id'] : 'e' . strtolower((string) $r['email']);
            if (!isset($out[$key])) {
                $u = (int) $r['user_id'] > 0 ? get_userdata((int) $r['user_id']) : null;
                $out[$key] = ['key' => $key, 'user_id' => (int) $r['user_id'], 'name' => $u ? (string) $u->display_name : (string) $r['name'], 'amount' => 0.0, 'projects' => [], 'last' => 0];
            }
            $out[$key]['amount'] += (float) $r['amount'];
            if ((int) $r['project_id'] > 0 && !in_array((int) $r['project_id'], $out[$key]['projects'], true)) {
                $out[$key]['projects'][] = (int) $r['project_id'];
            }
            $out[$key]['last'] = max($out[$key]['last'], Schema::ts($r['created']));
        }
        $list = array_values($out);
        usort($list, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
        return $list;
    }

    /** A month after a confirmed monthly gift, remind the giver once. */
    public static function remindRecurring(): void
    {
        global $wpdb;
        $cut = Schema::utc(time() - 30 * DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::payments() . " WHERE recurring = 1 AND status = 'paid' AND reminded IS NULL AND confirmed_at <= %s LIMIT 100", $cut), ARRAY_A);
        foreach ($rows ?: [] as $r) {
            $p = self::row($r);
            Mail::recurringReminder($p);
            $wpdb->update(Schema::payments(), ['reminded' => Schema::now()], ['id' => $p['id']]);
        }
    }

    /** The proof file link, for whoever may open it. */
    public static function proofUrl(int $paymentId, int $index): string
    {
        return add_query_arg(['action' => 'smem_proof', 'payment' => $paymentId, 'file' => $index, '_wpnonce' => wp_create_nonce('smem_proof_' . $paymentId)], admin_url('admin-post.php'));
    }

    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            'membership'  => __('Membership', 'sepo-membership'),
            'sponsorship' => __('Sponsorship', 'sepo-membership'),
            default       => __('Donation', 'sepo-membership'),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'paid'       => __('Confirmed', 'sepo-membership'),
            'rejected'   => __('Not received', 'sepo-membership'),
            'withdrawal' => __('Withdrawal requested', 'sepo-membership'),
            'withdrawn'  => __('Withdrawn', 'sepo-membership'),
            default    => __('Awaiting confirmation', 'sepo-membership'),
        };
    }

    public static function methodLabel(string $id): string
    {
        $all = Settings::methods();
        return $all[$id]['label'] ?? ($id !== '' ? ucfirst($id) : __('Recorded by hand', 'sepo-membership'));
    }
}
