<?php
if (!defined('ABSPATH')) {
    exit;
}

final class LV_News_Suite
{
    const POST_TYPE = 'lv_news';
    const TAXONOMY = 'lv_news_type';

    const OPTION_VERSION = 'lv_news_suite_plugin_version';
    const OPTION_DB_VERSION = 'lv_news_suite_schema_version';
    const OPTION_ROUTES_VERSION = 'lv_news_suite_routes_version';
    const OPTION_LAST_MIGRATION = 'lv_news_suite_last_migration';
    const MIGRATION_LOCK = 'lv_news_suite_migration_lock';

    const META_FEATURED = '_lv_news_featured';
    const META_FOCUS_X = '_lv_news_featured_focus_x';
    const META_FOCUS_Y = '_lv_news_featured_focus_y';
    const META_FOCUS_ZOOM = '_lv_news_featured_focus_zoom';
    const META_EDITOR_VERSION = '_lv_news_editor_version';
    const META_QUALITY_SCORE = '_lv_news_quality_score';
    const META_SEO_TITLE = '_lv_news_seo_title';
    const META_SEO_DESCRIPTION = '_lv_news_seo_description';
    const META_SEO_CANONICAL = '_lv_news_seo_canonical';
    const META_SEO_FOCUS_KEYWORD = '_lv_news_seo_focus_keyword';
    const META_OG_TITLE = '_lv_news_og_title';
    const META_OG_DESCRIPTION = '_lv_news_og_description';

    /** @var int|null */
    private static $archive_page_id_cache = null;

    /** @var int|null */
    private static $featured_id_cache = null;

    /** @var array<string,int>|null */
    private static $pagination_cache = null;

    public static function boot()
    {
        add_action('init', [__CLASS__, 'register_content_types'], 5);
        add_action('init', [__CLASS__, 'register_meta'], 10);
        add_action('init', [__CLASS__, 'register_media_sizes'], 15);
        add_action('init', [__CLASS__, 'register_rewrite_rules'], 20);
        add_action('init', [__CLASS__, 'run_migrations'], 80);

        add_filter('query_vars', [__CLASS__, 'query_vars']);
        add_filter('wp_insert_post_data', [__CLASS__, 'normalize_news_slug'], 20, 2);

        add_action('save_post_' . self::POST_TYPE, [__CLASS__, 'after_save'], 80, 3);
        LV_News_Rank_Math::boot();
        LV_News_Migrations::boot();
        add_action('rest_after_insert_' . self::POST_TYPE, [__CLASS__, 'after_rest_save'], 80, 3);

        LV_News_Editor::boot();
        LV_News_Public::boot();
        LV_News_SEO::boot();
        LV_News_Admin::boot();
    }

    public static function activate()
    {
        self::register_content_types();
        self::ensure_archive_page();
        self::register_rewrite_rules();
        self::run_migrations(true);
        update_option(self::OPTION_VERSION, LV_NEWS_SUITE_VERSION, false);
        update_option(self::OPTION_ROUTES_VERSION, LV_NEWS_SUITE_ROUTES_VERSION, false);
        flush_rewrite_rules(false);
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(LV_News_Migrations::HOOK);
        flush_rewrite_rules(false);
    }

