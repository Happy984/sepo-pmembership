<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Blocks for the editor: plans, my membership, members area, sponsors and
 * donate. Each renders on the server with the same code as its shortcode.
 */
final class Embeds
{
    public static function hook(): void
    {
        add_action('init', [self::class, 'blocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'editorAssets']);
        add_action('elementor/frontend/after_register_styles', [Frontend::class, 'register']);
        add_action('elementor/frontend/after_register_scripts', [Frontend::class, 'register']);
    }

    public static function blocks(): void
    {
        $str = static fn(string $default = ''): array => ['type' => 'string', 'default' => $default];
        register_block_type('sepo-membership/plans', [
            'api_version'     => 2,
            'title'           => __('Membership plans', 'sepo-membership'),
            'category'        => 'widgets',
            'attributes'      => ['tiers' => $str(), 'period' => $str(), 'title' => $str(), 'intro' => $str()],
            'render_callback' => static fn(array $a): string => Frontend::plans(array_map('strval', $a)),
        ]);
        register_block_type('sepo-membership/account', [
            'api_version'     => 2,
            'title'           => __('My membership', 'sepo-membership'),
            'category'        => 'widgets',
            'render_callback' => static fn(): string => Frontend::account(),
        ]);
        register_block_type('sepo-membership/members-area', [
            'api_version'     => 2,
            'title'           => __('Members area', 'sepo-membership'),
            'category'        => 'widgets',
            'attributes'      => [
                'tier' => $str(), 'discussionsTier' => $str(), 'suggestionsTier' => $str(),
                'collection' => $str(), 'show' => $str(), 'title' => $str(),
            ],
            'render_callback' => static fn(array $a): string => Frontend::area([
                'tier'             => (string) ($a['tier'] ?? ''),
                'discussions_tier' => (string) ($a['discussionsTier'] ?? ''),
                'suggestions_tier' => (string) ($a['suggestionsTier'] ?? ''),
                'collection'       => (string) ($a['collection'] ?? ''),
                'show'             => (string) ($a['show'] ?? ''),
                'title'            => (string) ($a['title'] ?? ''),
            ]),
        ]);
        register_block_type('sepo-membership/sponsors', [
            'api_version'     => 2,
            'title'           => __('Sponsors', 'sepo-membership'),
            'category'        => 'widgets',
            'attributes'      => ['show' => $str(), 'project' => ['type' => 'number', 'default' => 0], 'title' => $str(), 'intro' => $str()],
            'render_callback' => static fn(array $a): string => Frontend::sponsors([
                'show' => (string) ($a['show'] ?? ''), 'project' => (string) (int) ($a['project'] ?? 0),
                'title' => (string) ($a['title'] ?? ''), 'intro' => (string) ($a['intro'] ?? ''),
            ]),
        ]);
        register_block_type('sepo-membership/donate', [
            'api_version'     => 2,
            'title'           => __('Donate', 'sepo-membership'),
            'category'        => 'widgets',
            'attributes'      => ['project' => ['type' => 'number', 'default' => 0], 'amounts' => $str(), 'title' => $str(), 'intro' => $str(), 'progress' => ['type' => 'boolean', 'default' => true]],
            'render_callback' => static fn(array $a): string => Frontend::donate([
                'project' => (string) (int) ($a['project'] ?? 0), 'amounts' => (string) ($a['amounts'] ?? ''),
                'title' => (string) ($a['title'] ?? ''), 'intro' => (string) ($a['intro'] ?? ''),
                'progress' => !isset($a['progress']) || $a['progress'] ? 'yes' : 'no',
            ]),
        ]);
    }

    public static function editorAssets(): void
    {
        Frontend::register();
        wp_enqueue_style(Frontend::HANDLE);
        wp_enqueue_script('sepo-membership-blocks', SMEM_URL . 'assets/js/blocks.js', ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'], Plugin::assetVersion('assets/js/blocks.js'), true);
        $tiers = [['value' => '', 'label' => __('Any active plan', 'sepo-membership')]];
        foreach (Tiers::all() as $t) {
            /* translators: %s: plan */
            $tiers[] = ['value' => $t['id'], 'label' => sprintf(__('%s and above', 'sepo-membership'), $t['label'])];
        }
        $projects = [['value' => 0, 'label' => __('General donations', 'sepo-membership')]];
        foreach (get_posts(['post_type' => PostTypes::PROJECT, 'post_status' => 'publish', 'numberposts' => 100]) as $p) {
            $projects[] = ['value' => $p->ID, 'label' => get_the_title($p)];
        }
        $collections = [['value' => '', 'label' => __('All collections', 'sepo-membership')]];
        $terms = get_terms(['taxonomy' => PostTypes::COLLECTION, 'hide_empty' => false]);
        foreach (is_array($terms) ? $terms : [] as $t) {
            $collections[] = ['value' => $t->slug, 'label' => $t->name];
        }
        wp_add_inline_script('sepo-membership-blocks', 'window.SepoMembershipBlocks=' . wp_json_encode(['tiers' => $tiers, 'projects' => $projects, 'collections' => $collections]) . ';', 'before');
        wp_set_script_translations('sepo-membership-blocks', 'sepo-membership');
    }
}
