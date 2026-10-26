<?php

declare(strict_types=1);

namespace SepoMembership;

defined('ABSPATH') || exit;

/**
 * Content types the plugin manages in the dashboard:
 *
 *   Projects   things people can sponsor, with a goal
 *   Sponsors   organisations and people you list by hand
 *   Resources  member downloads and links, each with a minimum tier
 */
final class PostTypes
{
    public const PROJECT = 'smem_project';
    public const SPONSOR = 'smem_sponsor';
    public const RESOURCE = 'smem_resource';
    public const COLLECTION = 'smem_collection';

    public static function hook(): void
    {
        add_action('init', [self::class, 'register']);
        add_filter('manage_' . self::RESOURCE . '_posts_columns', static function (array $c): array {
            $out = [];
            foreach ($c as $k => $v) {
                $out[$k] = $v;
                if ($k === 'title') {
                    $out['smem_kind'] = __('Kind', 'sepo-membership');
                    $out['smem_tier'] = __('Plan needed', 'sepo-membership');
                }
            }
            return $out;
        });
        add_action('manage_' . self::RESOURCE . '_posts_custom_column', static function (string $col, int $id): void {
            $r = Resources::get($id);
            if ($r === null) {
                return;
            }
            if ($col === 'smem_kind') {
                echo esc_html($r['type'] === 'link' ? __('Link', 'sepo-membership') : __('Download', 'sepo-membership'));
            } elseif ($col === 'smem_tier') {
                echo esc_html(Tiers::label($r['tier']));
            }
        }, 10, 2);
        add_filter('manage_' . self::PROJECT . '_posts_columns', static function (array $c): array {
            $c['smem_raised'] = __('Raised', 'sepo-membership');
            return $c;
        });
        add_action('manage_' . self::PROJECT . '_posts_custom_column', static function (string $col, int $id): void {
            if ($col === 'smem_raised') {
                $goal = (float) get_post_meta($id, '_smem_goal', true);
                echo esc_html(Settings::money(Payments::raised($id)) . ($goal > 0 ? ' / ' . Settings::money($goal) : ''));
            }
        }, 10, 2);
        add_filter('manage_' . self::SPONSOR . '_posts_columns', static function (array $c): array {
            $c['smem_level'] = __('Level', 'sepo-membership');
            return $c;
        });
        add_action('manage_' . self::SPONSOR . '_posts_custom_column', static function (string $col, int $id): void {
            if ($col === 'smem_level') {
                $l = (string) get_post_meta($id, '_smem_level', true);
                echo esc_html(Settings::sponsorLevels()[$l]['label'] ?? '—');
            }
        }, 10, 2);
    }

    public static function register(): void
    {
        register_post_type(self::PROJECT, [
            'labels' => [
                'name'          => __('Sponsorship projects', 'sepo-membership'),
                'singular_name' => __('Project', 'sepo-membership'),
                'menu_name'     => __('Projects', 'sepo-membership'),
                'add_new_item'  => __('Add a project', 'sepo-membership'),
                'edit_item'     => __('Edit project', 'sepo-membership'),
                'all_items'     => __('Projects', 'sepo-membership'),
            ],
            'public'       => true,
            'show_in_menu' => 'sepo-membership',
            'show_in_rest' => true,
            'has_archive'  => false,
            'supports'     => ['title', 'editor', 'excerpt', 'thumbnail'],
            'rewrite'      => ['slug' => 'sponsor', 'with_front' => false],
        ]);
        register_post_type(self::SPONSOR, [
            'labels' => [
                'name'          => __('Sponsors', 'sepo-membership'),
                'singular_name' => __('Sponsor', 'sepo-membership'),
                'add_new_item'  => __('Add a sponsor', 'sepo-membership'),
                'edit_item'     => __('Edit sponsor', 'sepo-membership'),
                'all_items'     => __('Sponsors', 'sepo-membership'),
                'featured_image'     => __('Logo', 'sepo-membership'),
                'set_featured_image' => __('Set logo', 'sepo-membership'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'sepo-membership',
            'supports'     => ['title', 'excerpt', 'thumbnail', 'page-attributes'],
        ]);
        register_post_type(self::RESOURCE, [
            'labels' => [
                'name'          => __('Member resources', 'sepo-membership'),
                'singular_name' => __('Resource', 'sepo-membership'),
                'add_new_item'  => __('Add a resource', 'sepo-membership'),
                'edit_item'     => __('Edit resource', 'sepo-membership'),
                'all_items'     => __('Resources', 'sepo-membership'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => 'sepo-membership',
            'supports'     => ['title', 'excerpt', 'page-attributes'],
        ]);
        register_taxonomy(self::COLLECTION, self::RESOURCE, [
            'labels' => [
                'name'          => __('Collections', 'sepo-membership'),
                'singular_name' => __('Collection', 'sepo-membership'),
            ],
            'public'            => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'hierarchical'      => true,
        ]);
    }
}
