<?php
/**
 * NE Med Lab Prescriptions Plugin - Uninstall
 * This file runs when the plugin is deleted through WordPress admin
 * It completely removes all plugin-related data and files
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Remove custom database table
global $wpdb;
$table_name = $wpdb->prefix . 'prescriptions';
$wpdb->query("DROP TABLE IF EXISTS $table_name");

// Remove all plugin options and meta
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ne_mlp_%'");
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_ne_mlp_%'");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE 'ne_mlp_%'");

// Remove uploaded prescription files
$upload_dir = wp_upload_dir();
$prescription_dir = $upload_dir['basedir'] . '/ne-mlp-prescriptions';

if (is_dir($prescription_dir)) {
    // Delete all files in the directory
    $files = glob($prescription_dir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    // Remove the directory
    rmdir($prescription_dir);
}

// Remove rewrite endpoints
delete_option('rewrite_rules');

// Clear any cached data
wp_cache_flush();

// Log the uninstall action
error_log('NE Med Lab Prescriptions Plugin: Complete uninstall completed successfully'); 