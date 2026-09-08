<?php
if (!defined('ABSPATH')) { exit; }

/** Rank Math is the only writer while active. Local fields are revision/fallback mirrors. */
final class LV_News_Rank_Math
{
    private static $locks = [];
    private static $mirroring = false;

    public static function boot()
    {
        add_filter('rest_pre_insert_lv_news', [__CLASS__, 'guard_local_rest'], 4, 2);
        add_filter('rest_request_before_callbacks', [__CLASS__, 'before_rank_save'], 10, 3);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'after_rank_save'], 10, 3);
        foreach (['added_post_meta', 'updated_post_meta', 'deleted_post_meta'] as $hook) {
            add_action($hook, [__CLASS__, 'mirror_meta'], 10, 4);
        }
        add_action('wp_restore_post_revision', [__CLASS__, 'restore_revision'], 20, 2);
    }

    public static function fields()
    {
        return [
            '_lv_news_seo_title' => 'rank_math_title',
            '_lv_news_seo_description' => 'rank_math_description',
            '_lv_news_seo_canonical' => 'rank_math_canonical_url',
            '_lv_news_seo_focus_keyword' => 'rank_math_focus_keyword',
            '_lv_news_og_title' => 'rank_math_facebook_title',
            '_lv_news_og_description' => 'rank_math_facebook_description',
        ];
    }

    public static function can_edit($post_id, $local_key)
    {
        if ($post_id ? !current_user_can('edit_post', $post_id) : !current_user_can('edit_posts')) { return false; }
        if (!LV_News_Suite::rank_math_is_active()) { return true; }
        if (!is_callable(['\\RankMath\\Helper', 'has_cap'])) { return false; }
        $cap = $local_key === '_lv_news_seo_canonical' ? 'onpage_advanced'
            : (strpos($local_key, '_lv_news_og_') === 0 ? 'onpage_social' : 'onpage_general');
        return (bool) \RankMath\Helper::has_cap($cap);
    }

    public static function authorize_local_meta($allowed, $meta_key, $post_id)
    {
        return self::can_edit(absint($post_id), $meta_key);
    }

    public static function snapshot($post_id)
    {
        $values = [];
        foreach (self::fields() as $rank_key) { $values[$rank_key] = (string) get_post_meta($post_id, $rank_key, true); }
        return $values;
    }

    public static function guard_local_rest($prepared, $request)
    {
        if (is_wp_error($prepared) || !LV_News_Suite::rank_math_is_active()) { return $prepared; }
        $id = absint($request->get_param('id'));
        $meta = $request->get_param('meta');
        if (!is_array($meta)) { return $prepared; }
        foreach (self::fields() as $key => $rank_key) {
            if (!array_key_exists($key, $meta)) { continue; }
            // Gutenberg may include unchanged registered fields. Remove them before core writes meta.
            if ((string) $meta[$key] !== (string) get_post_meta($id, $key, true)) {
                return new WP_Error('lv_news_seo_owner', 'SEO сохраняется через Rank Math. Обновите редактор перед повторным сохранением.', ['status' => 409]);
            }
            unset($meta[$key]);
        }
        $request->set_param('meta', $meta);
        return $prepared;
    }

    private static function rank_post_id($request)
    {
        if (rtrim($request->get_route(), '/') !== '/rankmath/v1/updateMeta' || $request->get_param('objectType') !== 'post') { return 0; }
        $id = absint($request->get_param('objectID'));
        return get_post_type($id) === LV_News_Suite::POST_TYPE ? $id : 0;
    }

    public static function before_rank_save($response, $handler, $request)
    {
        $id = self::rank_post_id($request);
        if (!$id || $response !== null) { return $response; }
        $meta = $request->get_param('meta');
        if (!is_array($meta)) { return $response; }
        $baseline = $request->get_param('lv_news_seo_baseline');
        $has_fields = false;
        foreach (self::fields() as $local => $rank) {
            if (!array_key_exists($rank, $meta)) { continue; }
            $has_fields = true;
            if (!self::can_edit($id, $local)) {
                return new WP_Error('lv_news_seo_forbidden', 'Недостаточно прав для изменения этих SEO-полей.', ['status' => 403]);
            }
        }
        if (!$has_fields) { return $response; }
        $lock = LV_News_Lock::acquire('seo_' . $id);
        if (!$lock) { return new WP_Error('lv_news_seo_busy', 'SEO этой новости сейчас сохраняется. Повторите попытку.', ['status' => 409]); }
        $current = self::snapshot($id);
        if (is_array($baseline)) {
            foreach (self::fields() as $rank) {
                if (array_key_exists($rank, $meta) && (!array_key_exists($rank, $baseline) || (string) $baseline[$rank] !== $current[$rank])) {
                    LV_News_Lock::release('seo_' . $id, $lock);
                    return new WP_Error('lv_news_seo_conflict', 'SEO изменилось в другом окне. Скопируйте свои правки и обновите страницу.', ['status' => 409]);
                }
            }
        }
        self::$locks[spl_object_hash($request)] = [$id, $lock];
        return $response;
    }

    public static function after_rank_save($response, $handler, $request)
    {
        $hash = spl_object_hash($request);
        if (isset(self::$locks[$hash])) {
            list($id, $lock) = self::$locks[$hash];
            unset(self::$locks[$hash]);
            if (!is_wp_error($response)) {
                $response = rest_ensure_response($response);
                $data = $response->get_data();
                if (is_array($data)) {
                    $data['lv_news_seo_snapshot'] = self::snapshot($id);
                    $response->set_data($data);
                }
                // Native SEO can finish after the content request created its revision.
                // Capture the confirmed mirror as well; core skips identical revisions.
                if ($response->get_status() < 400) { wp_save_post_revision($id); }
            }
            LV_News_Lock::release('seo_' . $id, $lock);
        }
        return $response;
    }

    public static function mirror_meta($meta_id, $post_id, $meta_key, $value)
    {
        if (self::$mirroring || get_post_type($post_id) !== LV_News_Suite::POST_TYPE) { return; }
        $local = array_search($meta_key, self::fields(), true);
        if ($local === false) { return; }
        self::$mirroring = true;
        try {
            // Read persisted value: deletion deliberately mirrors an empty string.
            update_post_meta($post_id, $local, (string) get_post_meta($post_id, $meta_key, true));
        } finally { self::$mirroring = false; }
    }

    public static function restore_revision($post_id, $revision_id)
    {
        if (!LV_News_Suite::rank_math_is_active() || get_post_type($post_id) !== LV_News_Suite::POST_TYPE) { return; }
        $lock = LV_News_Lock::acquire('seo_' . $post_id);
        if (!$lock) {
            foreach (self::fields() as $local => $rank) {
                update_post_meta($post_id, $local, (string) get_post_meta($post_id, $rank, true));
            }
            set_transient('lv_news_seo_restore_notice_' . get_current_user_id(), 1, 120);
            return;
        }
        try {
            foreach (self::fields() as $local => $rank) {
                if (self::can_edit($post_id, $local)) {
                    update_post_meta($post_id, $rank, (string) get_post_meta($post_id, $local, true));
                } else {
                    // Core restores local revision meta first. Do not let it bypass SEO permissions.
                    update_post_meta($post_id, $local, (string) get_post_meta($post_id, $rank, true));
                }
            }
        } finally { LV_News_Lock::release('seo_' . $post_id, $lock); }
    }
}
