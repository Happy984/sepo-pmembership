<?php

declare(strict_types=1);

namespace SepoMembership;

use SepoMembership\Render\AccountView;
use SepoMembership\Render\AreaView;
use SepoMembership\Render\DonateView;
use SepoMembership\Render\Icons;
use SepoMembership\Render\Parts;
use SepoMembership\Render\PlansView;
use SepoMembership\Render\SponsorsView;

defined('ABSPATH') || exit;

/** Shortcodes, assets, project pages and the proof-of-payment download. */
final class Frontend
{
    public const HANDLE = 'sepo-membership';

    public static function hook(): void
    {
        add_action('init', static function (): void {
            add_shortcode('sepo_membership_plans', [self::class, 'plans']);
            add_shortcode('sepo_membership_account', [self::class, 'account']);
            add_shortcode('sepo_members_area', [self::class, 'area']);
            add_shortcode('sepo_sponsors', [self::class, 'sponsors']);
            add_shortcode('sepo_donate', [self::class, 'donate']);
            add_shortcode('sepo_members_only', [self::class, 'membersOnly']);
        });
        add_action('wp_enqueue_scripts', [self::class, 'register'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'early'], 20);
        add_action('template_redirect', [self::class, 'noCache'], 5);
        add_filter('the_content', [self::class, 'projectContent'], 20);
        add_action('admin_post_smem_proof', [self::class, 'proof']);
        add_action('admin_post_nopriv_smem_proof', static function (): void {
            auth_redirect();
        });
    }

    public static function register(): void
    {
        if (wp_style_is(self::HANDLE, 'registered')) {
            return;
        }
        wp_register_style(self::HANDLE, SMEM_URL . 'assets/css/membership.css', [], Plugin::assetVersion('assets/css/membership.css'));
        wp_register_script(self::HANDLE, SMEM_URL . 'assets/js/membership.js', [], Plugin::assetVersion('assets/js/membership.js'), true);
        $accent = (string) Settings::get('accent');
        if ($accent !== '' && preg_match('~^#[0-9a-fA-F]{3,6}$~', $accent) === 1) {
            wp_add_inline_style(self::HANDLE, '.smem-scope{--smem-accent:' . $accent . ';--smem-on-accent:' . Settings::textOn($accent) . ';}');
        }
        add_action('wp_print_footer_scripts', [self::class, 'printConfig'], 1);
    }

    /** Load the styles in the head on pages that use the plugin, so nothing shifts as they arrive. */
    public static function early(): void
    {
        if (self::usesPlugin()) {
            wp_enqueue_style(self::HANDLE);
        }
    }

    private static function usesPlugin(): bool
    {
        if (is_singular(PostTypes::PROJECT)) {
            return true;
        }
        $post = is_singular() ? get_post() : null;
        if (!$post instanceof \WP_Post) {
            return false;
        }
        $c = (string) $post->post_content;
        return str_contains($c, '[sepo_membership') || str_contains($c, '[sepo_members') || str_contains($c, '[sepo_sponsors') || str_contains($c, '[sepo_donate') || str_contains($c, '<!-- wp:sepo-membership/');
    }

    /** Pages that show a person's own membership or the members area must not be stored by page caches. */
    public static function noCache(): void
    {
        $post = is_singular() ? get_post() : null;
        if (!$post instanceof \WP_Post) {
            return;
        }
        $c = (string) $post->post_content;
        if (str_contains($c, '[sepo_membership_account') || str_contains($c, '[sepo_members_area') || str_contains($c, '[sepo_members_only') || str_contains($c, 'wp:sepo-membership/members-area') || str_contains($c, 'wp:sepo-membership/account')) {
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
        }
    }

    public static function enqueue(): void
    {
        self::register();
        wp_enqueue_style(self::HANDLE);
        wp_enqueue_script(self::HANDLE);
    }