    public static function register_content_types()
    {
        $labels = [
            'name' => 'Новости',
            'singular_name' => 'Новость',
            'menu_name' => 'Новости',
            'name_admin_bar' => 'Новость',
            'add_new' => 'Добавить новость',
            'add_new_item' => 'Добавить новую новость',
            'edit_item' => 'Редактировать новость',
            'new_item' => 'Новая новость',
            'view_item' => 'Посмотреть новость',
            'view_items' => 'Посмотреть новости',
            'search_items' => 'Найти новость',
            'not_found' => 'Новости не найдены',
            'not_found_in_trash' => 'В корзине новостей нет',
            'all_items' => 'Все новости',
            'archives' => 'Архив новостей',
            'attributes' => 'Параметры новости',
            'featured_image' => 'Главное изображение новости',
            'set_featured_image' => 'Выбрать главное изображение',
            'remove_featured_image' => 'Удалить главное изображение',
            'use_featured_image' => 'Использовать как главное изображение',
            'item_published' => 'Новость опубликована.',
            'item_reverted_to_draft' => 'Новость возвращена в черновики.',
            'item_scheduled' => 'Публикация новости запланирована.',
            'item_updated' => 'Новость обновлена.',
        ];

        register_post_type(self::POST_TYPE, [
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_in_admin_bar' => true,
            'show_in_nav_menus' => false,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-megaphone',
            'menu_position' => 20,
            'supports' => [
                'title',
                'editor',
                'thumbnail',
                'excerpt',
                'author',
                'revisions',
                // Обязательно для REST-сохранения register_post_meta().
                // Без этого Gutenberg показывает локальное значение meta,
                // но WordPress не включает его в REST schema и после Save
                // поле возвращается пустым.
                'custom-fields',
            ],
            'has_archive' => false,
            'rewrite' => [
                'slug' => 'news',
                'with_front' => false,
            ],
            'query_var' => true,
            'exclude_from_search' => false,
            'delete_with_user' => false,
            'taxonomies' => [self::TAXONOMY],
        ]);

        register_taxonomy(self::TAXONOMY, [self::POST_TYPE], [
            'labels' => [
                'name' => 'Типы новостей',
                'singular_name' => 'Тип новости',
                'search_items' => 'Найти тип',
                'all_items' => 'Все типы',
                'edit_item' => 'Редактировать тип',
                'update_item' => 'Обновить тип',
                'add_new_item' => 'Добавить тип новости',
                'new_item_name' => 'Название типа',
                'menu_name' => 'Типы новостей',
            ],
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => true,
            'show_admin_column' => false,
            'show_in_quick_edit' => true,
            'show_in_rest' => true,
            'hierarchical' => false,
            'rewrite' => false,
            'query_var' => false,
            'meta_box_cb' => false,
            'default_term' => [
                'name' => 'Новости фонда',
                'slug' => 'fond',
            ],
        ]);

    }

    public static function seed_terms()
    {
        if (!taxonomy_exists(self::TAXONOMY)) {
            return;
        }

        $terms = [
            ['name' => 'Новости фонда', 'slug' => 'fond'],
            ['name' => 'Подопечные', 'slug' => 'wards'],
            ['name' => 'События', 'slug' => 'events'],
            ['name' => 'Помощь животным', 'slug' => 'help'],
            ['name' => 'Волонтёры', 'slug' => 'volunteers'],
        ];

        foreach ($terms as $term) {
            if (!term_exists($term['slug'], self::TAXONOMY)) {
                wp_insert_term($term['name'], self::TAXONOMY, ['slug' => $term['slug']]);
            }
        }
    }

    public static function register_media_sizes()
    {
        /*
         * WordPress 7.1 генерирует зарегистрированные sub-sizes прямо в
         * браузере на поддерживаемых устройствах. Размеры без жёсткого crop:
         * точку кадрирования по-прежнему задаёт наш object-position.
         */
        add_image_size('lv-news-featured', 1280, 0, false);
        add_image_size('lv-news-card', 720, 0, false);
        add_image_size('lv-news-single', 1600, 0, false);
        add_image_size('lv-news-mini', 320, 0, false);
    }

