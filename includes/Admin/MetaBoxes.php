<?php

declare(strict_types=1);

namespace SepoMembership\Admin;

use SepoMembership\Files;
use SepoMembership\Payments;
use SepoMembership\Plugin;
use SepoMembership\PostTypes;
use SepoMembership\Projects;
use SepoMembership\Resources;
use SepoMembership\Settings;
use SepoMembership\Tiers;

defined('ABSPATH') || exit;

/** The extra fields on projects, sponsors and member resources. */
final class MetaBoxes
{
    public static function hook(): void
    {
        add_action('add_meta_boxes', [self::class, 'add']);
        add_action('admin_enqueue_scripts', static function (): void {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && $screen->post_type === PostTypes::RESOURCE && $screen->base === 'post') {
                wp_enqueue_media();
            }
        });
        add_action('save_post_' . PostTypes::PROJECT, [self::class, 'saveProject'], 10, 2);
        add_action('save_post_' . PostTypes::SPONSOR, [self::class, 'saveSponsor'], 10, 2);
        add_action('save_post_' . PostTypes::RESOURCE, [self::class, 'saveResource'], 10, 2);
        add_action('wp_ajax_smem_remove_resource_file', [self::class, 'removeFile']);
        add_action('post_edit_form_tag', static function (\WP_Post $post): void {
            if ($post->post_type === PostTypes::RESOURCE) {
                echo ' enctype="multipart/form-data"';
            }
        });
        add_action('before_delete_post', static function (int $id): void {
            if (get_post_type($id) === PostTypes::RESOURCE) {
                $f = get_post_meta($id, '_smem_file', true);
                if (is_array($f)) {
                    Files::remove('resources', [$f]);
                }
            }
        });
        add_filter('enter_title_here', static function (string $t, \WP_Post $p): string {
            return match ($p->post_type) {
                PostTypes::SPONSOR  => __('Sponsor name', 'sepo-membership'),
                PostTypes::PROJECT  => __('Project name', 'sepo-membership'),
                PostTypes::RESOURCE => __('Resource title', 'sepo-membership'),
                default             => $t,
            };
        }, 10, 2);
    }

    public static function add(): void
    {
        add_meta_box('smem-project', __('Sponsorship', 'sepo-membership'), [self::class, 'project'], PostTypes::PROJECT, 'side', 'high');
        add_meta_box('smem-sponsor', __('Sponsor details', 'sepo-membership'), [self::class, 'sponsor'], PostTypes::SPONSOR, 'normal', 'high');
        add_meta_box('smem-resource', __('Resource', 'sepo-membership'), [self::class, 'resource'], PostTypes::RESOURCE, 'normal', 'high');
    }

    private static function canSave(int $id, string $nonce): bool
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($id)) {
            return false;
        }
        $n = isset($_POST[$nonce]) ? sanitize_text_field(wp_unslash((string) $_POST[$nonce])) : '';
        return $n !== '' && wp_verify_nonce($n, $nonce) && current_user_can('edit_post', $id);
    }

    // ---------------------------------------------------------- projects

    public static function project(\WP_Post $post): void
    {
        wp_nonce_field('smem_project', 'smem_project');
        $p = Projects::get($post->ID);
        echo '<p><label for="smem-goal"><strong>' . esc_html__('Goal', 'sepo-membership') . '</strong> (' . esc_html(Settings::currency()) . ')</label><input type="number" step="any" min="0" class="widefat" id="smem-goal" name="smem_goal" value="' . esc_attr($p && $p['goal'] > 0 ? (string) $p['goal'] : '') . '"></p>'
            . '<p><label for="smem-deadline"><strong>' . esc_html__('Closes on', 'sepo-membership') . '</strong></label><input type="date" class="widefat" id="smem-deadline" name="smem_deadline" value="' . esc_attr($p['deadline'] ?? '') . '"></p>'
            . '<p><label><input type="checkbox" name="smem_close_at_goal" value="1"' . checked($p['close_at_goal'] ?? false, true, false) . '> ' . esc_html__('Close when the goal is reached', 'sepo-membership') . '</label></p>'
            . '<p><label><input type="checkbox" name="smem_closed" value="1"' . checked((int) get_post_meta($post->ID, '_smem_closed', true), 1, false) . '> ' . esc_html__('Closed now', 'sepo-membership') . '</label></p>';
        if ($p !== null) {
            echo '<p class="smem-muted">' . esc_html(sprintf(
                /* translators: 1: amount raised, 2: number of supporters */
                __('Raised %1$s from %2$d supporters.', 'sepo-membership'),
                Settings::money($p['raised']),
                $p['backers']
            )) . ' <a href="' . esc_url(Menu::url('payments', ['status' => 'all', 'kind' => 'gifts'])) . '">' . esc_html__('Payments', 'sepo-membership') . '</a></p>';
        }
        echo '<p class="description">' . esc_html__('The project page shows its progress, a giving form and its sponsors below the description.', 'sepo-membership') . '</p>';
    }

    public static function saveProject(int $id, \WP_Post $post): void
    {
        if (!self::canSave($id, 'smem_project')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- checked in canSave()
        update_post_meta($id, '_smem_goal', max(0, (float) ($_POST['smem_goal'] ?? 0)));
        $d = sanitize_text_field(wp_unslash((string) ($_POST['smem_deadline'] ?? '')));
        update_post_meta($id, '_smem_deadline', preg_match('~^\d{4}-\d{2}-\d{2}$~', $d) ? $d : '');
        update_post_meta($id, '_smem_close_at_goal', !empty($_POST['smem_close_at_goal']) ? 1 : 0);
        update_post_meta($id, '_smem_closed', !empty($_POST['smem_closed']) ? 1 : 0);
        // phpcs:enable
    }

    // ---------------------------------------------------------- sponsors

    public static function sponsor(\WP_Post $post): void
    {
        wp_nonce_field('smem_sponsor', 'smem_sponsor');
        $level = (string) get_post_meta($post->ID, '_smem_level', true);
        $url = (string) get_post_meta($post->ID, '_smem_url', true);
        $mine = array_map('intval', (array) get_post_meta($post->ID, '_smem_projects', true));
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="smem-level">' . esc_html__('Level', 'sepo-membership') . '</label></th><td><select id="smem-level" name="smem_level">';
        foreach (Settings::sponsorLevels() as $id => $l) {
            echo '<option value="' . esc_attr($id) . '"' . selected($level, $id, false) . '>' . esc_html($l['label']) . '</option>';
        }
        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="smem-url">' . esc_html__('Website', 'sepo-membership') . '</label></th><td><input type="url" class="regular-text" id="smem-url" name="smem_url" value="' . esc_attr($url) . '" placeholder="https://"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Sponsors these projects', 'sepo-membership') . '</th><td>';
        $projects = Projects::all();
        if ($projects === []) {
            echo '<span class="smem-muted">' . esc_html__('No projects yet.', 'sepo-membership') . '</span>';
        }
        foreach ($projects as $p) {
            echo '<label class="smem-check"><input type="checkbox" name="smem_projects[]" value="' . (int) $p['id'] . '"' . checked(in_array($p['id'], $mine, true), true, false) . '> ' . esc_html($p['title']) . '</label><br>';
        }
        echo '</td></tr></tbody></table>';
        echo '<p class="description">' . esc_html__('The logo is the featured image. The excerpt is shown under the name. Use Order to place sponsors within their level.', 'sepo-membership') . '</p>';
    }

    public static function saveSponsor(int $id, \WP_Post $post): void
    {
        if (!self::canSave($id, 'smem_sponsor')) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- checked in canSave()
        $level = sanitize_key((string) ($_POST['smem_level'] ?? ''));
        update_post_meta($id, '_smem_level', isset(Settings::sponsorLevels()[$level]) ? $level : '');
        update_post_meta($id, '_smem_url', esc_url_raw(trim((string) wp_unslash($_POST['smem_url'] ?? ''))));
        update_post_meta($id, '_smem_projects', array_values(array_filter(array_map('absint', (array) ($_POST['smem_projects'] ?? [])))));
        // phpcs:enable
    }

    // ---------------------------------------------------------- resources

    public static function resource(\WP_Post $post): void
    {
        wp_nonce_field('smem_resource', 'smem_resource');
        $r = Resources::get($post->ID);
        $type = $r['type'] ?? 'download';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . esc_html__('Kind', 'sepo-membership') . '</th><td>'
            . '<label class="smem-check"><input type="radio" name="smem_type" value="download"' . checked($type, 'download', false) . ' data-smem-kind> ' . esc_html__('A file to download', 'sepo-membership') . '</label> '
            . '<label class="smem-check"><input type="radio" name="smem_type" value="link"' . checked($type, 'link', false) . ' data-smem-kind> ' . esc_html__('A link', 'sepo-membership') . '</label></td></tr>';
        echo '<tr><th scope="row"><label for="smem-tier">' . esc_html__('Plan needed', 'sepo-membership') . '</label></th><td><select id="smem-tier" name="smem_tier"><option value="">' . esc_html__('Any active plan', 'sepo-membership') . '</option>';
        foreach (Tiers::all() as $t) {
            /* translators: %s: plan */
            echo '<option value="' . esc_attr($t['id']) . '"' . selected($r['tier'] ?? '', $t['id'], false) . '>' . esc_html(sprintf(__('%s and above', 'sepo-membership'), $t['label'])) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Members below this plan see the resource marked as locked, with a way to upgrade.', 'sepo-membership') . '</p></td></tr>';

        $source = (string) get_post_meta($post->ID, '_smem_source', true) === 'media' ? 'media' : 'private';
        $private = get_post_meta($post->ID, '_smem_file', true);
        $media = (int) get_post_meta($post->ID, '_smem_attachment', true);
        $mediaPath = $media > 0 ? (string) get_attached_file($media) : '';
        echo '<tr data-smem-for="download"' . ($type === 'link' ? ' hidden' : '') . '><th scope="row">' . esc_html__('File', 'sepo-membership') . '</th><td>'
            . '<p><label class="smem-check"><input type="radio" name="smem_source" value="private"' . checked($source, 'private', false) . ' data-smem-source> ' . esc_html__('Upload a private file', 'sepo-membership') . '</label> '
            . '<label class="smem-check"><input type="radio" name="smem_source" value="media"' . checked($source, 'media', false) . ' data-smem-source> ' . esc_html__('Use a file from the media library', 'sepo-membership') . '</label></p>';

        echo '<div data-smem-src="private"' . ($source === 'media' ? ' hidden' : '') . '>';
        if (is_array($private)) {
            echo '<p data-smem-private-file><strong>' . esc_html((string) $private['name']) . '</strong> · ' . esc_html(size_format((int) ($private['size'] ?? 0), 1))
                . ' <button type="button" class="button-link button-link-delete" data-smem-remove-private="' . (int) $post->ID . '" data-nonce="' . esc_attr(wp_create_nonce('smem_remove_file_' . $post->ID)) . '">' . esc_html__('Remove this file', 'sepo-membership') . '</button></p>';
        }
        echo '<p class="smem-note-ok" data-smem-removed hidden role="status">' . esc_html__('File removed. Members can no longer download it.', 'sepo-membership') . '</p>';
        echo '<label class="screen-reader-text" for="smem-file">' . esc_html__('File to upload', 'sepo-membership') . '</label><input type="file" id="smem-file" name="smem_file">'
            . '<p class="description">' . esc_html__('Stored in a private folder that cannot be opened by its address, and handed out only to members whose plan covers it. Uploading a new file replaces the old one.', 'sepo-membership') . '</p></div>';

        echo '<div data-smem-src="media"' . ($source === 'media' ? '' : ' hidden') . ' data-smem-media>'
            . '<input type="hidden" name="smem_attachment" value="' . (int) $media . '" data-smem-media-id>'
            . '<p data-smem-media-name>' . ($mediaPath !== '' ? '<strong>' . esc_html(wp_basename($mediaPath)) . '</strong> · ' . esc_html(size_format(is_file($mediaPath) ? (int) filesize($mediaPath) : 0, 1)) : '<span class="smem-muted">' . esc_html__('No file chosen.', 'sepo-membership') . '</span>') . '</p>'
            . '<p><button type="button" class="button" data-smem-media-pick>' . esc_html__('Choose from the media library', 'sepo-membership') . '</button> '
            . '<button type="button" class="button-link button-link-delete" data-smem-media-clear' . ($media > 0 ? '' : ' hidden') . '>' . esc_html__('Remove', 'sepo-membership') . '</button></p>'
            . '<p class="description smem-note-warn">' . esc_html__('Members download it through the same plan check. Files in the media library also have a public address of their own, so anyone who has that address can open it. Use a private upload for anything that must stay members-only.', 'sepo-membership') . '</p></div>';
        echo '</td></tr>';
        echo '<tr data-smem-for="link"' . ($type === 'link' ? '' : ' hidden') . '><th scope="row"><label for="smem-link">' . esc_html__('Address', 'sepo-membership') . '</label></th><td><input type="url" class="large-text" id="smem-link" name="smem_url" value="' . esc_attr($r['url'] ?? '') . '" placeholder="https://"><p class="description">' . esc_html__('Never shown in the page: members are sent on to it after their plan is checked.', 'sepo-membership') . '</p></td></tr>';
        echo '</tbody></table><p class="description">' . esc_html__('The excerpt is shown as the description. Collections let different members areas show different resources.', 'sepo-membership') . '</p>';
        $err = get_transient('smem_res_err_' . get_current_user_id());
        if ($err) {
            delete_transient('smem_res_err_' . get_current_user_id());
            echo '<div class="notice notice-error inline smem-notice-tint smem-notice-tint--bad"><p>' . esc_html((string) $err) . '</p></div>';
        }
    }

    /** Remove a resource's private file at once, from the button beside it. */
    public static function removeFile(): void
    {
        $id = absint($_POST['post'] ?? 0);
        if ($id < 1 || !check_ajax_referer('smem_remove_file_' . $id, 'nonce', false) || !current_user_can('edit_post', $id) || !Plugin::canManage() || get_post_type($id) !== PostTypes::RESOURCE) {
            wp_send_json_error(['message' => __('You cannot change this resource.', 'sepo-membership')], 403);
        }
        $f = get_post_meta($id, '_smem_file', true);
        if (is_array($f)) {
            Files::remove('resources', [$f]);
        }
        delete_post_meta($id, '_smem_file');
        wp_send_json_success();
    }

    public static function saveResource(int $id, \WP_Post $post): void
    {
        if (!self::canSave($id, 'smem_resource') || !Plugin::canManage()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- checked in canSave()
        $type = ($_POST['smem_type'] ?? '') === 'link' ? 'link' : 'download';
        update_post_meta($id, '_smem_type', $type);
        $tier = sanitize_key((string) ($_POST['smem_tier'] ?? ''));
        update_post_meta($id, '_smem_tier', Tiers::get($tier) !== null ? $tier : '');
        $url = esc_url_raw(trim((string) wp_unslash($_POST['smem_url'] ?? '')), ['http', 'https']);
        update_post_meta($id, '_smem_url', $url);

        update_post_meta($id, '_smem_source', ($_POST['smem_source'] ?? '') === 'media' ? 'media' : 'private');
        $att = absint($_POST['smem_attachment'] ?? 0);
        update_post_meta($id, '_smem_attachment', $att > 0 && get_post_type($att) === 'attachment' ? $att : 0);

        $old = get_post_meta($id, '_smem_file', true);
        if (!empty($_POST['smem_remove_file']) && is_array($old)) {
            Files::remove('resources', [$old]);
            delete_post_meta($id, '_smem_file');
            $old = null;
        }
        if (isset($_FILES['smem_file']) && is_array($_FILES['smem_file']) && (int) ($_FILES['smem_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $server = max(1, (int) floor(wp_max_upload_size() / 1048576));
                $stored = Files::store('resources', Files::fromUpload($_FILES['smem_file']), Files::resourceMimes(), $server, 1);
                if ($stored !== []) {
                    if (is_array($old)) {
                        Files::remove('resources', [$old]);
                    }
                    update_post_meta($id, '_smem_file', $stored[0]);
                }
            } catch (\Throwable $e) {
                set_transient('smem_res_err_' . get_current_user_id(), $e->getMessage(), 120);
            }
        }
        // phpcs:enable
    }
}
