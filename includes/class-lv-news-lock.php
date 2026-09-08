<?php
if (!defined('ABSPATH')) { exit; }

/** A database-backed lease shared by concurrent PHP requests. */
final class LV_News_Lock
{
    public static function acquire($name, $seconds = 120)
    {
        global $wpdb;
        $key = 'lv_news_lock_' . sanitize_key($name);
        $token = (time() + $seconds) . ':' . wp_generate_uuid4();
        if (add_option($key, $token, '', false)) {
            return $token;
        }
        // Read the database, not a possibly stale persistent object cache.
        $old = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key));
        if (!$old || (int) $old >= time()) { return false; }
        $changed = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $token, $key, $old
        ));
        wp_cache_delete($key, 'options');
        return $changed === 1 ? $token : false;
    }

    public static function release($name, $token)
    {
        global $wpdb;
        $key = 'lv_news_lock_' . sanitize_key($name);
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $key, $token));
        wp_cache_delete($key, 'options');
    }
}
