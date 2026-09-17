<?php
if (!defined('ABSPATH')) {
    exit;
}

final class LV_News_Public
{
    private static $rendering_single = false;

    public static function boot()
    {
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets'], 30);
        add_filter('body_class', [__CLASS__, 'body_classes']);
        add_shortcode('lv_news_archive', [__CLASS__, 'archive_shortcode']);
        add_shortcode('lv_home_news', [__CLASS__, 'home_shortcode']);
        add_filter('the_content', [__CLASS__, 'single_content'], 40);
        add_action('template_redirect', [__CLASS__, 'route_guards'], 1);
    }

    public static function enqueue_assets()
    {
        $needed = is_singular(LV_News_Suite::POST_TYPE) || LV_News_Suite::is_archive_context();

        if (!$needed) {
            $post = get_queried_object();
            if ($post instanceof WP_Post) {
                $needed = has_shortcode($post->post_content, 'lv_news_archive') || has_shortcode($post->post_content, 'lv_home_news');
            }
        }

        if (!$needed && is_front_page()) {
            $needed = true;
        }

        if (!$needed) {
            return;
        }

        wp_enqueue_style(
            'lv-news-suite-public',
            LV_NEWS_SUITE_URL . 'assets/public.css',
            [],
            LV_NEWS_SUITE_VERSION
        );
    }

    public static function body_classes($classes)
    {
        if (LV_News_Suite::is_archive_context()) {
            $classes[] = 'lv-news-page';
        }

        if (is_singular(LV_News_Suite::POST_TYPE)) {
            $classes[] = 'lv-single-news-page';
        }

        return $classes;
    }

