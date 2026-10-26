<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/** Emails to payers and to the people who confirm payments. */
final class Mail
{
    private static function send(string $to, string $subject, string $body): void
    {
        if (!is_email($to)) {
            return;
        }
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        wp_mail($to, '[' . $site . '] ' . $subject, $body);
    }

    private static function admins(): string
    {
        $to = trim((string) Settings::get('notify_email'));
        return $to !== '' ? $to : (string) get_option('admin_email');
    }

    /** What the payment is for, in a few words. */
    private static function what(array $p): string
    {
        if ($p['kind'] === 'membership') {
            /* translators: 1: plan name, 2: billing period */
            return sprintf(__('%1$s membership (%2$s)', 'sepo-membership'), Tiers::label((string) $p['level']), mb_strtolower(Tiers::periodLabel((string) $p['period'])));
        }
        if ($p['project_id'] > 0) {
            /* translators: %s: project name */
            return sprintf(__('sponsorship of %s', 'sepo-membership'), get_the_title((int) $p['project_id']));
        }
        return __('donation', 'sepo-membership');
    }

    private static function account(): string
    {
        $url = Settings::pageUrl('account_page');
        return $url !== '' ? "\n\n" . $url : '';
    }

    public static function received(array $p): void
    {
        $money = Settings::money((float) $p['amount'], (string) $p['currency']);
        $method = Settings::methods()[$p['method']] ?? null;
        $detail = $method && $method['type'] === 'manual' && trim($method['detail']) !== '' ? "\n\n" . __('Payment details:', 'sepo-membership') . "\n" . $method['detail'] : '';
        /* translators: %s: amount */
        self::send((string) $p['email'], sprintf(__('We have your payment of %s', 'sepo-membership'), $money), sprintf(
            /* translators: 1: what it is for, 2: amount */
            __("Thank you. We have recorded your %1\$s of %2\$s.\n\nWe confirm payments by hand, usually within a working day. You will get another email when it is confirmed.", 'sepo-membership'),
            self::what($p),
            $money
        ) . $detail . ($p['kind'] === 'membership' ? self::account() : ''));

        if ((int) Settings::get('notify_payments') === 1) {
            /* translators: 1: amount, 2: payer name */
            self::send(self::admins(), sprintf(__('Payment to confirm: %1$s from %2$s', 'sepo-membership'), $money, $p['name']), sprintf(
                /* translators: 1: payer, 2: what for, 3: amount, 4: method, 5: reference, 6: link */
                __("%1\$s says they paid for a %2\$s.\n\nAmount: %3\$s\nMethod: %4\$s\nReference: %5\$s\n\nConfirm or reject it here:\n%6\$s", 'sepo-membership'),
                $p['name'] . ' <' . $p['email'] . '>',
                self::what($p),
                $money,
                Payments::methodLabel((string) $p['method']),
                $p['reference'] !== '' ? $p['reference'] : '—',
                admin_url('admin.php?page=sepo-membership-payments&status=pending')
            ));
        }
    }

    public static function proofAdded(array $p): void
    {
        if ((int) Settings::get('notify_payments') !== 1) {
            return;
        }
        /* translators: %s: payer name */
        self::send(self::admins(), sprintf(__('Proof of payment added by %s', 'sepo-membership'), $p['name']), admin_url('admin.php?page=sepo-membership-payments&status=pending'));
    }

    public static function confirmed(array $p): void
    {
        $money = Settings::money((float) $p['amount'], (string) $p['currency']);
        if ($p['kind'] === 'membership') {
            $m = Members::get((int) $p['user_id']);
            $until = $m && $m['expires_ts'] > 0 ? "\n" . sprintf(/* translators: %s: date */ __('Your plan runs until %s.', 'sepo-membership'), wp_date((string) get_option('date_format'), $m['expires_ts'])) : '';
            /* translators: %s: plan name */
            self::send((string) $p['email'], sprintf(__('Your %s membership is active', 'sepo-membership'), Tiers::label((string) $p['level'])), sprintf(
                /* translators: 1: amount, 2: plan */
                __("Your payment of %1\$s is confirmed and your %2\$s membership is active. Thank you for your support.", 'sepo-membership'),
                $money,
                Tiers::label((string) $p['level'])
            ) . $until . self::account());
            return;
        }
        self::send((string) $p['email'], __('Thank you for your gift', 'sepo-membership'), sprintf(
            /* translators: 1: amount, 2: what for */
            __("Your %2\$s of %1\$s has arrived. Thank you — it makes a real difference.", 'sepo-membership'),
            $money,
            self::what($p)
        ) . ($p['listed'] && Settings::pageUrl('sponsors_page') !== '' ? "\n\n" . __('You are listed on our sponsors page:', 'sepo-membership') . "\n" . Settings::pageUrl('sponsors_page') : ''));
    }

    public static function rejected(array $p): void
    {
        $money = Settings::money((float) $p['amount'], (string) $p['currency']);
        /* translators: %s: amount */
        self::send((string) $p['email'], sprintf(__('We could not find your payment of %s', 'sepo-membership'), $money), sprintf(
            /* translators: 1: amount, 2: what for */
            __("We could not match your payment of %1\$s for a %2\$s to money received.", 'sepo-membership'),
            $money,
            self::what($p)
        ) . ((string) $p['admin_note'] !== '' ? "\n\n" . $p['admin_note'] : '') . "\n\n" . __('If you did pay, reply to this email with your receipt and we will sort it out.', 'sepo-membership'));
    }

