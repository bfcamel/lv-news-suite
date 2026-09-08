<?php
if (!defined('ABSPATH')) {
    exit;
}

final class LV_News_Admin
{
    public static function boot()
    {
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 30);
        add_filter('manage_' . LV_News_Suite::POST_TYPE . '_posts_columns', [__CLASS__, 'columns']);
        add_action('manage_' . LV_News_Suite::POST_TYPE . '_posts_custom_column', [__CLASS__, 'column_content'], 10, 2);
        add_filter('manage_edit-' . LV_News_Suite::POST_TYPE . '_sortable_columns', [__CLASS__, 'sortable_columns']);
        add_filter('post_row_actions', [__CLASS__, 'row_actions'], 20, 2);
        add_action('admin_action_lv_news_duplicate', [__CLASS__, 'duplicate']);
        add_action('admin_action_lv_news_make_featured', [__CLASS__, 'make_featured']);
        add_action('restrict_manage_posts', [__CLASS__, 'filters']);
        add_action('pre_get_posts', [__CLASS__, 'apply_filters']);
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_action('admin_head-edit.php', [__CLASS__, 'list_css']);
    }

    public static function admin_menu()
    {
        add_submenu_page(
            'edit.php?post_type=' . LV_News_Suite::POST_TYPE,
            'Система новостей',
            'Система новостей',
            'manage_options',
            'lv-news-system',
            [__CLASS__, 'system_page']
        );
    }

    public static function columns($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'title') {
                $new['lv_news_thumb'] = 'Фото';
                $new[$key] = $label;
                $new['lv_news_type'] = 'Тип';
                $new['lv_news_featured'] = 'Главная';
                $new['lv_news_quality'] = 'Готовность';
                continue;
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public static function column_content($column, $post_id)
    {
        if ($column === 'lv_news_thumb') {
            if (has_post_thumbnail($post_id)) {
                echo get_the_post_thumbnail($post_id, [64, 48], ['class' => 'lv-news-list-thumb']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo '<span class="lv-news-list-no-thumb">—</span>';
            }
            return;
        }

        if ($column === 'lv_news_type') {
            $terms = wp_get_post_terms($post_id, LV_News_Suite::TAXONOMY, ['fields' => 'names']);
            echo esc_html(!is_wp_error($terms) && $terms ? implode(', ', $terms) : '—');
            return;
        }

        if ($column === 'lv_news_featured') {
            if (LV_News_Suite::is_featured($post_id) && get_post_status($post_id) === 'publish') {
                echo '<span class="lv-news-featured-badge">Главная</span>';
            } else {
                echo '<span class="lv-news-muted">—</span>';
            }
            return;
        }

        if ($column === 'lv_news_quality') {
            $score = get_post_meta($post_id, LV_News_Suite::META_QUALITY_SCORE, true);
            if ($score === '') {
                $score = LV_News_Suite::calculate_quality($post_id);
            }
            $score = max(0, min(100, (int) $score));
            $class = $score >= 80 ? 'is-good' : ($score >= 50 ? 'is-warn' : 'is-low');
            echo '<span class="lv-news-quality ' . esc_attr($class) . '"><span style="width:' . esc_attr((string) $score) . '%"></span><strong>' . esc_html((string) $score) . '%</strong></span>';
        }
    }

    public static function sortable_columns($columns)
    {
        $columns['lv_news_quality'] = 'lv_news_quality';
        return $columns;
    }

    public static function row_actions($actions, $post)
    {
        if (!$post instanceof WP_Post || $post->post_type !== LV_News_Suite::POST_TYPE) {
            return $actions;
        }

        if (current_user_can('edit_post', $post->ID)) {
            $duplicate_url = wp_nonce_url(
                admin_url('admin.php?action=lv_news_duplicate&post=' . $post->ID),
                'lv_news_duplicate_' . $post->ID
            );
            $actions['lv_news_duplicate'] = '<a href="' . esc_url($duplicate_url) . '">Дублировать</a>';
        }

        $preview = get_preview_post_link($post);
        if ($preview) {
            $actions['lv_news_preview'] = '<a href="' . esc_url($preview) . '" target="_blank" rel="noopener">Предпросмотр</a>';
        }

        if ($post->post_status === 'publish' && current_user_can('publish_posts') && current_user_can('edit_post', $post->ID) && !LV_News_Suite::is_featured($post->ID)) {
            $featured_url = wp_nonce_url(
                admin_url('admin.php?action=lv_news_make_featured&post=' . $post->ID),
                'lv_news_make_featured_' . $post->ID
            );
            $actions['lv_news_featured'] = '<a href="' . esc_url($featured_url) . '">Сделать главной</a>';
        }

        return $actions;
    }

    public static function duplicate()
    {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$post_id || !current_user_can('edit_post', $post_id)) {
            wp_die('Недостаточно прав для дублирования новости.');
        }

        check_admin_referer('lv_news_duplicate_' . $post_id);
        $source = get_post($post_id);
        if (!$source || $source->post_type !== LV_News_Suite::POST_TYPE) {
            wp_die('Новость не найдена.');
        }

        $new_id = wp_insert_post([
            'post_type' => LV_News_Suite::POST_TYPE,
            'post_status' => 'draft',
            'post_title' => $source->post_title . ' — копия',
            'post_content' => $source->post_content,
            'post_excerpt' => $source->post_excerpt,
            'post_author' => get_current_user_id(),
            'post_name' => '',
        ], true);

        if (is_wp_error($new_id)) {
            wp_die(esc_html($new_id->get_error_message()));
        }

        $thumb = get_post_thumbnail_id($post_id);
        if ($thumb) {
            set_post_thumbnail($new_id, $thumb);
        }

        $terms = wp_get_object_terms($post_id, LV_News_Suite::TAXONOMY, ['fields' => 'ids']);
        if (!is_wp_error($terms)) {
            wp_set_object_terms($new_id, array_map('intval', $terms), LV_News_Suite::TAXONOMY);
        }

        $copy_meta = [
            LV_News_Suite::META_FOCUS_X,
            LV_News_Suite::META_FOCUS_Y,
            LV_News_Suite::META_FOCUS_ZOOM,
            LV_News_Suite::META_SEO_TITLE,
            LV_News_Suite::META_SEO_DESCRIPTION,
            LV_News_Suite::META_SEO_FOCUS_KEYWORD,
            LV_News_Suite::META_OG_TITLE,
            LV_News_Suite::META_OG_DESCRIPTION,
        ];

        foreach ($copy_meta as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ($value !== '') {
                update_post_meta($new_id, $key, $value);
            }
        }

        delete_post_meta($new_id, LV_News_Suite::META_FEATURED);
        wp_safe_redirect(add_query_arg(['post' => $new_id, 'action' => 'edit', 'lv_news_duplicated' => 1], admin_url('post.php')));
        exit;
    }

    public static function make_featured()
    {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$post_id || !current_user_can('publish_posts') || !current_user_can('edit_post', $post_id)) {
            wp_die('Недостаточно прав для назначения главной новости.');
        }

        check_admin_referer('lv_news_make_featured_' . $post_id);
        if (!LV_News_Suite::make_featured($post_id)) {
            wp_die('Главной можно назначить только опубликованную новость.');
        }

        wp_safe_redirect(add_query_arg([
            'post_type' => LV_News_Suite::POST_TYPE,
            'lv_news_featured_changed' => 1,
        ], admin_url('edit.php')));
        exit;
    }

    public static function filters($post_type)
    {
        if ($post_type !== LV_News_Suite::POST_TYPE) {
            return;
        }

        $selected = isset($_GET['lv_news_type_filter']) ? sanitize_key(wp_unslash($_GET['lv_news_type_filter'])) : '';
        wp_dropdown_categories([
            'show_option_all' => 'Все типы новостей',
            'taxonomy' => LV_News_Suite::TAXONOMY,
            'name' => 'lv_news_type_filter',
            'orderby' => 'name',
            'selected' => $selected,
            'hierarchical' => false,
            'hide_empty' => false,
            'value_field' => 'slug',
        ]);

        $author_id = isset($_GET['lv_news_author_filter']) ? absint($_GET['lv_news_author_filter']) : 0;
        wp_dropdown_users([
            'name' => 'lv_news_author_filter',
            'selected' => $author_id,
            'show_option_all' => 'Все авторы',
            'who' => 'authors',
            'capability' => ['edit_posts'],
        ]);
    }

    public static function apply_filters($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== LV_News_Suite::POST_TYPE) {
            return;
        }

        if ($query->get('orderby') === 'lv_news_quality') {
            $query->set('meta_key', LV_News_Suite::META_QUALITY_SCORE);
            $query->set('orderby', 'meta_value_num');
        }

        $type = isset($_GET['lv_news_type_filter']) ? sanitize_key(wp_unslash($_GET['lv_news_type_filter'])) : '';
        if ($type !== '') {
            $query->set('tax_query', [[
                'taxonomy' => LV_News_Suite::TAXONOMY,
                'field' => 'slug',
                'terms' => [$type],
            ]]);
        }

        $author_id = isset($_GET['lv_news_author_filter']) ? absint($_GET['lv_news_author_filter']) : 0;
        if ($author_id > 0) {
            $query->set('author', $author_id);
        }
    }

    public static function notices()
    {
        if (!empty($_GET['lv_news_duplicated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Создана копия новости как черновик. Статус «Главная новость» не перенесён.</p></div>';
        }
        if (!empty($_GET['lv_news_featured_changed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Главная новость изменена.</p></div>';
        }
    }

    public static function list_css()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== LV_News_Suite::POST_TYPE) {
            return;
        }
        ?>
        <style>
            .column-lv_news_thumb{width:76px}.lv-news-list-thumb{display:block;width:64px;height:48px;object-fit:cover;border-radius:6px}.column-lv_news_type{width:150px}.column-lv_news_featured{width:100px}.column-lv_news_quality{width:145px}.lv-news-featured-badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#8ed4d3;color:#12414c;font-size:11px;font-weight:700}.lv-news-muted{color:#8a8a8a}.lv-news-quality{position:relative;display:block;width:112px;height:22px;overflow:hidden;border-radius:999px;background:#eef0f0}.lv-news-quality>span{position:absolute;inset:0 auto 0 0;background:#8ed4d3}.lv-news-quality.is-warn>span{background:#e8c46a}.lv-news-quality.is-low>span{background:#e6a098}.lv-news-quality strong{position:relative;z-index:2;display:block;padding:2px 8px;color:#18434e;font-size:11px;line-height:18px;text-align:center}
        </style>
        <?php
    }

    public static function system_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $pagination = LV_News_Suite::archive_pagination_data();
        $featured_id = (int) $pagination['featured_id'];
        $featured_count = 0;
        if ($featured_id) {
            $featured_count = count(get_posts([
                'post_type' => LV_News_Suite::POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'meta_key' => LV_News_Suite::META_FEATURED,
                'meta_value' => '1',
                'fields' => 'ids',
                'no_found_rows' => true,
            ]));
        }

        $archive_page_id = LV_News_Suite::get_archive_page_id();
        $news_page = $archive_page_id ? get_post($archive_page_id) : null;
        $page_has_shortcode = $news_page instanceof WP_Post && has_shortcode($news_page->post_content, 'lv_news_archive');
        $rank_math_active = LV_News_Suite::rank_math_is_active();
        $rank_math_controls = LV_News_Suite::rank_math_seo_controls_enabled();
        $rank_math_access = LV_News_Suite::rank_math_user_can_edit();
        $registered_meta = function_exists('get_registered_meta_keys')
            ? get_registered_meta_keys('post', LV_News_Suite::POST_TYPE)
            : [];
        $revision_keys = [
            LV_News_Suite::META_FOCUS_X,
            LV_News_Suite::META_FOCUS_Y,
            LV_News_Suite::META_FOCUS_ZOOM,
            LV_News_Suite::META_SEO_TITLE,
            LV_News_Suite::META_SEO_DESCRIPTION,
            LV_News_Suite::META_SEO_CANONICAL,
        ];
        $revision_meta_ok = !empty($registered_meta);
        foreach ($revision_keys as $key) {
            if (empty($registered_meta[$key]['revisions_enabled'])) {
                $revision_meta_ok = false;
                break;
            }
        }
        $migration = get_option(LV_News_Migrations::STATE, []);
        $migration_status = !empty($migration['error']) ? 'Ошибка: ' . $migration['error']
            : (!empty($migration) ? 'Обработано: ' . (int) ($migration['processed'] ?? 0) : 'Нет незавершённых обновлений');
        $items = [
            ['Обновление базы', $migration_status, empty($migration['error'])],
            ['WordPress', get_bloginfo('version') . ' (минимум 7.1)', version_compare(get_bloginfo('version'), '7.1', '>=')],
            ['PHP', PHP_VERSION . ' (минимум 7.4)', version_compare(PHP_VERSION, '7.4', '>=')],
            ['Версия плагина', LV_NEWS_SUITE_VERSION, true],
            ['Версия схемы данных', (string) get_option(LV_News_Suite::OPTION_DB_VERSION, 0), (int) get_option(LV_News_Suite::OPTION_DB_VERSION, 0) === LV_NEWS_SUITE_DB_VERSION],
            ['Версия маршрутов', (string) get_option(LV_News_Suite::OPTION_ROUTES_VERSION, 0), (int) get_option(LV_News_Suite::OPTION_ROUTES_VERSION, 0) === LV_NEWS_SUITE_ROUTES_VERSION],
            ['Последняя миграция', (string) get_option(LV_News_Suite::OPTION_LAST_MIGRATION, '—'), true],
            ['Опубликовано новостей', (string) $pagination['total'], true],
            ['Главная новость', $featured_id ? get_the_title($featured_id) . ' (#' . $featured_id . ')' : 'Пока нет опубликованных новостей', $featured_count <= 1],
            ['Целостность главной новости', $featured_count <= 1 ? 'Не более одной опубликованной главной' : 'Найдено несколько главных', $featured_count <= 1],
            ['Страница /news/', $news_page ? 'Найдена' : 'Не найдена', (bool) $news_page],
            ['Shortcode [lv_news_archive]', $page_has_shortcode ? 'Присутствует на странице /news/' : 'Не найден на странице /news/', $page_has_shortcode],
            ['Rank Math', $rank_math_active ? 'Сохраняет SEO; локальные поля служат резервной копией' : 'Не обнаружен — используется резервный SEO-вывод', $rank_math_active],
            ['Rank Math SEO Controls', !$rank_math_active
                ? 'Недоступны без Rank Math'
                : ($rank_math_controls === true
                    ? 'Включены для типа записи «Новости»'
                    : ($rank_math_controls === false ? 'Выключены для типа записи «Новости»' : 'Состояние проверит редактор в браузере')),
                $rank_math_active && $rank_math_controls !== false],
            ['Права на редактор Rank Math', !$rank_math_active
                ? 'Недоступны без Rank Math'
                : ($rank_math_access === false ? 'У текущего пользователя нет on-page SEO прав' : 'Доступ разрешён или будет проверен в браузере'),
                $rank_math_active && $rank_math_access !== false],
            ['Meta в ревизиях', $revision_meta_ok ? 'Кадрирование и SEO включены в autosave/preview' : 'Не все поля доступны ревизиям', $revision_meta_ok],
            ['Редактор ' . LV_NEWS_SUITE_VERSION, 'Материал → Фото → Проверка → Предпросмотр → Публикация', true],
        ];
        ?>
        <div class="wrap">
            <h1>Система новостей</h1>
            <p>Диагностика LV News Suite <?php echo esc_html(LV_NEWS_SUITE_VERSION); ?>. Обновление сохраняет CPT <code>lv_news</code>, таксономию <code>lv_news_type</code>, shortcode и публичные URL.</p>
            <table class="widefat striped" style="max-width:980px;margin-top:22px">
                <thead><tr><th>Проверка</th><th>Состояние</th><th style="width:110px">Результат</th></tr></thead>
                <tbody>
                    <?php foreach ($items as $item) : ?>
                        <tr><td><strong><?php echo esc_html($item[0]); ?></strong></td><td><?php echo esc_html($item[1]); ?></td><td><?php echo $item[2] ? '<span style="color:#18723a;font-weight:700">✓ OK</span>' : '<span style="color:#b32d2e;font-weight:700">! Проверить</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top:22px"><a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . LV_News_Suite::POST_TYPE)); ?>">Создать тестовую новость</a> <a class="button" href="<?php echo esc_url(LV_News_Suite::archive_url()); ?>" target="_blank" rel="noopener">Открыть /news/</a></p>
        </div>
        <?php
    }
}