    public static function route_guards()
    {
        if (!LV_News_Suite::is_archive_context()) {
            return;
        }

        $page = max(1, absint(get_query_var('lv_news_page')));
        $path = LV_News_Suite::request_path();

        $archive_page_id = LV_News_Suite::get_archive_page_id();
        if (
            $archive_page_id > 0 &&
            is_page($archive_page_id) &&
            !preg_match('#^/news(?:/page/[0-9]+)?/?$#', $path)
        ) {
            wp_safe_redirect(LV_News_Suite::archive_url($page), 301);
            exit;
        }

        if ($page === 1 && preg_match('#^/news/page/1/?$#', $path)) {
            wp_safe_redirect(LV_News_Suite::archive_url(), 301);
            exit;
        }

        $pagination = LV_News_Suite::archive_pagination_data();
        if ($page > (int) $pagination['total_pages']) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
        }
    }

    public static function archive_shortcode()
    {
        $page = max(1, absint(get_query_var('lv_news_page')));
        $pagination = LV_News_Suite::archive_pagination_data();
        $featured_id = (int) $pagination['featured_id'];

        if ((int) $pagination['total'] < 1) {
            $html = '<div id="lvNewsArchive"><div class="na-main"><div class="na-empty"><h2 class="na-empty__title">Новостей пока нет</h2><p class="na-empty__text">Скоро здесь появятся новости фонда и истории наших подопечных.</p></div>';
            $html .= LV_News_Counters::render();
            $html .= '</div></div>';
            return self::compact_shortcode_html($html);
        }

        $normal_query = self::archive_query($page, $featured_id);

        ob_start();
        ?>
        <div id="lvNewsArchive" data-lv-news-version="<?php echo esc_attr(LV_NEWS_SUITE_VERSION); ?>">
            <div class="na-main">
                <?php if ($page === 1 && $featured_id) : ?>
                    <?php echo self::render_archive_featured($featured_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>

                <?php if ($normal_query->have_posts()) : ?>
                    <h2 class="na-section-title"><?php echo esc_html($page === 1 ? 'Свежие новости' : 'Новости'); ?></h2>
                    <div class="na-grid" role="list">
                        <?php
                        while ($normal_query->have_posts()) {
                            $normal_query->the_post();
                            echo self::render_archive_card(get_the_ID()); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                        }
                        wp_reset_postdata();
                        ?>
                    </div>
                <?php endif; ?>

                <?php echo self::render_pagination($page, (int) $pagination['total_pages']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php echo LV_News_Counters::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted admin-managed snippets. ?>
            </div>
        </div>
        <?php
        return self::compact_shortcode_html((string) ob_get_clean());
    }

    private static function archive_query($page, $featured_id)
    {
        if ($page === 1) {
            $per_page = $featured_id ? 9 : 10;
            $offset = 0;
        } else {
            $per_page = 12;
            $first_grid = $featured_id ? 9 : 10;
            $offset = $first_grid + (($page - 2) * 12);
        }

        return new WP_Query([
            'post_type' => LV_News_Suite::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'offset' => $offset,
            'orderby' => 'date',
            'order' => 'DESC',
            'post__not_in' => $featured_id ? [$featured_id] : [],
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        ]);
    }

    private static function render_archive_featured($post_id)
    {
        $title = self::title($post_id);
        $url = get_permalink($post_id);
        $excerpt = self::excerpt($post_id, 42);

        ob_start();
        ?>
        <article class="na-featured">
            <a class="na-featured__media" href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true">
                <?php echo self::image_html($post_id, 'lv-news-featured', 'na-featured__img', true, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <div class="na-featured__body">
                <div class="na-featured__meta">
                    <span class="na-featured__kicker">Главная новость</span>
                    <time class="na-featured__date" datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>"><?php echo esc_html(get_the_date('j F Y', $post_id)); ?></time>
                </div>
                <h2 class="na-featured__title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a></h2>
                <?php if ($excerpt !== '') : ?><p class="na-featured__excerpt"><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                <a class="na-read" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr('Читать новость: ' . $title); ?>">
                    <span class="na-read__arrows" aria-hidden="true"><svg viewBox="0 0 34 16" focusable="false"><path d="M3 3l6 5-6 5"></path><path d="M14 3l6 5-6 5"></path></svg></span> Читать
                </a>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_archive_card($post_id)
    {
        $title = self::title($post_id);
        $url = get_permalink($post_id);
        $excerpt = self::excerpt($post_id, 26);

        ob_start();
        ?>
        <article class="na-card" role="listitem">
            <a class="na-card__media" href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true">
                <?php echo self::image_html($post_id, 'lv-news-card', 'na-card__img', false, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <div class="na-card__body">
                <time class="na-card__date" datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>"><?php echo esc_html(get_the_date('j F Y', $post_id)); ?></time>
                <h3 class="na-card__title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a></h3>
                <?php if ($excerpt !== '') : ?><p class="na-card__excerpt"><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                <a class="na-read" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr('Читать новость: ' . $title); ?>">
                    <span class="na-read__arrows" aria-hidden="true"><svg viewBox="0 0 34 16" focusable="false"><path d="M3 3l6 5-6 5"></path><path d="M14 3l6 5-6 5"></path></svg></span> Читать
                </a>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_pagination($current, $total)
    {
        if ($total <= 1) {
            return '';
        }

        $items = [];
        if ($total <= 7) {
            $items = range(1, $total);
        } else {
            $items = array_values(array_unique(array_filter([
                1,
                2,
                $current - 1,
                $current,
                $current + 1,
                $total - 1,
                $total,
            ], static function ($page) use ($total) {
                return $page >= 1 && $page <= $total;
            })));
            sort($items, SORT_NUMERIC);
        }

        ob_start();
        ?>
        <nav class="na-pagination" aria-label="Страницы новостей"><ul class="na-pagination__list">
            <?php if ($current > 1) : ?>
                <li class="na-pagination__item"><a class="na-pagination__link na-pagination__link--arrow" href="<?php echo esc_url(LV_News_Suite::archive_url($current - 1)); ?>" aria-label="Предыдущая страница">←</a></li>
            <?php endif; ?>
            <?php $previous = 0; foreach ($items as $page) : ?>
                <?php if ($previous && $page - $previous > 1) : ?><li class="na-pagination__item"><span class="na-pagination__dots" aria-hidden="true">…</span></li><?php endif; ?>
                <li class="na-pagination__item">
                    <?php if ($page === $current) : ?>
                        <span class="na-pagination__current" aria-current="page"><?php echo esc_html((string) $page); ?></span>
                    <?php else : ?>
                        <a class="na-pagination__link" href="<?php echo esc_url(LV_News_Suite::archive_url($page)); ?>"><?php echo esc_html((string) $page); ?></a>
                    <?php endif; ?>
                </li>
                <?php $previous = $page; ?>
            <?php endforeach; ?>
            <?php if ($current < $total) : ?>
                <li class="na-pagination__item"><a class="na-pagination__link na-pagination__link--arrow" href="<?php echo esc_url(LV_News_Suite::archive_url($current + 1)); ?>" aria-label="Следующая страница">→</a></li>
            <?php endif; ?>
        </ul></nav>
        <?php
        return (string) ob_get_clean();
    }

    public static function home_shortcode()
    {
        $featured_id = LV_News_Suite::get_featured_id();
        if (!$featured_id) {
            return '';
        }

        $others = get_posts([
            'post_type' => LV_News_Suite::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'post__not_in' => [$featured_id],
            'no_found_rows' => true,
        ]);

        $posts = array_merge([get_post($featured_id)], $others);
        $posts = array_filter($posts, static function ($post) {
            return $post instanceof WP_Post;
        });

        ob_start();
        ?>
        <div class="lv-home-news" data-lv-news-version="<?php echo esc_attr(LV_NEWS_SUITE_VERSION); ?>">
            <section class="hn-sec" aria-label="Свежие новости">
      <h2 class="hn-title" style="margin:0 0 clamp(28px,3.2vw,40px);color:var(--teal);font-family:var(--lv-news-serif);font-size:clamp(30px,3.6vw,46px);line-height:1.15;font-weight:400;text-align:center">Последние новости</h2>
                <div class="hn-grid<?php echo count($posts) === 1 ? ' hn-grid--single' : ''; ?>">
                    <?php foreach ($posts as $index => $post) : ?>
                        <?php echo self::render_home_card($post->ID, $index === 0); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                </div>
                <div class="hn-foot">
                    <span class="hn-tagwrap">
                        <span class="hn-tag-echo" aria-hidden="true"></span>
                        <a class="hn-tag" href="<?php echo esc_url(LV_News_Suite::archive_url()); ?>">Все новости</a>
                    </span>
                </div>
            </section>
        </div>
        <?php
        return self::compact_shortcode_html((string) ob_get_clean());
    }

    private static function render_home_card($post_id, $featured)
    {
        $title = self::title($post_id);
        $url = get_permalink($post_id);
        $excerpt = self::excerpt($post_id, $featured ? 52 : 28);
        $class = $featured ? 'hn-card hn-card--feature' : 'hn-card hn-card--standard';

        ob_start();
        ?>
        <article class="<?php echo esc_attr($class); ?>">
            <a class="hn-card__media" href="<?php echo esc_url($url); ?>" tabindex="-1" aria-hidden="true">
                <?php echo self::image_html($post_id, $featured ? 'lv-news-featured' : 'lv-news-card', 'hn-card__img', false, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </a>
            <div class="hn-card__body">
                <time class="hn-card__date" datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>"><?php echo esc_html(get_the_date('j F Y', $post_id)); ?></time>
                <h3 class="hn-card__title"><a href="<?php echo esc_url($url); ?>"><?php echo esc_html($title); ?></a></h3>
                <?php if ($excerpt !== '') : ?><p class="hn-card__excerpt"><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                <a class="hn-card__link" href="<?php echo esc_url($url); ?>" aria-label="<?php echo esc_attr('Читать новость: ' . $title); ?>"><span class="hn-card__arrows" aria-hidden="true"><svg viewBox="0 0 34 16" focusable="false"><path d="M3 3l6 5-6 5"></path><path d="M14 3l6 5-6 5"></path></svg></span>Читать</a>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Убирает форматирующие переводы строк между HTML-тегами shortcode.
     * Elementor Text Editor в некоторых конфигурациях прогоняет уже готовый
     * результат shortcode через wpautop и превращает эти переводы в <br> и
     * лишние <p>, нарушая структуру карточек.
     */
    private static function compact_shortcode_html($html)
    {
        $html = trim((string) $html);
        $compacted = preg_replace('/>\s+</u', '><', $html);
        return is_string($compacted) ? $compacted : $html;
    }

    public static function single_content($original_content)
    {
        if (
            self::$rendering_single ||
            !is_singular(LV_News_Suite::POST_TYPE) ||
            !in_the_loop() ||
            !is_main_query()
        ) {
            return $original_content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $original_content;
        }

        self::$rendering_single = true;

        $title = self::title($post_id);
        $excerpt = trim((string) get_post_field('post_excerpt', $post_id));
        $author_id = (int) get_post_field('post_author', $post_id);
        $author = $author_id ? get_the_author_meta('display_name', $author_id) : '';
        if ($author === '') {
            $author = get_bloginfo('name');
        }
        $minutes = self::reading_time($original_content);
        $term = self::type_label($post_id);
        $has_image = has_post_thumbnail($post_id);

        ob_start();
        ?>
        <div id="lvSingleNews" data-lv-news-version="<?php echo esc_attr(LV_NEWS_SUITE_VERSION); ?>">
            <header class="sn-hero<?php echo $has_image ? ' has-image' : ''; ?>">
                <div class="sn-hero__inner">
                    <div class="sn-hero__content">
                        <a class="sn-back" href="<?php echo esc_url(LV_News_Suite::archive_url()); ?>">← Все новости</a>
                        <span class="sn-badge"><?php echo esc_html($term); ?></span>
                        <h1 class="sn-title"><?php echo esc_html($title); ?></h1>
                        <div class="sn-meta">
                            <span class="sn-author"><?php echo esc_html($author); ?></span>
                            <span class="sn-meta__separator" aria-hidden="true">•</span>
                            <time class="sn-date sn-meta__item" datetime="<?php echo esc_attr(get_the_date('c', $post_id)); ?>"><?php echo esc_html(get_the_date('j F Y', $post_id)); ?></time>
                            <span class="sn-meta__separator" aria-hidden="true">•</span>
                            <span class="sn-meta__item">Чтение: ~<?php echo esc_html((string) $minutes); ?> мин.</span>
                        </div>
                        <?php if ($excerpt !== '') : ?><p class="sn-lead"><?php echo esc_html($excerpt); ?></p><?php endif; ?>
                    </div>
                </div>
                <?php if ($has_image) : ?>
                    <div class="sn-hero__media">
                        <picture class="sn-hero__picture">
                            <?php echo self::image_html($post_id, 'lv-news-single', 'sn-hero__img', true, null); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </picture>
                    </div>
                <?php endif; ?>
            </header>

            <div class="sn-main">
                <div class="sn-layout">
                    <article class="sn-article">
                        <div class="sn-content"><?php echo $original_content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
                    </article>
                    <aside class="sn-side" aria-label="Другие новости">
                        <h2 class="sn-side__title">Другие новости</h2>
                        <?php echo self::related_news($post_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <a class="sn-side__all" href="<?php echo esc_url(LV_News_Suite::archive_url()); ?>">Все новости →</a>
                    </aside>
                </div>
                <?php echo LV_News_Counters::render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted admin-managed snippets. ?>
            </div>
        </div>
        <?php
        $html = (string) ob_get_clean();
        self::$rendering_single = false;
        return $html;
    }

    private static function related_news($post_id)
    {
        $posts = get_posts([
            'post_type' => LV_News_Suite::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => 3,
            'orderby' => 'date',
            'order' => 'DESC',
            'post__not_in' => [$post_id],
            'no_found_rows' => true,
        ]);

        if (!$posts) {
            return '<p class="sn-side__empty">Других публикаций пока нет.</p>';
        }

        ob_start();
        foreach ($posts as $post) :
            $has_image = has_post_thumbnail($post->ID);
            ?>
            <a class="sn-mini<?php echo $has_image ? ' has-image' : ''; ?>" href="<?php echo esc_url(get_permalink($post)); ?>">
                <?php if ($has_image) : ?>
                    <span class="sn-mini__media" aria-hidden="true"><?php echo self::image_html($post->ID, 'lv-news-mini', 'sn-mini__img', false, ''); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
                <?php endif; ?>
                <span class="sn-mini__body"><time class="sn-mini__date" datetime="<?php echo esc_attr(get_the_date('c', $post)); ?>"><?php echo esc_html(get_the_date('d.m.Y', $post)); ?></time><span class="sn-mini__title"><?php echo esc_html(self::title($post->ID)); ?></span></span>
            </a>
            <?php
        endforeach;
        return (string) ob_get_clean();
    }

    public static function excerpt($post_id, $words = 26)
    {
        $text = trim((string) get_post_field('post_excerpt', $post_id));
        if ($text === '') {
            $text = (string) get_post_field('post_content', $post_id);
        }

        $text = wp_strip_all_tags(strip_shortcodes($text), true);
        $text = preg_replace('/\s+/u', ' ', $text);
        return wp_trim_words(trim((string) $text), absint($words), '…');
    }

    public static function reading_time($content)
    {
        $text = trim(wp_strip_all_tags(strip_shortcodes((string) $content)));
        if ($text === '') {
            return 1;
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        return max(1, (int) ceil(count($words) / 180));
    }

    private static function title($post_id)
    {
        $title = trim((string) get_the_title($post_id));
        return $title !== '' ? $title : 'Новость фонда';
    }

    private static function type_label($post_id)
    {
        $terms = wp_get_post_terms($post_id, LV_News_Suite::TAXONOMY);
        if (!is_wp_error($terms) && !empty($terms)) {
            return (string) $terms[0]->name;
        }
        return 'Новости фонда';
    }

    public static function image_html($post_id, $size, $class, $eager = false, $alt = null)
    {
        $thumb_id = get_post_thumbnail_id($post_id);
        if (!$thumb_id) {
            if (strpos($class, 'hn-') !== false) {
                return '<span class="hn-placeholder" aria-hidden="true"><span class="hn-placeholder__mark">Л&amp;В</span><span class="hn-placeholder__text">Люди и Верблюды</span></span>';
            }

            if (strpos($class, 'na-') !== false) {
                return '<span class="na-placeholder" aria-hidden="true"><span class="na-placeholder__mark">Л&amp;В</span><span class="na-placeholder__text">Люди и Верблюды</span></span>';
            }

            return '<span class="lv-news-image-placeholder" aria-hidden="true"><span>Л&amp;В</span></span>';
        }

        $fallback_sizes = [
            'lv-news-featured' => 'large',
            'lv-news-card' => 'medium_large',
            'lv-news-single' => 'full',
            'lv-news-mini' => 'thumbnail',
        ];

        if (isset($fallback_sizes[$size]) && $size !== 'full') {
            $metadata = wp_get_attachment_metadata($thumb_id);
            $has_generated_size = is_array($metadata)
                && !empty($metadata['sizes'])
                && isset($metadata['sizes'][$size]);

            if (!$has_generated_size) {
                $size = $fallback_sizes[$size];
            }
        }

        $focus = LV_News_Suite::get_featured_focus($post_id);
        $style = sprintf(
            '--lv-news-focus-x:%d%%;--lv-news-focus-y:%d%%;--lv-news-focus-scale:%s;object-position:%d%% %d%%!important;transform:scale(%s)!important;transform-origin:%d%% %d%%!important;',
            $focus['x'],
            $focus['y'],
            number_format($focus['zoom'] / 100, 2, '.', ''),
            $focus['x'],
            $focus['y'],
            number_format($focus['zoom'] / 100, 2, '.', ''),
            $focus['x'],
            $focus['y']
        );

        if ($alt === null) {
            $alt = (string) get_post_meta($thumb_id, '_wp_attachment_image_alt', true);
        }

        return wp_get_attachment_image($thumb_id, $size, false, [
            'class' => $class,
            'alt' => $alt,
            'loading' => $eager ? 'eager' : 'lazy',
            'fetchpriority' => $eager ? 'high' : 'auto',
            'decoding' => 'async',
            'style' => $style,
        ]);
    }
}