    public static function printConfig(): void
    {
        static $done = false;
        if ($done || !wp_script_is(self::HANDLE, 'enqueued')) {
            return;
        }
        $done = true;
        wp_add_inline_script(self::HANDLE, 'window.SepoMembershipConfig=' . wp_json_encode(self::config()) . ';', 'before');
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        return [
            'api'       => esc_url_raw(rest_url(Rest::NS)),
            // Only signed-in visitors get a nonce: a cached page would carry a stale one for guests.
            'nonce'     => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'loggedIn'  => is_user_logged_in(),
            'currency'  => Settings::currency(),
            'locale'    => str_replace('_', '-', get_locale()),
            'proof'     => (string) Settings::get('proof'),
            'proofMax'  => Files::maxMb(),
            'account'   => Settings::pageUrl('account_page'),
            'icons'     => Icons::all(),
            'i18n'      => self::strings(),
        ];
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        return [
            'network'       => __('Could not reach the site. Check your connection and try again.', 'sepo-membership'),
            'close'         => __('Close', 'sepo-membership'),
            'cancel'        => __('Cancel', 'sepo-membership'),
            'back'          => __('Back', 'sepo-membership'),
            'save'          => __('Save', 'sepo-membership'),
            'saving'        => __('Saving…', 'sepo-membership'),
            'sending'       => __('Sending…', 'sepo-membership'),
            'copied'        => __('Copied', 'sepo-membership'),
            'joinTitle'     => __('Join %s', 'sepo-membership'),
            'renewTitle'    => __('Renew %s', 'sepo-membership'),
            'upgradeTitle'  => __('Upgrade to %s', 'sepo-membership'),
            'youPay'        => __('You pay', 'sepo-membership'),
            'paid'          => __('I have paid', 'sepo-membership'),
            'sendPayment'   => __('Send payment details', 'sepo-membership'),
            'confirmSwitch' => __('Switch at renewal', 'sepo-membership'),
            'switchTitle'   => __('Move to %s?', 'sepo-membership'),
            'switchText'    => __('You keep your current plan until it runs out. %s starts with your next payment.', 'sepo-membership'),
            'needProof'     => __('Attach proof of payment, such as a screenshot or a receipt.', 'sepo-membership'),
            'tooBig'        => __('%s is larger than %d MB.', 'sepo-membership'),
            'filesChosen'   => __('%d files chosen', 'sepo-membership'),
            'cancelTitle'   => __('Cancel your plan?', 'sepo-membership'),
            'cancelText'    => __('You keep your access until %s. After that the members area locks until you renew.', 'sepo-membership'),
            'cancelYes'     => __('Cancel plan', 'sepo-membership'),
            'keep'          => __('Keep my plan', 'sepo-membership'),
            'withdrawTitle' => __('Ask to withdraw this payment', 'sepo-membership'),
            'withdrawText'  => __('Use this if you made the payment by mistake or need it back. We look at every request and email you our decision. If you have already sent the money and want to keep your plan, add proof instead.', 'sepo-membership'),
            'withdrawWhy'   => __('Why do you want to withdraw it?', 'sepo-membership'),
            'withdrawHelp'  => __('Kept with the payment record and seen by the people who handle payments.', 'sepo-membership'),
            'withdrawNeed'  => __('Tell us briefly why you want to withdraw this payment.', 'sepo-membership'),
            'withdrawSend'  => __('Send request', 'sepo-membership'),
            'give'          => __('Give %s', 'sepo-membership'),
            'giveMonthly'   => __('Give %s monthly', 'sepo-membership'),
            'enterAmount'   => __('Enter the amount.', 'sepo-membership'),
            'enterName'     => __('Enter your name and a valid email address.', 'sepo-membership'),
            'thanks'        => __('Thank you', 'sepo-membership'),
            'giveAgain'     => __('Give again', 'sepo-membership'),
            'newPost'       => __('New discussion', 'sepo-membership'),
            'newSuggestion' => __('Suggest an idea', 'sepo-membership'),
            'title'         => __('Title', 'sepo-membership'),
            'details'       => __('Details', 'sepo-membership'),
            'optional'      => __('optional', 'sepo-membership'),
            'post'          => __('Post', 'sepo-membership'),
            'reply'         => __('Reply', 'sepo-membership'),
            'writeReply'    => __('Write a reply…', 'sepo-membership'),
            'edit'          => __('Edit', 'sepo-membership'),
            'edited'        => __('edited', 'sepo-membership'),
            'remove'        => __('Remove', 'sepo-membership'),
            'removeTitle'   => __('Remove this?', 'sepo-membership'),
            'removeThread'  => __('The whole conversation, with its replies, will be removed.', 'sepo-membership'),
            'removeReply'   => __('This reply will be removed.', 'sepo-membership'),
            'replies'       => __('%d replies', 'sepo-membership'),
            'oneReply'      => __('1 reply', 'sepo-membership'),
            'noReplies'     => __('No replies yet', 'sepo-membership'),
            'votes'         => __('votes', 'sepo-membership'),
            'vote'          => __('Vote', 'sepo-membership'),
            'voted'         => __('Voted', 'sepo-membership'),
            'pinned'        => __('Pinned', 'sepo-membership'),
            'pin'           => __('Pin', 'sepo-membership'),
            'unpin'         => __('Unpin', 'sepo-membership'),
            'status'        => __('Status', 'sepo-membership'),
            'emptyPosts'    => __('No discussions yet. Start the first one.', 'sepo-membership'),
            'emptyIdeas'    => __('No suggestions yet. Share the first idea.', 'sepo-membership'),
            'emptyFilter'   => __('Nothing here with this filter.', 'sepo-membership'),
            'loading'       => __('Loading…', 'sepo-membership'),
            'prev'          => __('Previous', 'sepo-membership'),
            'next'          => __('Next', 'sepo-membership'),
            'pageOf'        => __('Page %1$d of %2$d', 'sepo-membership'),
            'allPosts'      => __('All discussions', 'sepo-membership'),
            'allIdeas'      => __('All suggestions', 'sepo-membership'),
            'by'            => __('by %s', 'sepo-membership'),
            'you'           => __('you', 'sepo-membership'),
            'statusOpen'    => __('Open', 'sepo-membership'),
            'statusPlanned' => __('Planned', 'sepo-membership'),
            'statusDone'    => __('Done', 'sepo-membership'),
            'statusDeclined' => __('Not planned', 'sepo-membership'),
        ];
    }

