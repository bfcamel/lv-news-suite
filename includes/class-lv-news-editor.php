<?php
if (!defined('ABSPATH')) {
    exit;
}

final class LV_News_Editor
{
    private static $rest_validated = false;
    private static $guarding = false;
    public static function boot()
    {
        add_action('enqueue_block_editor_assets', [__CLASS__, 'enqueue_assets'], 30);
        add_action('enqueue_block_assets', [__CLASS__, 'enqueue_content_assets'], 30);
        add_filter('allowed_block_types_all', [__CLASS__, 'allowed_blocks'], 20, 2);
        add_filter('block_editor_settings_all', [__CLASS__, 'editor_settings'], 20, 2);
        add_filter('block_categories_all', [__CLASS__, 'block_categories'], 20, 2);
        add_filter('rest_pre_insert_' . LV_News_Suite::POST_TYPE, [__CLASS__, 'validate_publication'], 20, 2);
        add_filter('wp_insert_post_data', [__CLASS__, 'normalize_content_headings'], 25, 2);
        add_action('init', [__CLASS__, 'register_patterns'], 30);
        add_filter('wp_insert_post_data', [__CLASS__, 'guard_publication'], 30, 2);
        add_action('transition_post_status', [__CLASS__, 'guard_transition'], 1, 3);
        add_action('publish_future_post', [__CLASS__, 'guard_scheduled_publication'], 1);
        add_action('admin_notices', [__CLASS__, 'publication_notice']);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'reset_rest_validation'], 99, 3);
    }

    public static function allowed_blocks($allowed, $context)
    {
        if (empty($context->post) || $context->post->post_type !== LV_News_Suite::POST_TYPE) {
            return $allowed;
        }

        return self::allowed_block_names();
    }

    public static function allowed_block_names()
    {
        return [
            'core/paragraph',
            'core/list',
            'core/list-item',
            'core/quote',
            'core/image',
            'core/gallery',
            'core/separator',
            'core/table',
            'core/buttons',
            'core/button',
            'lv-news/heading-2',
            'lv-news/heading-3',
            'lv-news/lead',
            'lv-news/brand-accent',
            'lv-news/notice',
            'lv-news/callout',
            'lv-news/fact',
            'lv-news/cta',
            'lv-news/source-note',
        ];
    }

    /**
     * Отдельная группа редакционных блоков. Core Heading намеренно не включён
     * в allowed_blocks: через slash inserter пользователь видит только H2/H3.
     */
    public static function block_categories($categories, $context)
    {
        if (empty($context->post) || $context->post->post_type !== LV_News_Suite::POST_TYPE) {
            return $categories;
        }

        foreach ($categories as $category) {
            if (($category['slug'] ?? '') === 'lv-news') {
                return $categories;
            }
        }

        array_unshift($categories, [
            'slug' => 'lv-news',
            'title' => 'Новости фонда',
            'icon' => null,
        ]);

        return $categories;
    }

    public static function editor_settings($settings, $context)
    {
        if (empty($context->post) || $context->post->post_type !== LV_News_Suite::POST_TYPE) {
            return $settings;
        }

        if (!current_user_can('manage_options')) {
            $settings['codeEditingEnabled'] = false;
        }

        $settings['canLockBlocks'] = false;
        $settings['titlePlaceholder'] = 'Введите понятный заголовок новости';

        /*
         * WordPress 7.1 всегда использует iframe для post editor. Контентные
         * стили загружаются штатно через enqueue_block_assets(), а не через
         * ручное внедрение CSS/доступ к iframe document.
         */
        $settings['allowedBlockTypes'] = self::allowed_block_names();

        return $settings;
    }

    public static function enqueue_content_assets()
    {
        if (!is_admin()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== LV_News_Suite::POST_TYPE) {
            return;
        }

        /*
         * В WordPress 7.1 enqueue_block_assets — штатный способ загрузить
         * стили пользовательского контента прямо внутрь iframe редактора.
         */
        wp_enqueue_style(
            'lv-news-editor-content',
            LV_NEWS_SUITE_URL . 'assets/editor-content.css',
            [],
            LV_NEWS_SUITE_VERSION
        );
    }

    public static function enqueue_assets()
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== LV_News_Suite::POST_TYPE) {
            return;
        }

        $terms = get_terms([
            'taxonomy' => LV_News_Suite::TAXONOMY,
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);

        if (is_wp_error($terms)) {
            $terms = [];
        }

        $term_options = [];
        foreach ($terms as $term) {
            $term_options[] = [
                'id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
            ];
        }

        wp_enqueue_media();
        wp_enqueue_style(
            'lv-news-editor',
            LV_NEWS_SUITE_URL . 'assets/editor.css',
            ['wp-edit-blocks'],
            LV_NEWS_SUITE_VERSION
        );

        $script_dependencies = [
            'wp-plugins',
            'wp-edit-post',
            'wp-element',
            'wp-components',
            'wp-data',
            'wp-blocks',
            'wp-block-editor',
            'wp-i18n',
            'wp-api-fetch',
            'wp-notices',
            'wp-hooks',
        ];

        $rank_math_active = LV_News_Suite::rank_math_is_active();
        $rank_math_controls = LV_News_Suite::rank_math_seo_controls_enabled();
        $rank_math_access = LV_News_Suite::rank_math_user_can_edit();

        wp_enqueue_script(
            'lv-news-editor',
            LV_NEWS_SUITE_URL . 'assets/editor.js',
            $script_dependencies,
            LV_NEWS_SUITE_VERSION,
            true
        );

        $user = wp_get_current_user();
        $post_id = get_the_ID();
        $seo_permissions = [];
        foreach (LV_News_Rank_Math::fields() as $local_key => $rank_key) {
            $seo_permissions[$local_key] = LV_News_Rank_Math::can_edit($post_id, $local_key);
        }
        $config = [
            'version' => LV_NEWS_SUITE_VERSION,
            'seoPermissions' => $seo_permissions,
            'postType' => LV_News_Suite::POST_TYPE,
            'taxonomy' => LV_News_Suite::TAXONOMY,
            'terms' => $term_options,
            'canPublish' => current_user_can('publish_posts'),
            'canManage' => current_user_can('manage_options'),
            'currentUser' => [
                'id' => (int) $user->ID,
                'name' => (string) $user->display_name,
            ],
            'archiveUrl' => LV_News_Suite::archive_url(),
            'siteName' => (string) get_bloginfo('name'),
            'allowedBlocks' => self::allowed_block_names(),
            'rankMath' => [
                'active' => $rank_math_active,
                'baseline' => LV_News_Rank_Math::snapshot($post_id),
                'seoControls' => $rank_math_controls,
                'userAccess' => $rank_math_access,
                'settingsUrl' => admin_url('admin.php?page=rank-math-options-titles&view=post-type-' . LV_News_Suite::POST_TYPE),
                'fields' => [
                    'seoTitle' => [
                        'meta' => 'rank_math_title',
                        'selector' => 'getTitle',
                        'actions' => ['updateSerpTitle', 'updateTitle'],
                    ],
                    'seoDescription' => [
                        'meta' => 'rank_math_description',
                        'selector' => 'getDescription',
                        'actions' => ['updateSerpDescription', 'updateDescription'],
                    ],
                    'seoCanonical' => [
                        'meta' => 'rank_math_canonical_url',
                        'selector' => 'getCanonicalUrl',
                        'actions' => ['updateCanonicalUrl'],
                    ],
                    'seoFocusKeyword' => [
                        'meta' => 'rank_math_focus_keyword',
                        'selector' => 'getKeywords',
                        'actions' => ['updateKeywords'],
                    ],
                    'ogTitle' => [
                        'meta' => 'rank_math_facebook_title',
                        'selector' => 'getFacebookTitle',
                        'actions' => ['updateFacebookTitle'],
                    ],
                    'ogDescription' => [
                        'meta' => 'rank_math_facebook_description',
                        'selector' => 'getFacebookDescription',
                        'actions' => ['updateFacebookDescription'],
                    ],
                ],
            ],
            'meta' => [
                'featured' => LV_News_Suite::META_FEATURED,
                'focusX' => LV_News_Suite::META_FOCUS_X,
                'focusY' => LV_News_Suite::META_FOCUS_Y,
                'focusZoom' => LV_News_Suite::META_FOCUS_ZOOM,
                'editorVersion' => LV_News_Suite::META_EDITOR_VERSION,
                'qualityScore' => LV_News_Suite::META_QUALITY_SCORE,
                'seoTitle' => LV_News_Suite::META_SEO_TITLE,
                'seoDescription' => LV_News_Suite::META_SEO_DESCRIPTION,
                'seoCanonical' => LV_News_Suite::META_SEO_CANONICAL,
                'seoFocusKeyword' => LV_News_Suite::META_SEO_FOCUS_KEYWORD,
                'ogTitle' => LV_News_Suite::META_OG_TITLE,
                'ogDescription' => LV_News_Suite::META_OG_DESCRIPTION,
            ],
        ];

        wp_add_inline_script(
            'lv-news-editor',
            'window.LVNewsEditorConfig=' . wp_json_encode(
                $config,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
            ) . ';',
            'before'
        );
    }

    public static function validate_publication($prepared_post, $request)
    {
        if (!($prepared_post instanceof stdClass) && !($prepared_post instanceof WP_Post)) {
            return $prepared_post;
        }

        $status = isset($prepared_post->post_status) ? (string) $prepared_post->post_status : '';
        self::$rest_validated = false;
        if (!in_array($status, ['publish', 'future'], true)) {
            return $prepared_post;
        }

        $post_id = isset($request['id']) ? absint($request['id']) : 0;
        $existing = $post_id ? get_post($post_id) : null;

        /*
         * Уже опубликованные материалы можно править без жёсткой миграции
         * старого контента. Проверка обязательных полей применяется именно
         * к первому переводу черновика в publish.
         */
        if ($existing && $existing->post_status === 'publish') {
            self::$rest_validated = true;
            return $prepared_post;
        }

        /*
         * REST может прислать при публикации только изменившиеся поля.
         * Поэтому для черновика берём отсутствующие значения из записи,
         * а не выдаём ложное сообщение «поле не заполнено».
         */
        $title_source = isset($prepared_post->post_title)
            ? $prepared_post->post_title
            : ($existing ? $existing->post_title : '');

        $excerpt_source = isset($prepared_post->post_excerpt)
            ? $prepared_post->post_excerpt
            : ($existing ? $existing->post_excerpt : '');

        $content_source = isset($prepared_post->post_content)
            ? $prepared_post->post_content
            : ($existing ? $existing->post_content : '');

        $title = trim(wp_strip_all_tags($title_source));
        $excerpt = trim(wp_strip_all_tags($excerpt_source));
        $content = trim(wp_strip_all_tags(strip_shortcodes($content_source)));

        if (isset($request['featured_media'])) {
            $featured_media = absint($request['featured_media']);
        } elseif ($post_id) {
            $featured_media = (int) get_post_thumbnail_id($post_id);
        } else {
            $featured_media = 0;
        }

        if (isset($request[LV_News_Suite::TAXONOMY])) {
            $term_ids = (array) $request[LV_News_Suite::TAXONOMY];
        } elseif ($post_id) {
            $term_ids = wp_get_object_terms($post_id, LV_News_Suite::TAXONOMY, ['fields' => 'ids']);
            if (is_wp_error($term_ids)) {
                $term_ids = [];
            }
        } else {
            $term_ids = [];
        }

        $missing = [];
        if ($title === '') {
            $missing[] = 'заголовок';
        }
        if ($excerpt === '') {
            $missing[] = 'краткий анонс';
        }
        if ($content === '') {
            $missing[] = 'основной текст';
        }
        if (!$featured_media || !wp_attachment_is_image($featured_media)) {
            $missing[] = 'главное изображение';
        }
        if (empty(array_filter(array_map('absint', $term_ids)))) {
            $missing[] = 'тип новости';
        }

        if (!empty($missing)) {
            return new WP_Error(
                'lv_news_incomplete',
                'Перед публикацией заполните: ' . implode(', ', $missing) . '.',
                ['status' => 400]
            );
        }

        self::$rest_validated = true;
        return $prepared_post;
    }

    public static function guard_publication($data, $postarr)
    {
        if (($data['post_type'] ?? '') !== LV_News_Suite::POST_TYPE || !in_array($data['post_status'] ?? '', ['publish', 'future'], true)) { return $data; }
        if (self::$rest_validated) { return $data; }
        $id = absint($postarr['ID'] ?? 0);
        if ($id && get_post_status($id) === 'publish') { return $data; }
        $thumb = isset($postarr['meta_input']['_thumbnail_id']) ? absint($postarr['meta_input']['_thumbnail_id']) : get_post_thumbnail_id($id);
        $terms = $postarr['tax_input'][LV_News_Suite::TAXONOMY] ?? ($id ? wp_get_object_terms($id, LV_News_Suite::TAXONOMY, ['fields' => 'ids']) : []);
        if (is_wp_error($terms)) { $terms = []; }
        $missing = self::missing_fields($data, $thumb, $terms);
        if ($missing) {
            $data['post_status'] = 'draft';
            self::remember_notice($missing);
        }
        return $data;
    }

    public static function reset_rest_validation($response, $handler, $request)
    {
        self::$rest_validated = false;
        return $response;
    }

    private static function missing_fields($data, $thumb, $terms)
    {
        $missing = [];
        foreach (['post_title' => 'заголовок', 'post_excerpt' => 'краткий анонс', 'post_content' => 'основной текст'] as $key => $label) {
            if (trim(wp_strip_all_tags(strip_shortcodes((string) ($data[$key] ?? '')))) === '') { $missing[] = $label; }
        }
        if (!$thumb || !wp_attachment_is_image($thumb)) { $missing[] = 'главное изображение'; }
        if (empty($terms)) { $missing[] = 'тип новости'; }
        return $missing;
    }

    public static function guard_transition($new, $old, $post)
    {
        if (self::$guarding || self::$rest_validated || $post->post_type !== LV_News_Suite::POST_TYPE || !in_array($new, ['publish', 'future'], true) || $old === 'publish') { return; }
        $terms = wp_get_object_terms($post->ID, LV_News_Suite::TAXONOMY, ['fields' => 'ids']);
        $missing = self::missing_fields((array) $post, get_post_thumbnail_id($post->ID), is_wp_error($terms) ? [] : $terms);
        if (!$missing) { return; }
        self::$guarding = true;
        try {
            wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
            // Remaining core hooks must also see the corrected status.
            $post->post_status = 'draft';
            update_post_meta($post->ID, '_lv_news_publication_error', implode(', ', $missing));
            self::remember_notice($missing);
        } finally { self::$guarding = false; }
    }

    public static function guard_scheduled_publication($post_id)
    {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== LV_News_Suite::POST_TYPE || $post->post_status !== 'future') { return; }
        $terms = wp_get_object_terms($post_id, LV_News_Suite::TAXONOMY, ['fields' => 'ids']);
        $missing = self::missing_fields((array) $post, get_post_thumbnail_id($post_id), is_wp_error($terms) ? [] : $terms);
        if ($missing) {
            // Runs before WordPress's scheduled publisher: no publish notifications are fired.
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            update_post_meta($post_id, '_lv_news_publication_error', implode(', ', $missing));
        }
    }

    private static function remember_notice($missing)
    {
        if (get_current_user_id()) { set_transient('lv_news_publication_notice_' . get_current_user_id(), implode(', ', $missing), 120); }
    }

    public static function publication_notice()
    {
        $seo_key = 'lv_news_seo_restore_notice_' . get_current_user_id();
        if (get_transient($seo_key)) {
            delete_transient($seo_key);
            echo '<div class="notice notice-warning"><p>Текст ревизии восстановлен. SEO сейчас сохраняется в другом запросе; его восстановление нужно повторить.</p></div>';
        }
        $key = 'lv_news_publication_notice_' . get_current_user_id();
        $missing = get_transient($key);
        if (!$missing) { return; }
        delete_transient($key);
        echo '<div class="notice notice-warning"><p>' . esc_html('Новость сохранена как черновик. Перед публикацией заполните: ' . $missing . '.') . '</p></div>';
    }

    /**
     * Внутри новости разрешены только H2 и H3. H1 принадлежит заголовку
     * страницы, а H4-H6 создают лишнюю глубину для обычной новостной заметки.
     * UI 1.2.4 больше не предлагает core/heading через slash inserter, однако
     * серверная нормализация остаётся страховкой для старых записей, REST,
     * Classic/freeform-контента и вставленного HTML.
     */
    public static function normalize_content_headings($data, $postarr)
    {
        if (empty($data['post_type']) || $data['post_type'] !== LV_News_Suite::POST_TYPE || !isset($data['post_content'])) {
            return $data;
        }

        $content = wp_unslash((string) $data['post_content']);
        if ($content === '' || !preg_match('/(?:<h[1-6](?:\s|>)|"level"\s*:\s*(?:1|4|5|6))/i', $content)) {
            return $data;
        }

        if (function_exists('parse_blocks') && function_exists('serialize_blocks')) {
            $blocks = parse_blocks($content);
            $blocks = self::normalize_heading_blocks($blocks);
            $content = serialize_blocks($blocks);
        }

        // Покрывает Classic/freeform HTML и любые нестандартные блоки.
        $content = preg_replace_callback('/<h([1-6])(\s[^>]*)?>/i', static function ($m) {
            $level = (int) $m[1];
            $target = $level <= 2 ? 2 : 3;
            return '<h' . $target . ($m[2] ?? '') . '>';
        }, $content);
        $content = preg_replace_callback('/<\/h([1-6])\s*>/i', static function ($m) {
            $level = (int) $m[1];
            $target = $level <= 2 ? 2 : 3;
            return '</h' . $target . '>';
        }, $content);

        $data['post_content'] = wp_slash($content);
        return $data;
    }

    private static function normalize_heading_blocks($blocks)
    {
        foreach ($blocks as &$block) {
            if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = self::normalize_heading_blocks($block['innerBlocks']);
            }

            if (($block['blockName'] ?? '') === 'core/heading') {
                $level = isset($block['attrs']['level']) ? (int) $block['attrs']['level'] : 2;
                $target = $level <= 2 ? 2 : 3;
                if ($level !== 2 && $level !== 3) {
                    if (!isset($block['attrs']) || !is_array($block['attrs'])) {
                        $block['attrs'] = [];
                    }
                    $block['attrs']['level'] = $target;
                }
            }

            if (isset($block['innerHTML']) && is_string($block['innerHTML'])) {
                $block['innerHTML'] = self::normalize_heading_html($block['innerHTML']);
            }

            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                foreach ($block['innerContent'] as &$piece) {
                    if (is_string($piece)) {
                        $piece = self::normalize_heading_html($piece);
                    }
                }
                unset($piece);
            }
        }
        unset($block);

        return $blocks;
    }

    private static function normalize_heading_html($html)
    {
        $html = preg_replace_callback('/<h([1-6])(\s[^>]*)?>/i', static function ($m) {
            $level = (int) $m[1];
            $target = $level <= 2 ? 2 : 3;
            return '<h' . $target . ($m[2] ?? '') . '>';
        }, (string) $html);

        return preg_replace_callback('/<\/h([1-6])\s*>/i', static function ($m) {
            $level = (int) $m[1];
            $target = $level <= 2 ? 2 : 3;
            return '</h' . $target . '>';
        }, $html);
    }

    public static function register_patterns()
    {
        if (!function_exists('register_block_pattern')) {
            return;
        }

        if (function_exists('register_block_pattern_category')) {
            register_block_pattern_category('lv-news', ['label' => 'Люди и Верблюды — новости']);
        }

        $patterns = [
            'standard' => [
                'title' => 'Обычная новость',
                'content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Что произошло</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Почему это важно</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Что будет дальше</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
            ],
            'ward' => [
                'title' => 'История подопечного',
                'content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Что случилось</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Как мы помогаем</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Как можно помочь</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
            ],
            'event' => [
                'title' => 'Событие',
                'content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Как всё прошло</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Главный результат</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Благодарим</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
            ],
            'help' => [
                'title' => 'Сбор или просьба о помощи',
                'content' => '<!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Какая помощь нужна</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Что уже сделано</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph --><!-- wp:lv-news/heading-2 --><h2 class="lv-news-heading lv-news-heading--2">Как помочь</h2><!-- /wp:lv-news/heading-2 --><!-- wp:paragraph --><p></p><!-- /wp:paragraph -->',
            ],
        ];

        foreach ($patterns as $slug => $pattern) {
            register_block_pattern('lv-news/' . $slug, [
                'title' => $pattern['title'],
                'categories' => ['lv-news'],
                'content' => $pattern['content'],
                'inserter' => true,
            ]);
        }
    }
}
