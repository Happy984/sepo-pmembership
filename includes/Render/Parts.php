<?php

declare(strict_types=1);

namespace SepoMembership\Render;

use SepoMembership\Files;
use SepoMembership\Settings;

defined('ABSPATH') || exit;

/** Pieces shared by the plans, account, donate, sponsors and members-area views. */
final class Parts
{
    /**
     * The outer frame every view sits in: the scope that carries the colour
     * tokens and dark mode, a live region for messages, and the dialog shell.
     *
     * @param array<string,string|int> $data data-* attributes
     */
    public static function frame(string $inner, string $class = '', array $data = []): string
    {
        $attrs = '';
        foreach ($data as $k => $v) {
            $attrs .= ' data-' . esc_attr($k) . '="' . esc_attr((string) $v) . '"';
        }
        return '<div class="smem-scope smem-wrap' . ($class !== '' ? ' ' . esc_attr($class) : '') . '"' . $attrs . '>'
            . $inner
            . '<div class="smem-toast" role="status" aria-live="polite" data-smem-toast hidden></div>'
            . self::modal()
            . '</div>';
    }

    private static function modal(): string
    {
        return '<div class="smem-modal" data-smem-modal hidden>'
            . '<div class="smem-modal__panel" role="dialog" aria-modal="true" aria-labelledby="" tabindex="-1">'
            . '<div class="smem-modal__head"><h2 class="smem-modal__title" data-smem-modal-title></h2>'
            . '<button type="button" class="smem-iconbtn" data-smem-modal-close aria-label="' . esc_attr__('Close', 'sepo-membership') . '">' . Icons::get('x', 18) . '</button></div>'
            . '<div class="smem-modal__body" data-smem-modal-body></div>'
            . '<div class="smem-modal__foot" data-smem-modal-foot></div>'
            . '</div></div>';
    }

