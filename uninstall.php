<?php
/**
 * Removes the plugin's data when it is deleted, if the setting asks for it.
 * Members, payments, board posts, private files, projects, sponsors,
 * resources and settings go; the pages the plugin made are left for you.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('sepo_membership_settings', []);
if (empty($settings['delete_on_uninstall'])) {
    return;
}

global $wpdb;
foreach (['smem_members', 'smem_payments', 'smem_board', 'smem_votes', 'smem_log'] as $t) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $t); // phpcs:ignore WordPress.DB.PreparedSQL
}
foreach (['smem_project', 'smem_sponsor', 'smem_resource'] as $type) {
    $ids = get_posts(['post_type' => $type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']);
    foreach ($ids as $id) {
        wp_delete_post((int) $id, true);
    }
}
$terms = get_terms(['taxonomy' => 'smem_collection', 'hide_empty' => false, 'fields' => 'ids']);
if (is_array($terms)) {
    foreach ($terms as $t) {
        wp_delete_term((int) $t, 'smem_collection');
    }
}
foreach (['sepo_membership_settings', 'sepo_membership_tiers', 'sepo_membership_plans', 'sepo_membership_version'] as $o) {
    delete_option($o);
}
foreach (['_smem_synced_tier', '_smem_lapse_notice', '_smem_board_mail_off'] as $k) {
    delete_metadata('user', 0, $k, '', true);
}
wp_clear_scheduled_hook('sepo_membership_daily');

$uploads = wp_upload_dir(null, false);
$dir = trailingslashit($uploads['basedir']) . 'sepo-membership-private';
if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}
