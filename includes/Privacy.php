<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Personal data: the privacy-policy text, WordPress's export and erase
 * tools, and tidying up when an account is deleted.
 *
 * Erasing removes the membership, votes and authorship of board posts, and
 * strips names, emails, messages and proofs from payments. The amounts and
 * dates of confirmed payments are kept as anonymous accounting records.
 */
final class Privacy
{
    public static function hook(): void
    {
        add_action('admin_init', [self::class, 'policy']);
        add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
            $e['sepo-membership'] = ['exporter_friendly_name' => __('Sepo Membership', 'sepo-membership'), 'callback' => [self::class, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
            $e['sepo-membership'] = ['eraser_friendly_name' => __('Sepo Membership', 'sepo-membership'), 'callback' => [self::class, 'erase']];
            return $e;
        });
        add_action('deleted_user', [self::class, 'userDeleted']);
    }

    public static function policy(): void
    {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }
        wp_add_privacy_policy_content(__('Sepo Membership', 'sepo-membership'), wp_kses_post('<p>'
            . __('When you join, renew or give, we store your name, email address, the plan or project, the amount, the payment method, any reference and message you enter, and any proof of payment you upload. Proofs are kept in a private folder and seen only by the people who confirm payments.', 'sepo-membership')
            . '</p><p>'
            . __('If you choose to be listed, your name (and, for members, your plan) is shown on the sponsors page. You can change this at any time on your membership page.', 'sepo-membership')
            . '</p><p>'
            . __('Posts, replies and votes in the members area are stored with your account and shown to other members.', 'sepo-membership')
            . '</p>'));
    }

    /** @return list<int> */
    private static function paymentIds(string $email, int $uid): array
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::payments() . ' WHERE email = %s OR (user_id > 0 AND user_id = %d)', $email, $uid)) ?: []);
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        $u = get_user_by('email', $email);
        $uid = $u ? (int) $u->ID : 0;
        $data = [];
        if ($page === 1 && $uid > 0) {
            $m = Members::get($uid);
            if ($m !== null) {
                $data[] = ['group_id' => 'smem-membership', 'group_label' => __('Membership', 'sepo-membership'), 'item_id' => 'smem-member-' . $uid, 'data' => [
                    ['name' => __('Plan', 'sepo-membership'), 'value' => Tiers::label((string) $m['level'])],
                    ['name' => __('Billing period', 'sepo-membership'), 'value' => Tiers::periodLabel((string) $m['period'])],
                    ['name' => __('Status', 'sepo-membership'), 'value' => Members::status($uid)],
                    ['name' => __('Member since', 'sepo-membership'), 'value' => (string) $m['started']],
                    ['name' => __('Expires', 'sepo-membership'), 'value' => (string) $m['expires']],
                    ['name' => __('Listed on sponsors page', 'sepo-membership'), 'value' => $m['listed'] ? __('Yes', 'sepo-membership') : __('No', 'sepo-membership')],
                ]];
            }
            foreach (Board::byUser($uid) as $b) {
                $data[] = ['group_id' => 'smem-board', 'group_label' => __('Members area posts', 'sepo-membership'), 'item_id' => 'smem-board-' . $b['id'], 'data' => [
                    ['name' => __('Kind', 'sepo-membership'), 'value' => $b['kind']],
                    ['name' => __('Title', 'sepo-membership'), 'value' => (string) $b['title']],
                    ['name' => __('Text', 'sepo-membership'), 'value' => (string) $b['body']],
                    ['name' => __('Date', 'sepo-membership'), 'value' => (string) $b['created']],
                ]];
            }
        }
        if ($page === 1) {
            foreach (self::paymentIds($email, $uid) as $id) {
                $p = Payments::get($id);
                if ($p === null) {
                    continue;
                }
                $data[] = ['group_id' => 'smem-payments', 'group_label' => __('Payments', 'sepo-membership'), 'item_id' => 'smem-payment-' . $id, 'data' => [
                    ['name' => __('Kind', 'sepo-membership'), 'value' => Payments::kindLabel((string) $p['kind'])],
                    ['name' => __('Amount', 'sepo-membership'), 'value' => Settings::money((float) $p['amount'], (string) $p['currency'])],
                    ['name' => __('Method', 'sepo-membership'), 'value' => Payments::methodLabel((string) $p['method'])],
                    ['name' => __('Reference', 'sepo-membership'), 'value' => (string) $p['reference']],
                    ['name' => __('Message', 'sepo-membership'), 'value' => (string) $p['message']],
                    ['name' => __('Name', 'sepo-membership'), 'value' => (string) $p['name']],
                    ['name' => __('Email', 'sepo-membership'), 'value' => (string) $p['email']],
                    ['name' => __('Status', 'sepo-membership'), 'value' => Payments::statusLabel((string) $p['status'])],
                    ['name' => __('Reason for withdrawing', 'sepo-membership'), 'value' => (string) ($p['withdraw_reason'] ?? '')],
                    ['name' => __('Date', 'sepo-membership'), 'value' => (string) $p['created']],
                    ['name' => __('History', 'sepo-membership'), 'value' => implode('; ', array_map(static fn(array $r): string => $r['created'] . ' ' . $r['label'] . ((string) $r['note'] !== '' ? ': ' . $r['note'] : ''), Log::forPayment($id)))],
                ]];
            }
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        global $wpdb;
        $u = get_user_by('email', $email);
        $uid = $u ? (int) $u->ID : 0;
        $removed = false;
        $ids = self::paymentIds($email, $uid);
        $retained = false;
        foreach (self::paymentIds($email, $uid) as $id) {
            $p = Payments::get($id);
            if ($p === null) {
                continue;
            }
            Files::remove('proofs', $p['proofs']);
            if ($p['status'] === 'paid') {
                $wpdb->update(Schema::payments(), ['name' => '', 'email' => '', 'message' => '', 'reference' => '', 'withdraw_reason' => '', 'proofs' => '[]', 'listed' => 0, 'user_id' => 0], ['id' => $id]);
                $retained = true;
            } else {
                $wpdb->delete(Schema::payments(), ['id' => $id]);
            }
            $removed = true;
        }
        Log::anonymise($uid, $ids);
        if ($uid > 0) {
            $removed = self::detach($uid) || $removed;
        }
        return [
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $retained ? [__('Confirmed payments are kept, without any name or contact details, as accounting records.', 'sepo-membership')] : [],
            'done'           => true,
        ];
    }

    /** An account is gone: its membership and votes go too, and its posts stay without an author. */
    public static function userDeleted(int $uid): void
    {
        global $wpdb;
        self::detach($uid);
        $wpdb->update(Schema::payments(), ['user_id' => 0], ['user_id' => $uid]);
    }

    private static function detach(int $uid): bool
    {
        global $wpdb;
        $n = (int) $wpdb->delete(Schema::members(), ['user_id' => $uid]);
        $voted = (int) $wpdb->delete(Schema::votes(), ['user_id' => $uid]);
        if ($voted > 0) {
            $wpdb->query('UPDATE ' . Schema::board() . ' b SET votes = (SELECT COUNT(*) FROM ' . Schema::votes() . " v WHERE v.item_id = b.id) WHERE b.kind = 'suggestion'");
        }
        $n += $voted;
        $n += (int) $wpdb->update(Schema::board(), ['user_id' => 0], ['user_id' => $uid]);
        Members::forget($uid);
        delete_user_meta($uid, '_smem_board_mail_off');
        delete_user_meta($uid, '_smem_lapse_notice');
        delete_user_meta($uid, Bridge::SYNCED_META);
        return $n > 0;
    }
}