    /** The address of the page being shown. */
    public static function here(): string
    {
        return (is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    }

    public static function login(string $back = ''): string
    {
        return wp_login_url($back !== '' ? $back : self::here());
    }

    public static function register(): string
    {
        return get_option('users_can_register') ? (string) wp_registration_url() : '';
    }

    /** "in 3 days" or "2 hours ago". */
    public static function ago(int $ts): string
    {
        if ($ts < 1) {
            return '';
        }
        $d = human_time_diff($ts, time());
        /* translators: %s: a span of time such as "3 days" */
        return $ts > time() ? sprintf(__('in %s', 'sepo-membership'), $d) : sprintf(__('%s ago', 'sepo-membership'), $d);
    }

    public static function date(int $ts): string
    {
        return $ts > 0 ? wp_date((string) get_option('date_format'), $ts) : '';
    }

    /** A notice: info, good, warn or bad. Tinted, with an icon. */
    public static function notice(string $html, string $kind = 'info', string $icon = 'info'): string
    {
        return '<div class="smem-notice smem-notice--' . esc_attr($kind) . '">' . Icons::get($icon, 18) . '<div class="smem-notice__body">' . $html . '</div></div>';
    }

    public static function progress(int $percent, string $label): string
    {
        $percent = max(0, min(100, $percent));
        return '<div class="smem-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $percent . '" aria-label="' . esc_attr($label) . '"><span style="width:' . $percent . '%"></span></div>';
    }

    /** Two letters for a name, for a tile with no logo or photo. */
    public static function initials(string $name): string
    {
        $words = preg_split('~\s+~u', trim(wp_strip_all_tags($name))) ?: [];
        $out = '';
        foreach (array_slice(array_filter($words), 0, 2) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $out !== '' ? $out : '•';
    }

    /**
     * The "how did you pay" part of a payment form, shared by joining and giving:
     * a choice of method, its details or payment link, a reference, proof of
     * payment and, when asked for, the choice to be listed as a sponsor.
     */
    public static function payFields(string $for, bool $offerListing, string $listingLabel = ''): string
    {
        $methods = Settings::methods($for);
        if ($methods === []) {
            return self::notice(esc_html__('Payments are not set up yet. Please check back soon.', 'sepo-membership'), 'warn');
        }
        $uid = 'smem-pay-' . wp_rand(1000, 99999);
        $h = '<fieldset class="smem-methods"><legend class="smem-label">' . esc_html__('How are you paying?', 'sepo-membership') . '</legend>';
        $first = true;
        foreach ($methods as $m) {
            $id = $uid . '-' . $m['id'];
            $h .= '<label class="smem-method" for="' . esc_attr($id) . '">'
                . '<input type="radio" name="method" id="' . esc_attr($id) . '" value="' . esc_attr($m['id']) . '" data-type="' . esc_attr($m['type']) . '"' . ($first ? ' checked' : '') . '>'
                . '<span class="smem-method__dot" aria-hidden="true"></span>'
                . '<span class="smem-method__label">' . esc_html($m['label']) . '</span>'
                . '<span class="smem-method__kind">' . ($m['type'] === 'link' ? esc_html__('Pay online', 'sepo-membership') : esc_html__('Pay, then tell us', 'sepo-membership')) . '</span>'
                . '</label>';
            $first = false;
        }
        $h .= '</fieldset>';

        foreach ($methods as $m) {
            $h .= '<div class="smem-method-detail" data-method-detail="' . esc_attr($m['id']) . '" hidden>';
            if ($m['type'] === 'link') {
                $h .= '<p class="smem-help">' . esc_html__('Pay on the payment page, then come back here and press the button below so we can match your payment.', 'sepo-membership') . '</p>';
                $h .= '<a class="smem-btn smem-btn--outline" href="#" data-pay-link="' . esc_attr(trim($m['detail'])) . '" target="_blank" rel="noopener">' . Icons::get('external') . esc_html__('Open the payment page', 'sepo-membership') . '</a>';
            } elseif (trim($m['detail']) !== '') {
                $h .= '<div class="smem-paydetail"><pre class="smem-paydetail__text">' . esc_html(trim($m['detail'])) . '</pre>'
                    . '<button type="button" class="smem-btn smem-btn--sm smem-btn--quiet" data-smem-copy>' . Icons::get('copy', 14) . esc_html__('Copy', 'sepo-membership') . '</button></div>';
            }
            $h .= '</div>';
        }

        $h .= '<div class="smem-field"><label class="smem-label" for="' . esc_attr($uid) . '-ref">' . esc_html__('Payment reference', 'sepo-membership') . ' <span class="smem-optional">' . esc_html__('optional', 'sepo-membership') . '</span></label>'
            . '<input class="smem-input" id="' . esc_attr($uid) . '-ref" name="reference" type="text" maxlength="190" autocomplete="off">'
            . '<span class="smem-help">' . esc_html__('The transaction ID or the reference you used, so we can find your payment quickly.', 'sepo-membership') . '</span></div>';

        $proof = (string) Settings::get('proof');
        if ($proof !== 'off') {
            $h .= '<div class="smem-field" data-proof-field><label class="smem-label" for="' . esc_attr($uid) . '-proof">' . esc_html__('Proof of payment', 'sepo-membership')
                . ($proof === 'required' ? '' : ' <span class="smem-optional">' . esc_html__('optional', 'sepo-membership') . '</span>') . '</label>'
                . '<label class="smem-drop" for="' . esc_attr($uid) . '-proof">' . Icons::get('upload', 18) . '<span data-drop-label>' . esc_html__('Choose a screenshot or receipt', 'sepo-membership') . '</span></label>'
                . '<input class="smem-file" id="' . esc_attr($uid) . '-proof" name="proof[]" type="file" multiple accept="' . esc_attr(self::accept(Files::proofMimes())) . '"' . ($proof === 'required' ? ' data-required-manual' : '') . '>'
                /* translators: 1: file types, 2: size in MB */
                . '<span class="smem-help">' . esc_html(sprintf(__('%1$s. Up to %2$d MB each.', 'sepo-membership'), Files::label(Files::proofMimes()), Files::maxMb())) . '</span></div>';
        }
        if ($offerListing) {
            $h .= '<label class="smem-check"><input type="checkbox" name="listed" value="1"><span>' . esc_html($listingLabel !== '' ? $listingLabel : __('Show my name on the sponsors page', 'sepo-membership')) . '</span></label>';
        }
        return $h;
    }

    /** @param array<string,string> $mimes */
    private static function accept(array $mimes): string
    {
        $out = [];
        foreach (array_keys($mimes) as $exts) {
            foreach (explode('|', (string) $exts) as $e) {
                $out[] = '.' . $e;
            }
        }
        return implode(',', $out);
    }

    /** Escaped plain text as paragraphs. */
    public static function paras(string $text): string
    {
        $out = '';
        foreach (preg_split('~\n{2,}~', trim($text)) ?: [] as $p) {
            if (trim($p) !== '') {
                $out .= '<p>' . nl2br(esc_html(trim($p)), false) . '</p>';
            }
        }
        return $out;
    }
}
