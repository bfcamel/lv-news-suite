<?php
if (!defined('ABSPATH')) { exit; }

final class LV_News_Migrations
{
    const HOOK = 'lv_news_migration_batch';
    const STATE = 'lv_news_migration_progress';

    public static function boot()
    {
        add_action(self::HOOK, [__CLASS__, 'run_batch']);
    }

    public static function maybe_schedule()
    {
        if ((int) get_option(LV_News_Suite::OPTION_DB_VERSION, 0) >= LV_NEWS_SUITE_DB_VERSION) {
            if (get_option(LV_News_Suite::OPTION_VERSION) !== LV_NEWS_SUITE_VERSION) {
                update_option(LV_News_Suite::OPTION_VERSION, LV_NEWS_SUITE_VERSION, false);
            }
            if ((int) get_option(LV_News_Suite::OPTION_ROUTES_VERSION, 0) !== LV_NEWS_SUITE_ROUTES_VERSION) {
                LV_News_Suite::register_rewrite_rules();
                flush_rewrite_rules(false);
                update_option(LV_News_Suite::OPTION_ROUTES_VERSION, LV_NEWS_SUITE_ROUTES_VERSION, false);
            }
            return;
        }
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + 5, self::HOOK);
        }
    }

    public static function run_batch()
    {
        global $wpdb;
        $lock = LV_News_Lock::acquire('migration', 300);
        if (!$lock) { self::maybe_schedule(); return; }
        try {
            $from = (int) get_option(LV_News_Suite::OPTION_DB_VERSION, 0);
            if ($from >= LV_NEWS_SUITE_DB_VERSION) { return; }
            $state = get_option(self::STATE, []);
            if (!is_array($state) || ($state['target'] ?? 0) !== LV_NEWS_SUITE_DB_VERSION) {
                $state = ['target' => LV_NEWS_SUITE_DB_VERSION, 'last_id' => 0, 'processed' => 0, 'error' => ''];
            }
            if (!$state['last_id']) {
                if (!LV_News_Suite::ensure_archive_page()) { throw new RuntimeException('Не удалось создать страницу новостей.'); }
                if (!$from) { LV_News_Suite::seed_terms(); }
            }
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','future','private') AND ID > %d ORDER BY ID ASC LIMIT 25",
                LV_News_Suite::POST_TYPE, (int) $state['last_id']
            ));
            if ($wpdb->last_error) { throw new RuntimeException('Не удалось прочитать новости для обновления.'); }
            $deadline = microtime(true) + 12;
            foreach ($ids as $id) {
                LV_News_Suite::migrate_one((int) $id, $from);
                $state['last_id'] = (int) $id;
                $state['processed']++;
                $state['error'] = '';
                self::checkpoint($state);
                if (microtime(true) >= $deadline) { break; }
            }
            if (!$ids) {
                LV_News_Suite::normalize_featured_integrity();
                LV_News_Suite::register_rewrite_rules();
                flush_rewrite_rules(false);
                update_option(LV_News_Suite::OPTION_ROUTES_VERSION, LV_NEWS_SUITE_ROUTES_VERSION, false);
                update_option(LV_News_Suite::OPTION_DB_VERSION, LV_NEWS_SUITE_DB_VERSION, false);
                if ((int) get_option(LV_News_Suite::OPTION_DB_VERSION) !== LV_NEWS_SUITE_DB_VERSION) {
                    throw new RuntimeException('Не удалось отметить завершение обновления.');
                }
                update_option(LV_News_Suite::OPTION_VERSION, LV_NEWS_SUITE_VERSION, false);
                update_option(LV_News_Suite::OPTION_LAST_MIGRATION, current_time('mysql'), false);
                delete_option(self::STATE);
            }
        } catch (Throwable $error) {
            $state = get_option(self::STATE, ['target' => LV_NEWS_SUITE_DB_VERSION, 'last_id' => 0, 'processed' => 0]);
            $state['error'] = $error->getMessage();
            update_option(self::STATE, $state, false);
        } finally {
            LV_News_Lock::release('migration', $lock);
            if ((int) get_option(LV_News_Suite::OPTION_DB_VERSION, 0) < LV_NEWS_SUITE_DB_VERSION && !wp_next_scheduled(self::HOOK)) {
                wp_schedule_single_event(time() + 60, self::HOOK);
            }
        }
    }

    private static function checkpoint($state)
    {
        update_option(self::STATE, $state, false);
        if (get_option(self::STATE) !== $state) { throw new RuntimeException('Не удалось сохранить прогресс обновления.'); }
    }
}