    public static function expiringSoon(int $uid): void
    {
        $u = get_userdata($uid);
        $m = Members::get($uid);
        if (!$u || $m === null) {
            return;
        }
        $renew = Settings::pageUrl('account_page');
        /* translators: %s: plan name */
        self::send((string) $u->user_email, sprintf(__('Your %s membership ends soon', 'sepo-membership'), Tiers::label((string) $m['level'])), sprintf(
            /* translators: 1: plan, 2: date */
            __("Your %1\$s membership runs until %2\$s. Renew before then to keep your access without a break.", 'sepo-membership'),
            Tiers::label((string) $m['level']),
            wp_date((string) get_option('date_format'), $m['expires_ts'])
        ) . ($renew !== '' ? "\n\n" . $renew : ''));
    }

    public static function expired(int $uid): void
    {
        $u = get_userdata($uid);
        $m = Members::get($uid);
        if (!$u || $m === null) {
            return;
        }
        /* translators: %s: plan name */
        self::send((string) $u->user_email, sprintf(__('Your %s membership has ended', 'sepo-membership'), Tiers::label((string) $m['level'])), __('Your membership has run out, so members-only content is locked for now. Renew any time to pick up where you left off.', 'sepo-membership') . self::account());
    }

    public static function withdrawalRequested(array $p): void
    {
        $money = Settings::money((float) $p['amount'], (string) $p['currency']);
        /* translators: %s: amount */
        self::send((string) $p['email'], sprintf(__('We have your request to withdraw %s', 'sepo-membership'), $money), sprintf(
            /* translators: 1: what for, 2: amount */
            __("You asked to withdraw your %1\$s of %2\$s. We will look at it and email you when it has been dealt with.", 'sepo-membership'),
            self::what($p),
            $money
        ) . "\n\n" . __('Your reason:', 'sepo-membership') . "\n" . (string) $p['withdraw_reason']);
        if ((int) Settings::get('notify_payments') === 1) {
            /* translators: 1: payer, 2: amount */
            self::send(self::admins(), sprintf(__('Withdrawal request: %1$s, %2$s', 'sepo-membership'), $p['name'], $money), sprintf(
                /* translators: 1: payer, 2: what for, 3: amount, 4: reason, 5: link */
                __("%1\$s asked to withdraw a %2\$s of %3\$s.\n\nReason:\n%4\$s\n\nApprove or decline it here:\n%5\$s", 'sepo-membership'),
                $p['name'] . ' <' . $p['email'] . '>',
                self::what($p),
                $money,
                (string) $p['withdraw_reason'],
                admin_url('admin.php?page=sepo-membership-payments&status=withdrawal')
            ));
        }
    }

    public static function withdrawalDecided(array $p, bool $approved): void
    {
        $money = Settings::money((float) $p['amount'], (string) $p['currency']);
        $note = (string) $p['admin_note'] !== '' ? "\n\n" . (string) $p['admin_note'] : '';
        if ($approved) {
            /* translators: %s: amount */
            self::send((string) $p['email'], sprintf(__('Your payment of %s is withdrawn', 'sepo-membership'), $money), sprintf(
                /* translators: 1: what for, 2: amount */
                __("We have withdrawn your %1\$s of %2\$s as you asked. If money did leave your account, reply to this email and we will arrange to return it.", 'sepo-membership'),
                self::what($p),
                $money
            ) . $note);
            return;
        }
        /* translators: %s: amount */
        self::send((string) $p['email'], sprintf(__('Your payment of %s is still open', 'sepo-membership'), $money), sprintf(
            /* translators: 1: what for, 2: amount */
            __("We looked at your request to withdraw your %1\$s of %2\$s and have kept the payment open.", 'sepo-membership'),
            self::what($p),
            $money
        ) . $note . self::account());
    }

    /** Someone answered a member's discussion or suggestion. */
    public static function boardReply(array $thread, array $reply): void
    {
        $to = get_userdata((int) $thread['user_id']);
        $from = get_userdata((int) $reply['user_id']);
        if (!$to || (int) get_user_meta($to->ID, '_smem_board_mail_off', true) === 1) {
            return;
        }
        $url = '';
        if (str_starts_with((string) $thread['area'], 'p')) {
            $page = (int) substr((string) $thread['area'], 1);
            $url = $page > 0 ? add_query_arg(['smem_tab' => $thread['kind'] === 'suggestion' ? 'suggestions' : 'discussions', 'smem_item' => (int) $thread['id']], (string) get_permalink($page)) : '';
        }
        self::send((string) $to->user_email, sprintf(
            /* translators: %s: title of the discussion */
            __('New reply: %s', 'sepo-membership'),
            (string) $thread['title']
        ), sprintf(
            /* translators: 1: name of who replied, 2: title */
            __("%1\$s replied to \"%2\$s\":", 'sepo-membership'),
            $from ? (string) $from->display_name : __('A member', 'sepo-membership'),
            (string) $thread['title']
        ) . "\n\n" . wp_trim_words((string) $reply['body'], 60) . ($url !== '' ? "\n\n" . $url : ''));
    }

    public static function recurringReminder(array $p): void
    {
        $donate = $p['project_id'] > 0 ? (string) get_permalink((int) $p['project_id']) : Settings::pageUrl('donate_page');
        self::send((string) $p['email'], __('Your monthly gift', 'sepo-membership'), sprintf(
            /* translators: %s: amount */
            __("A month ago you chose to give %s each month. Thank you. When you are ready to make this month's gift, you can do it here:", 'sepo-membership'),
            Settings::money((float) $p['amount'], (string) $p['currency'])
        ) . ($donate !== '' ? "\n\n" . $donate : ''));
    }
}
