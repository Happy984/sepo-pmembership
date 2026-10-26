<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/** Site-wide settings, in one option. */
final class Settings
{
    public const OPTION = 'sepo_membership_settings';

    /** The pages the plugin makes on activation: key => [title, slug, shortcode]. */
    public const PAGES = [
        'plans_page'    => ['Membership', 'membership', '[sepo_membership_plans]'],
        'account_page'  => ['My membership', 'my-membership', '[sepo_membership_account]'],
        'area_page'     => ['Members area', 'members-area', '[sepo_members_area]'],
        'sponsors_page' => ['Sponsors', 'sponsors', '[sepo_sponsors]'],
        'donate_page'   => ['Donate', 'donate', '[sepo_donate]'],
    ];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'accent'                => '',
            'currency'              => 'ZMW',
            'methods'               => [
                ['id' => 'bank', 'label' => 'Bank transfer', 'type' => 'manual', 'detail' => "Bank: \nAccount name: \nAccount number: \nUse your name as the reference."],
                ['id' => 'mobile', 'label' => 'Mobile money', 'type' => 'manual', 'detail' => "Send to: \nUse your name as the reference."],
            ],
            'membership_methods'    => [],
            'donation_methods'      => [],
            'proof'                 => 'optional',
            'proof_max_mb'          => 5,
            'guest_donations'       => 1,
            'donate_amounts'        => '50, 100, 250, 500',
            'donate_goal'           => 0,
            'sponsor_levels'        => "platinum | Platinum | 5000\ngold | Gold | 1000\nsilver | Silver | 250\nsupporter | Supporter | 0",
            'remind_days'           => 7,
            'notify_email'          => '',
            'notify_payments'       => 1,
            'plans_page'            => 0,
            'account_page'          => 0,
            'area_page'             => 0,
            'sponsors_page'         => 0,
            'donate_page'           => 0,
            'delete_on_uninstall'   => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $o = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($o) ? $o : []);
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    /** @param array<string,mixed> $values */
    public static function update(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    public static function currency(): string
    {
        $c = strtoupper(preg_replace('~[^A-Za-z]~', '', (string) self::get('currency')) ?? '');
        return $c !== '' ? substr($c, 0, 3) : 'ZMW';
    }

    /** An amount with its currency, in the site's number format. */
    public static function money(float $amount, string $currency = ''): string
    {
        $currency = $currency !== '' ? $currency : self::currency();
        $decimals = abs($amount - round($amount)) < 0.005 ? 0 : 2;
        return $currency . ' ' . number_format_i18n($amount, $decimals);
    }

    /**
     * Payment methods, keyed by id.
     *
     * @return array<string,array{id:string,label:string,type:string,detail:string}>
     */
    public static function methods(string $for = ''): array
    {
        $out = [];
        foreach ((array) self::get('methods') as $m) {
            if (!is_array($m)) {
                continue;
            }
            $id = sanitize_key((string) ($m['id'] ?? ''));
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $type = in_array($m['type'] ?? '', ['manual', 'link'], true) ? (string) $m['type'] : 'manual';
            $out[$id] = [
                'id'     => $id,
                'label'  => (string) ($m['label'] ?? '') !== '' ? (string) $m['label'] : ucfirst($id),
                'type'   => $type,
                'detail' => (string) ($m['detail'] ?? ''),
            ];
        }
        $allow = $for === 'membership' ? (array) self::get('membership_methods') : ($for === 'donation' ? (array) self::get('donation_methods') : []);
        $allow = array_values(array_filter(array_map('sanitize_key', $allow)));
        if ($allow !== []) {
            $out = array_intersect_key($out, array_flip($allow));
        }
        return (array) apply_filters('sepo_membership_payment_methods', $out, $for);
    }

    /** White or near-black, whichever reads better on the colour. */
    public static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!ctype_xdigit($hex) || strlen($hex) !== 6) {
            return '#ffffff';
        }
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int) hexdec(substr($hex, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($hex, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($hex, 4, 2)));
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0575) ? '#ffffff' : '#0b1215';
    }

    /** Make the plugin's pages once, reusing a page that already has the shortcode. */
    public static function ensurePages(): void
    {
        $updates = [];
        foreach (self::PAGES as $key => [$title, $slug, $shortcode]) {
            $id = (int) self::get($key);
            if ($id > 0 && get_post_status($id)) {
                continue;
            }
            $existing = get_page_by_path($slug);
            $tag = substr($shortcode, 0, -1);
            if ($existing && str_contains((string) $existing->post_content, $tag)) {
                $updates[$key] = (int) $existing->ID;
                continue;
            }
            $new = wp_insert_post([
                'post_type'    => 'page',
                'post_status'  => 'publish',
                'post_title'   => $title,
                'post_name'    => $slug,
                'post_content' => '<!-- wp:shortcode -->' . $shortcode . '<!-- /wp:shortcode -->',
            ]);
            if (is_int($new) && $new > 0) {
                $updates[$key] = $new;
            }
        }
        if ($updates !== []) {
            self::update($updates);
        }
    }

    /** One of the plugin's pages, or '' when it is not set up. */
    public static function pageUrl(string $key, array $args = []): string
    {
        $id = (int) self::get($key);
        if ($id < 1 || get_post_status($id) !== 'publish') {
            return '';
        }
        $url = (string) get_permalink($id);
        return $args ? add_query_arg($args, $url) : $url;
    }

    /**
     * Sponsor levels, highest first: id => [id, label, min].
     *
     * @return array<string,array{id:string,label:string,min:float}>
     */
    public static function sponsorLevels(): array
    {
        $out = [];
        foreach (preg_split('~\R~', (string) self::get('sponsor_levels')) ?: [] as $line) {
            $p = array_map('trim', explode('|', $line));
            $id = sanitize_key((string) ($p[0] ?? ''));
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $out[$id] = ['id' => $id, 'label' => (string) ($p[1] ?? '') !== '' ? sanitize_text_field((string) $p[1]) : ucfirst($id), 'min' => max(0.0, (float) ($p[2] ?? 0))];
        }
        if ($out === []) {
            $out['supporter'] = ['id' => 'supporter', 'label' => __('Supporter', 'sepo-membership'), 'min' => 0.0];
        }
        uasort($out, static fn(array $a, array $b): int => $b['min'] <=> $a['min']);
        return $out;
    }

    /** The sponsor level an amount reaches. */
    public static function levelForAmount(float $amount): string
    {
        foreach (self::sponsorLevels() as $id => $l) {
            if ($amount >= $l['min']) {
                return $id;
            }
        }
        return (string) array_key_last(self::sponsorLevels());
    }

    /** @return list<float> */
    public static function donateAmounts(): array
    {
        $out = [];
        foreach (preg_split('~[,\s]+~', (string) self::get('donate_amounts')) ?: [] as $v) {
            $n = (float) $v;
            if ($n > 0 && !in_array($n, $out, true)) {
                $out[] = $n;
            }
        }
        return array_slice($out, 0, 8);
    }
}