    // ---------------------------------------------------------- shortcodes

    /** @param array<string,string>|string $atts */
    public static function plans($atts = []): string
    {
        self::enqueue();
        return PlansView::render(shortcode_atts(['tiers' => '', 'period' => '', 'title' => '', 'intro' => ''], (array) $atts, 'sepo_membership_plans'));
    }

    public static function account($atts = []): string
    {
        self::enqueue();
        return AccountView::render();
    }

    public static function area($atts = []): string
    {
        self::enqueue();
        $page = (int) get_queried_object_id();
        $cfg = Resources::normalise(shortcode_atts(Resources::defaults(), (array) $atts, 'sepo_members_area'), $page);
        return AreaView::render($cfg, $page);
    }

    public static function sponsors($atts = []): string
    {
        self::enqueue();
        return SponsorsView::render(shortcode_atts(['show' => '', 'project' => '', 'projects' => '', 'title' => '', 'intro' => ''], (array) $atts, 'sepo_sponsors'));
    }

    public static function donate($atts = []): string
    {
        self::enqueue();
        return DonateView::render(shortcode_atts(['project' => '', 'amounts' => '', 'title' => '', 'intro' => '', 'progress' => 'yes'], (array) $atts, 'sepo_donate'));
    }

    /**
     * [sepo_members_only tier="gold"]…[/sepo_members_only]: the enclosed
     * content for members with an active plan at the tier or above, and a
     * short note for everyone else.
     */
    public static function membersOnly($atts = [], ?string $content = null): string
    {
        $a = shortcode_atts(['tier' => '', 'message' => ''], (array) $atts, 'sepo_members_only');
        $tier = sanitize_key((string) $a['tier']);
        $uid = get_current_user_id();
        if (Plugin::canManage() || ($uid > 0 && Members::hasLevel($uid, $tier))) {
            return do_shortcode((string) $content);
        }
        self::enqueue();
        $plans = Settings::pageUrl('plans_page');
        $msg = (string) $a['message'] !== '' ? (string) $a['message'] : ($tier !== ''
            /* translators: %s: plan */
            ? sprintf(__('This part is for %s members and above.', 'sepo-membership'), Tiers::label($tier))
            : __('This part is for members.', 'sepo-membership'));
        $action = $uid < 1
            ? '<a class="smem-btn smem-btn--sm smem-btn--primary" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-membership') . '</a>'
            : '';
        if ($plans !== '') {
            $action .= '<a class="smem-btn smem-btn--sm smem-btn--outline" href="' . esc_url($plans) . '">' . esc_html__('See plans', 'sepo-membership') . '</a>';
        }
        return Parts::frame('<div class="smem-gate smem-gate--compact smem-gate--inline">' . Icons::get('lock', 20) . '<p class="smem-gate__text">' . esc_html($msg) . '</p><div class="smem-gate__actions">' . $action . '</div></div>', 'smem-only');
    }

    // ---------------------------------------------------------- project pages

    /** A project page shows its progress, a giving form and its sponsors after the description. */
    public static function projectContent(string $content): string
    {
        if (!is_singular(PostTypes::PROJECT) || !in_the_loop() || !is_main_query() || (int) get_the_ID() !== (int) get_queried_object_id()) {
            return $content;
        }
        $id = (int) get_the_ID();
        if (has_shortcode($content, 'sepo_donate')) {
            return $content;
        }
        self::enqueue();
        $give = DonateView::render(['project' => (string) $id, 'progress' => 'yes']);
        $sponsors = SponsorsView::render(['project' => (string) $id, 'show' => 'sponsors']);
        return $content . $give . $sponsors;
    }

    // ---------------------------------------------------------- proof files

    /** admin-post.php?action=smem_proof: a proof of payment, for managers and for the payer. */
    public static function proof(): void
    {
        $id = (int) ($_GET['payment'] ?? 0);
        $index = (int) ($_GET['file'] ?? -1);
        if (!wp_verify_nonce((string) ($_GET['_wpnonce'] ?? ''), 'smem_proof_' . $id)) {
            wp_die(esc_html__('This link has expired. Go back and open it again.', 'sepo-membership'), '', ['response' => 403, 'back_link' => true]);
        }
        $p = Payments::get($id);
        if ($p === null || !isset($p['proofs'][$index])) {
            wp_die(esc_html__('That file could not be found.', 'sepo-membership'), '', ['response' => 404, 'back_link' => true]);
        }
        if (!Plugin::canManage() && $p['user_id'] !== get_current_user_id()) {
            wp_die(esc_html__('You cannot open this file.', 'sepo-membership'), '', ['response' => 403, 'back_link' => true]);
        }
        Files::send('proofs', (array) $p['proofs'][$index], true);
    }
}