    public static function register_meta()
    {
        $auth = static function ($allowed, $meta_key, $post_id) {
            $post_id = absint($post_id);
            return $post_id > 0
                ? current_user_can('edit_post', $post_id)
                : current_user_can('edit_posts');
        };

        register_post_meta(self::POST_TYPE, self::META_FEATURED, [
            'single' => true,
            'type' => 'boolean',
            'default' => false,
            'show_in_rest' => true,
            'sanitize_callback' => static function ($value) {
                return (bool) $value;
            },
            'auth_callback' => static function ($allowed, $meta_key, $post_id) {
                $post_id = absint($post_id);
                return current_user_can('publish_posts')
                    && ($post_id === 0 || current_user_can('edit_post', $post_id));
            },
        ]);

        foreach ([self::META_FOCUS_X, self::META_FOCUS_Y] as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'single' => true,
                'type' => 'integer',
                'default' => 50,
                'show_in_rest' => true,
                'revisions_enabled' => true,
                'sanitize_callback' => static function ($value) {
                    return max(0, min(100, (int) $value));
                },
                'auth_callback' => $auth,
            ]);
        }

        register_post_meta(self::POST_TYPE, self::META_FOCUS_ZOOM, [
            'single' => true,
            'type' => 'integer',
            'default' => 100,
            'show_in_rest' => true,
            'revisions_enabled' => true,
            'sanitize_callback' => static function ($value) {
                return max(100, min(180, (int) $value));
            },
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_EDITOR_VERSION, [
            'single' => true,
            'type' => 'string',
            'default' => LV_NEWS_SUITE_VERSION,
            'show_in_rest' => true,
            'sanitize_callback' => 'sanitize_text_field',
            'auth_callback' => $auth,
        ]);

        register_post_meta(self::POST_TYPE, self::META_QUALITY_SCORE, [
            'single' => true,
            'type' => 'integer',
            'default' => 0,
            'show_in_rest' => true,
            'sanitize_callback' => 'absint',
            'auth_callback' => $auth,
        ]);

        $string_meta = [
            self::META_SEO_TITLE,
            self::META_SEO_DESCRIPTION,
            self::META_OG_TITLE,
            self::META_OG_DESCRIPTION,
            self::META_SEO_FOCUS_KEYWORD,
        ];

        foreach ($string_meta as $key) {
            register_post_meta(self::POST_TYPE, $key, [
                'single' => true,
                'type' => 'string',
                'default' => '',
                'show_in_rest' => true,
                'revisions_enabled' => true,
                'sanitize_callback' => 'sanitize_text_field',
                'auth_callback' => [LV_News_Rank_Math::class, 'authorize_local_meta'],
            ]);
        }

        register_post_meta(self::POST_TYPE, self::META_SEO_CANONICAL, [
            'single' => true,
            'type' => 'string',
            'default' => '',
            'show_in_rest' => true,
            'revisions_enabled' => true,
            'sanitize_callback' => 'esc_url_raw',
            'auth_callback' => [LV_News_Rank_Math::class, 'authorize_local_meta'],
        ]);

        /*
         * Не регистрируем rank_math_* от имени LV News Suite. Схема, REST-
         * доступ и rank_math_seo_score принадлежат Rank Math. Повторная
         * регистрация делала два независимых источника данных и могла
         * перезаписывать ручные изменения Rank Math старым значением.
         */
    }

    public static function ensure_archive_page()
    {
        $existing = self::get_archive_page_id();
        if ($existing > 0) {
            return $existing;
        }

        $page_id = wp_insert_post([
            'post_type' => 'page',
            'post_status' => 'publish',
            'post_title' => 'Новости',
            'post_name' => 'news',
            'post_content' => '[lv_news_archive]',
            'comment_status' => 'closed',
            'ping_status' => 'closed',
        ], true);

        self::$archive_page_id_cache = is_wp_error($page_id) ? 0 : (int) $page_id;
        return self::$archive_page_id_cache;
    }

    public static function register_rewrite_rules()
    {
        $page_id = self::get_archive_page_id();
        $target = $page_id > 0
            ? 'index.php?page_id=' . $page_id
            : 'index.php?pagename=news';

        add_rewrite_rule(
            '^news/?$',
            $target,
            'top'
        );

        add_rewrite_rule(
            '^news/page/([0-9]+)/?$',
            $target . '&lv_news_page=$matches[1]',
            'top'
        );
    }

    public static function get_archive_page_id()
    {
        if (self::$archive_page_id_cache !== null) {
            return self::$archive_page_id_cache;
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => 1,
            'name' => 'news',
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (!empty($pages)) {
            self::$archive_page_id_cache = (int) $pages[0];
            return self::$archive_page_id_cache;
        }

        $pages = get_posts([
            'post_type' => 'page',
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => 20,
            's' => '[lv_news_archive]',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
        ]);

        foreach ($pages as $page) {
            if ($page instanceof WP_Post && has_shortcode($page->post_content, 'lv_news_archive')) {
                self::$archive_page_id_cache = (int) $page->ID;
                return self::$archive_page_id_cache;
            }
        }

        self::$archive_page_id_cache = 0;
        return self::$archive_page_id_cache;
    }

    public static function query_vars($vars)
    {
        $vars[] = 'lv_news_page';
        return $vars;
    }

    public static function run_migrations($force = false)
    {
        // Activation must not rescan or overwrite an already migrated archive.
        LV_News_Migrations::maybe_schedule();
    }

    public static function migrate_one($post_id, $from_version)
    {
        // Preserve established values, including intentional empty SEO fields.
        if ($from_version < 6) {
            self::ensure_rank_math_defaults($post_id);
            self::migrate_rank_math_meta($post_id);
        }
        foreach ([self::META_FOCUS_X => 50, self::META_FOCUS_Y => 50, self::META_FOCUS_ZOOM => 100] as $key => $value) {
            if (!metadata_exists('post', $post_id, $key)) {
                self::write_meta_checked($post_id, $key, $value);
            }
        }
        self::write_meta_checked($post_id, self::META_EDITOR_VERSION, LV_NEWS_SUITE_VERSION);
        self::write_meta_checked($post_id, self::META_QUALITY_SCORE, self::calculate_quality($post_id));
    }

    public static function write_meta_checked($post_id, $key, $value)
    {
        update_post_meta($post_id, $key, $value);
        if (!metadata_exists('post', $post_id, $key) || (string) get_post_meta($post_id, $key, true) !== (string) $value) {
            throw new RuntimeException('Не удалось сохранить метаданные новости #' . absint($post_id));
        }
    }

    public static function normalize_featured_integrity()
    {
        $featured = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::META_FEATURED,
            'meta_value' => '1',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (count($featured) <= 1) {
            return;
        }

        $keep = array_shift($featured);
        foreach ($featured as $post_id) {
            if ((int) $post_id !== (int) $keep) {
                delete_post_meta($post_id, self::META_FEATURED);
            }
        }

        self::clear_runtime_caches();
    }

    public static function after_save($post_id, $post, $update)
    {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        update_post_meta($post_id, self::META_EDITOR_VERSION, LV_NEWS_SUITE_VERSION);
        if (in_array($post->post_status, ['publish', 'future'], true)) {
            delete_post_meta($post_id, '_lv_news_publication_error');
        }
        self::store_quality_score($post_id);
        self::clear_runtime_caches();

        if ($post->post_status === 'publish' && self::is_featured($post_id)) {
            self::make_featured($post_id);
        }
    }

    public static function after_rest_save($post, $request, $creating)
    {
        if (!$post instanceof WP_Post) {
            return;
        }

        // Core saved content/meta. Rank Math owns SEO persistence in its own request.
        update_post_meta($post->ID, self::META_EDITOR_VERSION, LV_NEWS_SUITE_VERSION);
        self::store_quality_score($post->ID);
        self::clear_runtime_caches();

        if ($post->post_status === 'publish' && self::is_featured($post->ID)) {
            self::make_featured($post->ID);
        }
    }

    public static function make_featured($post_id)
    {
        $post_id = absint($post_id);
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'publish') {
            return false;
        }

        $lock = LV_News_Lock::acquire('featured');
        if (!$lock) {
            return false;
        }
        try {
        $others = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'post__not_in' => [$post_id],
            'meta_key' => self::META_FEATURED,
            'meta_value' => '1',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($others as $other_id) {
            delete_post_meta($other_id, self::META_FEATURED);
        }

        update_post_meta($post_id, self::META_FEATURED, 1);
        self::clear_runtime_caches();
        return true;
        } finally {
            LV_News_Lock::release('featured', $lock);
        }
    }

    public static function is_featured($post_id)
    {
        return (bool) get_post_meta($post_id, self::META_FEATURED, true);
    }

    public static function get_featured_id()
    {
        if (self::$featured_id_cache !== null) {
            return self::$featured_id_cache;
        }

        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::META_FEATURED,
            'meta_value' => '1',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        if (!empty($ids)) {
            self::$featured_id_cache = (int) $ids[0];
            return self::$featured_id_cache;
        }

        $ids = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        self::$featured_id_cache = !empty($ids) ? (int) $ids[0] : 0;
        return self::$featured_id_cache;
    }

    public static function get_featured_focus($post_id)
    {
        $x = get_post_meta($post_id, self::META_FOCUS_X, true);
        $y = get_post_meta($post_id, self::META_FOCUS_Y, true);
        $zoom = get_post_meta($post_id, self::META_FOCUS_ZOOM, true);

        return [
            'x' => $x === '' ? 50 : max(0, min(100, (int) $x)),
            'y' => $y === '' ? 50 : max(0, min(100, (int) $y)),
            'zoom' => $zoom === '' ? 100 : max(100, min(180, (int) $zoom)),
        ];
    }

    public static function archive_url($page = 1)
    {
        $base = trailingslashit(home_url('/news/'));
        $page = max(1, absint($page));
        return $page === 1 ? $base : $base . 'page/' . $page . '/';
    }

    public static function archive_pagination_data()
    {
        if (self::$pagination_cache !== null) {
            return self::$pagination_cache;
        }

        $featured_id = self::get_featured_id();
        $counts = wp_count_posts(self::POST_TYPE);
        $total = isset($counts->publish) ? (int) $counts->publish : 0;
        $normal_count = max(0, $total - ($featured_id ? 1 : 0));
        $first_grid = $featured_id ? 9 : 10;

        if ($total <= 0) {
            $pages = 1;
        } elseif ($normal_count <= $first_grid) {
            $pages = 1;
        } else {
            $pages = 1 + (int) ceil(($normal_count - $first_grid) / 12);
        }

        self::$pagination_cache = [
            'featured_id' => $featured_id,
            'total' => $total,
            'normal_count' => $normal_count,
            'first_grid' => $first_grid,
            'total_pages' => max(1, $pages),
        ];

        return self::$pagination_cache;
    }

    public static function request_path()
    {
        $path = isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        $base = rtrim((string) wp_parse_url(home_url('/'), PHP_URL_PATH), '/');
        if ($base !== '' && ($path === $base || strpos($path, $base . '/') === 0)) {
            $path = substr($path, strlen($base));
        }
        return '/' . ltrim($path, '/');
    }

    public static function is_archive_context()
    {
        if (is_singular(self::POST_TYPE)) {
            return false;
        }

        $archive_page_id = self::get_archive_page_id();
        if ($archive_page_id && is_page($archive_page_id)) {
            return true;
        }

        if (is_page('news')) {
            return true;
        }

        $path = self::request_path();
        return is_string($path) && (bool) preg_match('#^/news(?:/page/[0-9]+)?/?$#', $path);
    }

    public static function normalize_news_slug($data, $postarr)
    {
        if (!isset($data['post_type']) || $data['post_type'] !== self::POST_TYPE) {
            return $data;
        }

        $post_id = isset($postarr['ID']) ? absint($postarr['ID']) : 0;
        if ($post_id > 0) {
            $existing = get_post($post_id);
            if ($existing && $existing->post_status === 'publish' && !empty($existing->post_name)) {
                $incoming = isset($data['post_name']) ? trim((string) $data['post_name']) : '';
                if ($incoming === '' || $incoming === $existing->post_name) {
                    $data['post_name'] = $existing->post_name;
                    return $data;
                }
            }
        }

        $raw_slug = isset($data['post_name']) ? urldecode((string) $data['post_name']) : '';
        $needs_ascii = $raw_slug === '' || preg_match('/[^\x20-\x7E]/', $raw_slug);

        if ($needs_ascii) {
            $source = !empty($data['post_title']) ? $data['post_title'] : $raw_slug;
            $data['post_name'] = self::ascii_slug($source);
        } else {
            $data['post_name'] = sanitize_title($raw_slug);
        }

        if ($data['post_name'] === '') {
            $data['post_name'] = 'news-' . wp_generate_password(8, false, false);
        }

        $data['post_name'] = wp_unique_post_slug(
            $data['post_name'], $post_id, $data['post_status'] ?? 'draft',
            self::POST_TYPE, isset($data['post_parent']) ? (int) $data['post_parent'] : 0
        );
        return $data;
    }

    public static function ascii_slug($text)
    {
        $map = [
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Е'=>'E','Ё'=>'E','Ж'=>'Zh','З'=>'Z','И'=>'I','Й'=>'Y','К'=>'K','Л'=>'L','М'=>'M','Н'=>'N','О'=>'O','П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','У'=>'U','Ф'=>'F','Х'=>'Kh','Ц'=>'Ts','Ч'=>'Ch','Ш'=>'Sh','Щ'=>'Shch','Ъ'=>'','Ы'=>'Y','Ь'=>'','Э'=>'E','Ю'=>'Yu','Я'=>'Ya',
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'kh','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];

        $text = strtr((string) $text, $map);
        $text = remove_accents($text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        return trim((string) $text, '-');
    }

    public static function calculate_quality($post_id)
    {
        $post = get_post($post_id);
        if (!$post) {
            return 0;
        }

        $title = trim(wp_strip_all_tags($post->post_title));
        $excerpt = trim(wp_strip_all_tags($post->post_excerpt));
        $content = trim(wp_strip_all_tags(strip_shortcodes($post->post_content)));
        $thumb = has_post_thumbnail($post_id);
        $terms = wp_get_object_terms($post_id, self::TAXONOMY, ['fields' => 'ids']);
        $term_ok = !is_wp_error($terms) && !empty($terms);
        $alt = '';
        $image_width = 0;
        $image_height = 0;

        $thumb_id = get_post_thumbnail_id($post_id);
        if ($thumb_id) {
            $alt = trim((string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true));
            $metadata = wp_get_attachment_metadata($thumb_id);
            if (is_array($metadata)) {
                $image_width = isset($metadata['width']) ? (int) $metadata['width'] : 0;
                $image_height = isset($metadata['height']) ? (int) $metadata['height'] : 0;
            }
        }

        $links_ready = true;
        if (class_exists('WP_HTML_Tag_Processor')) {
            $processor = new WP_HTML_Tag_Processor((string) $post->post_content);
            while ($processor->next_tag('a')) {
                $href = trim((string) $processor->get_attribute('href'));
                if ($href === '' || $href === '#' || preg_match('/^javascript:/i', $href)) {
                    $links_ready = false;
                    break;
                }
            }
        } elseif (preg_match_all('/<a\b[^>]*>/i', (string) $post->post_content, $links)) {
            foreach ($links[0] as $link) {
                $href = '';
                if (preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i', $link, $match)) {
                    $href = trim((string) $match[2]);
                }
                if ($href === '' || $href === '#' || preg_match('/^javascript:/i', $href)) {
                    $links_ready = false;
                    break;
                }
            }
        }

        $length = static function ($value) {
            return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        };

        $checks = [
            $title !== '',
            $excerpt !== '',
            $content !== '',
            $thumb,
            $term_ok,
            $length($title) >= 15 && $length($title) <= 110,
            $length($excerpt) >= 100 && $length($excerpt) <= 260,
            $length($content) >= 300,
            $alt !== '',
            $thumb && ($image_width === 0 || ($image_width >= 1200 && $image_height >= 700)),
            $links_ready,
            !preg_match('/<h(?:1|4|5|6)(?:\s|>)/i', $post->post_content),
        ];

        $done = count(array_filter($checks));
        return (int) round(($done / count($checks)) * 100);
    }

    private static function store_quality_score($post_id)
    {
        update_post_meta($post_id, self::META_QUALITY_SCORE, self::calculate_quality($post_id));
    }

    public static function rank_math_is_active()
    {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath') || function_exists('rank_math');
    }

    /**
     * Возвращает реальную настройку SEO Controls для типа записи. null
     * означает, что Rank Math активен, но его helper API недоступен в этой
     * точке загрузки; клиентская часть тогда проверит runtime самостоятельно.
     *
     * @return bool|null
     */
    public static function rank_math_seo_controls_enabled()
    {
        if (!self::rank_math_is_active()) {
            return false;
        }

        try {
            if (is_callable(['\\RankMath\\Helper', 'get_allowed_post_types'])) {
                $post_types = \RankMath\Helper::get_allowed_post_types();
                return is_array($post_types) && in_array(self::POST_TYPE, $post_types, true);
            }

            if (is_callable(['\\RankMath\\Helper', 'get_settings'])) {
                $value = \RankMath\Helper::get_settings('titles.pt_' . self::POST_TYPE . '_add_meta_box');
                return !in_array($value, [false, null, '', 0, '0', 'off'], true);
            }
        } catch (Throwable $error) {
            return null;
        }

        return null;
    }

    /**
     * Проверяет права текущего пользователя на редактор Rank Math, когда их
     * официальный helper уже загружен.
     *
     * @return bool|null
     */
    public static function rank_math_user_can_edit()
    {
        if (!self::rank_math_is_active()) {
            return false;
        }

        try {
            if (is_callable(['\\RankMath\\Helpers\\Editor', 'can_add_editor'])) {
                return (bool) \RankMath\Helpers\Editor::can_add_editor();
            }
        } catch (Throwable $error) {
            return null;
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private static function rank_math_meta_map()
    {
        return [
            self::META_SEO_TITLE => 'rank_math_title',
            self::META_SEO_DESCRIPTION => 'rank_math_description',
            self::META_SEO_CANONICAL => 'rank_math_canonical_url',
            self::META_OG_TITLE => 'rank_math_facebook_title',
            self::META_OG_DESCRIPTION => 'rank_math_facebook_description',
            self::META_SEO_FOCUS_KEYWORD => 'rank_math_focus_keyword',
        ];
    }

    private static function sanitize_seo_meta_value($local_key, $value)
    {
        if ($local_key === self::META_SEO_CANONICAL) {
            return esc_url_raw((string) $value);
        }

        return sanitize_text_field((string) $value);
    }

    private static function migrate_rank_math_meta($post_id)
    {
        if (!self::rank_math_is_active()) {
            return;
        }

        foreach (self::rank_math_meta_map() as $local_key => $rank_key) {
            $local_value = trim((string) get_post_meta($post_id, $local_key, true));
            $rank_value = trim((string) get_post_meta($post_id, $rank_key, true));
            $value = $rank_value !== '' ? $rank_value : $local_value;
            if ($value === '') {
                continue;
            }

            $value = self::sanitize_seo_meta_value($local_key, $value);
            self::write_meta_checked($post_id, $local_key, $value);
            self::write_meta_checked($post_id, $rank_key, $value);
        }
    }


    private static function ensure_rank_math_defaults($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        $title = trim(wp_strip_all_tags($post->post_title));
        $excerpt = trim(wp_strip_all_tags($post->post_excerpt));
        $content = trim(wp_strip_all_tags(strip_shortcodes($post->post_content), true));

        $site_name = trim((string) get_bloginfo('name'));
        $default_title = $title;
        if ($site_name !== '' && $title !== '') {
            $candidate = $title . ' | ' . $site_name;
            $default_title = self::limit_text($candidate, 60);
        } elseif ($title !== '') {
            $default_title = self::limit_text($title, 60);
        }

        $description_source = $excerpt !== '' ? $excerpt : $content;
        $default_focus = self::focus_keyword_from_title($title);
        $default_description = self::ensure_focus_in_description(
            self::limit_text($description_source, 158),
            $default_focus,
            158
        );

        $pairs = [
            self::META_SEO_TITLE => ['rank_math_title', $default_title],
            self::META_SEO_DESCRIPTION => ['rank_math_description', $default_description],
            self::META_OG_TITLE => ['rank_math_facebook_title', $default_title],
            self::META_OG_DESCRIPTION => ['rank_math_facebook_description', $default_description],
            self::META_SEO_FOCUS_KEYWORD => ['rank_math_focus_keyword', $default_focus],
        ];

        foreach ($pairs as $local_key => $pair) {
            if (trim((string) get_post_meta($post_id, $local_key, true)) !== '') {
                continue;
            }

            $rank_value = trim((string) get_post_meta($post_id, $pair[0], true));
            $value = $rank_value !== '' ? $rank_value : trim((string) $pair[1]);
            if ($value !== '') {
                self::write_meta_checked($post_id, $local_key, $value);
            }
        }
    }

    private static function limit_text($text, $limit)
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $text)));
        $limit = max(1, (int) $limit);

        $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
        if ($length <= $limit) {
            return $text;
        }

        $slice = function_exists('mb_substr') ? mb_substr($text, 0, $limit - 1) : substr($text, 0, $limit - 1);
        $slice = preg_replace('/\s+\S*$/u', '', $slice);
        return rtrim((string) $slice, " \t\n\r\0\x0B,.;:—-") . '…';
    }

    private static function ensure_focus_in_description($description, $focus, $limit = 158)
    {
        $description = trim((string) preg_replace('/\s+/u', ' ', (string) $description));
        $focus = trim((string) preg_replace('/\s+/u', ' ', (string) $focus));
        if ($focus === '' || $description === '') {
            return $description;
        }

        $haystack = function_exists('mb_strtolower') ? mb_strtolower($description) : strtolower($description);
        $needle = function_exists('mb_strtolower') ? mb_strtolower($focus) : strtolower($focus);
        $contains = function_exists('mb_strpos') ? mb_strpos($haystack, $needle) !== false : strpos($haystack, $needle) !== false;
        if ($contains) {
            return $description;
        }

        $first = function_exists('mb_substr') ? mb_substr($focus, 0, 1) : substr($focus, 0, 1);
        $rest = function_exists('mb_substr') ? mb_substr($focus, 1) : substr($focus, 1);
        $first = function_exists('mb_strtoupper') ? mb_strtoupper($first) : strtoupper($first);
        return self::limit_text($first . $rest . '. ' . $description, (int) $limit);
    }

    private static function focus_keyword_from_title($title)
    {
        $title = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $title)));
        if ($title === '') {
            return '';
        }

        /*
         * Полный заголовок часто слишком длинный для focus keyword и даёт
         * Rank Math низкую оценку из-за отсутствия точного вхождения во всех
         * элементах. Берём естественный непрерывный фрагмент 2–5 слов.
         * Если заголовок состоит из двух частей через двоеточие/тире, сначала
         * пробуем короткую первую часть. Начальные служебные слова вроде
         * «нам», «мы», «наш» убираем, не разрушая саму фразу.
         */
        $candidate = $title;
        $parts = preg_split('/\s*[:—–]\s*/u', $title, 2);
        if (is_array($parts) && !empty($parts[0])) {
            $first_words = preg_split('/\s+/u', trim($parts[0]));
            $first_count = is_array($first_words) ? count(array_filter($first_words, 'strlen')) : 0;
            if ($first_count >= 2 && $first_count <= 5) {
                $candidate = trim($parts[0]);
            }
        }

        $words = preg_split('/\s+/u', $candidate);
        if (!is_array($words)) {
            return self::limit_text($candidate, 70);
        }

        $leading_stopwords = [
            'мы', 'нам', 'нас', 'наш', 'наша', 'наше', 'наши',
            'я', 'мне', 'мой', 'моя', 'это', 'этот', 'эта', 'эти',
            'и', 'а', 'но', 'в', 'во', 'на', 'о', 'об', 'для', 'к', 'по', 'из',
        ];

        while (count($words) > 2) {
            $first = trim((string) $words[0], " \t\n\r\0\x0B,.;:!?«»\"'()[]");
            $first = function_exists('mb_strtolower') ? mb_strtolower($first) : strtolower($first);
            if (!in_array($first, $leading_stopwords, true)) {
                break;
            }
            array_shift($words);
        }

        $words = array_slice(array_values(array_filter($words, 'strlen')), 0, 5);
        return self::limit_text(implode(' ', $words), 70);
    }

    private static function clear_runtime_caches()
    {
        self::$featured_id_cache = null;
        self::$pagination_cache = null;
    }
}
