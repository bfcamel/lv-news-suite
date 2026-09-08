<?php
if (!defined('ABSPATH')) {
    exit;
}

final class LV_News_SEO
{
    public static function boot()
    {
        add_filter('get_canonical_url', [__CLASS__, 'core_canonical'], 20, 2);
        add_filter('rank_math/frontend/canonical', [__CLASS__, 'rank_math_canonical'], 20);
        add_filter('rank_math/frontend/title', [__CLASS__, 'rank_math_title'], 20);
        add_filter('rank_math/frontend/description', [__CLASS__, 'rank_math_description'], 20);
        add_filter('rank_math/json_ld', [__CLASS__, 'rank_math_json_ld'], 30, 2);
        add_filter('document_title_parts', [__CLASS__, 'document_title_parts'], 20);
        add_action('wp_head', [__CLASS__, 'fallback_meta'], 2);
        add_action('wp_head', [__CLASS__, 'pagination_rel_links'], 3);
    }

    private static function has_rank_math()
    {
        return defined('RANK_MATH_VERSION') || class_exists('RankMath') || function_exists('rank_math');
    }

    public static function core_canonical($canonical, $post)
    {
        if ($post instanceof WP_Post && $post->post_type === LV_News_Suite::POST_TYPE) {
            if (self::has_rank_math()) {
                return $canonical;
            }
            $custom = trim((string) get_post_meta($post->ID, LV_News_Suite::META_SEO_CANONICAL, true));
            return $custom !== '' ? $custom : get_permalink($post);
        }
        return $canonical;
    }

    public static function rank_math_canonical($canonical)
    {
        if (LV_News_Suite::is_archive_context()) {
            $page = max(1, absint(get_query_var('lv_news_page')));
            return LV_News_Suite::archive_url($page);
        }

        if (is_singular(LV_News_Suite::POST_TYPE)) {
            $post_id = get_queried_object_id();
            if (trim((string) $canonical) !== '') {
                return $canonical;
            }

            $rank_math = trim((string) get_post_meta($post_id, 'rank_math_canonical_url', true));
            if ($rank_math !== '') {
                return $rank_math;
            }

            $custom = trim((string) get_post_meta($post_id, LV_News_Suite::META_SEO_CANONICAL, true));
            return $custom !== '' ? $custom : get_permalink($post_id);
        }

        return $canonical;
    }

    public static function rank_math_title($title)
    {
        if (LV_News_Suite::is_archive_context()) {
            $page = max(1, absint(get_query_var('lv_news_page')));
            return $page > 1
                ? sprintf('Новости фонда — страница %d | %s', $page, get_bloginfo('name'))
                : 'Новости фонда | ' . get_bloginfo('name');
        }

        if (is_singular(LV_News_Suite::POST_TYPE)) {
            $post_id = get_queried_object_id();
            if (trim((string) $title) !== '') {
                return $title;
            }

            $rank_math = trim((string) get_post_meta($post_id, 'rank_math_title', true));
            if ($rank_math !== '') {
                return $rank_math;
            }

            $custom = trim((string) get_post_meta($post_id, LV_News_Suite::META_SEO_TITLE, true));
            return $custom !== '' ? $custom : get_the_title($post_id) . ' | ' . get_bloginfo('name');
        }

        return $title;
    }

    public static function rank_math_description($description)
    {
        if (LV_News_Suite::is_archive_context()) {
            return 'Новости фонда «Люди и Верблюды», истории подопечных, события и результаты нашей работы.';
        }

        if (is_singular(LV_News_Suite::POST_TYPE)) {
            $post_id = get_queried_object_id();
            if (trim((string) $description) !== '') {
                return $description;
            }

            $rank_math = trim((string) get_post_meta($post_id, 'rank_math_description', true));
            if ($rank_math !== '') {
                return $rank_math;
            }

            $custom = trim((string) get_post_meta($post_id, LV_News_Suite::META_SEO_DESCRIPTION, true));
            return $custom !== '' ? $custom : self::description_for_post($post_id);
        }

        return $description;
    }


    public static function pagination_rel_links()
    {
        if (!LV_News_Suite::is_archive_context()) {
            return;
        }

        $page = max(1, absint(get_query_var('lv_news_page')));
        $pagination = LV_News_Suite::archive_pagination_data();
        $total = max(1, (int) $pagination['total_pages']);

        if ($page > 1 && $page <= $total) {
            echo '<link rel="prev" href="' . esc_url(LV_News_Suite::archive_url($page - 1)) . '">' . "\n";
        }

        if ($page < $total) {
            echo '<link rel="next" href="' . esc_url(LV_News_Suite::archive_url($page + 1)) . '">' . "\n";
        }
    }

    public static function document_title_parts($parts)
    {
        if (!self::has_rank_math() && is_singular(LV_News_Suite::POST_TYPE)) {
            $custom = trim((string) get_post_meta(get_queried_object_id(), LV_News_Suite::META_SEO_TITLE, true));
            if ($custom !== '') {
                return ['title' => $custom];
            }
        }
        if (!self::has_rank_math() && LV_News_Suite::is_archive_context()) {
            $page = max(1, absint(get_query_var('lv_news_page')));
            $parts['title'] = $page > 1 ? 'Новости — страница ' . $page : 'Новости';
        }
        return $parts;
    }

    public static function rank_math_json_ld($data, $jsonld)
    {
        if (is_singular(LV_News_Suite::POST_TYPE)) {
            $post_id = get_queried_object_id();
            $article_key = null;

            foreach ((array) $data as $key => $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $type = isset($entity['@type']) ? $entity['@type'] : '';
                $types = is_array($type) ? $type : [$type];
                if (array_intersect($types, ['Article', 'BlogPosting', 'NewsArticle'])) {
                    $article_key = $key;
                    break;
                }
            }

            $article = self::article_schema($post_id);
            if ($article_key !== null) {
                $existing = is_array($data[$article_key]) ? $data[$article_key] : [];
                $merged = array_merge($article, $existing);
                $types = isset($existing['@type'])
                    ? (is_array($existing['@type']) ? $existing['@type'] : [$existing['@type']])
                    : [];
                if (!in_array('NewsArticle', $types, true)) {
                    array_unshift($types, 'NewsArticle');
                }
                $merged['@type'] = count($types) === 1 ? $types[0] : array_values(array_unique($types));
                $data[$article_key] = $merged;
            } else {
                $data['lv-news-article'] = $article;
            }
        } elseif (LV_News_Suite::is_archive_context()) {
            $has_collection = false;
            foreach ((array) $data as $entity) {
                if (!is_array($entity)) {
                    continue;
                }
                $type = isset($entity['@type']) ? $entity['@type'] : '';
                if ($type === 'CollectionPage' || (is_array($type) && in_array('CollectionPage', $type, true))) {
                    $has_collection = true;
                    break;
                }
            }

            if (!$has_collection) {
                $page = max(1, absint(get_query_var('lv_news_page')));
                $data['lv-news-collection'] = [
                    '@type' => 'CollectionPage',
                    '@id' => LV_News_Suite::archive_url($page) . '#collectionpage',
                    'url' => LV_News_Suite::archive_url($page),
                    'name' => $page > 1 ? 'Новости — страница ' . $page : 'Новости',
                    'description' => 'Новости фонда «Люди и Верблюды», истории подопечных и события из жизни фонда.',
                ];
            }
        }

        return $data;
    }

    private static function article_schema($post_id)
    {
        $author_id = (int) get_post_field('post_author', $post_id);
        $author_name = $author_id ? get_the_author_meta('display_name', $author_id) : '';
        if ($author_name === '') {
            $author_name = get_bloginfo('name');
        }

        $schema = [
            '@type' => 'NewsArticle',
            '@id' => get_permalink($post_id) . '#newsarticle',
            'mainEntityOfPage' => ['@id' => get_permalink($post_id)],
            'headline' => wp_strip_all_tags(get_the_title($post_id)),
            'description' => self::description_for_post($post_id),
            'datePublished' => get_the_date('c', $post_id),
            'dateModified' => get_the_modified_date('c', $post_id),
            'author' => [
                '@type' => 'Person',
                'name' => $author_name,
            ],
            'publisher' => [
                '@type' => 'Organization',
                'name' => get_bloginfo('name'),
                'url' => home_url('/'),
            ],
        ];

        $image = get_the_post_thumbnail_url($post_id, 'full');
        if ($image) {
            $schema['image'] = [$image];
        }

        return $schema;
    }

    private static function description_for_post($post_id)
    {
        if (self::has_rank_math()) {
            $rank_math = trim((string) get_post_meta($post_id, 'rank_math_description', true));
            if ($rank_math !== '') {
                return $rank_math;
            }
        }

        $custom = trim((string) get_post_meta($post_id, LV_News_Suite::META_SEO_DESCRIPTION, true));
        if ($custom !== '') {
            return $custom;
        }

        $excerpt = trim((string) get_post_field('post_excerpt', $post_id));
        if ($excerpt !== '') {
            return wp_html_excerpt(wp_strip_all_tags($excerpt), 220, '…');
        }

        $content = wp_strip_all_tags(strip_shortcodes((string) get_post_field('post_content', $post_id)), true);
        return wp_html_excerpt(trim(preg_replace('/\s+/u', ' ', $content)), 220, '…');
    }

    public static function fallback_meta()
    {
        if (self::has_rank_math()) {
            return;
        }

        if (!LV_News_Suite::is_archive_context() && !is_singular(LV_News_Suite::POST_TYPE)) {
            return;
        }

        remove_action('wp_head', 'rel_canonical');

        if (LV_News_Suite::is_archive_context()) {
            $page = max(1, absint(get_query_var('lv_news_page')));
            $canonical = LV_News_Suite::archive_url($page);
            $title = $page > 1 ? 'Новости — страница ' . $page : 'Новости';
            $description = 'Новости фонда «Люди и Верблюды», истории подопечных, события и результаты нашей работы.';
            $schema = [
                '@context' => 'https://schema.org',
                '@type' => 'CollectionPage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
            ];
            $image = '';
            $og_type = 'website';
        } else {
            $post_id = get_queried_object_id();
            $canonical = self::rank_math_canonical('');
            $title = trim((string) get_post_meta($post_id, LV_News_Suite::META_OG_TITLE, true));
            if ($title === '') {
                $title = get_the_title($post_id);
            }
            $description = trim((string) get_post_meta($post_id, LV_News_Suite::META_OG_DESCRIPTION, true));
            if ($description === '') {
                $description = self::description_for_post($post_id);
            }
            $schema = array_merge(['@context' => 'https://schema.org'], self::article_schema($post_id));
            $image = get_the_post_thumbnail_url($post_id, 'full');
            $og_type = 'article';
        }

        $seo_description = is_singular(LV_News_Suite::POST_TYPE)
            ? self::description_for_post(get_queried_object_id()) : $description;

        echo "\n" . '<link rel="canonical" href="' . esc_url($canonical) . '">' . "\n";
        echo '<meta name="description" content="' . esc_attr($seo_description) . '">' . "\n";
        echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
        echo '<meta property="og:url" content="' . esc_url($canonical) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($description) . '">' . "\n";
        if ($image) {
            echo '<meta property="og:image" content="' . esc_url($image) . '">' . "\n";
        }
        echo '<script type="application/ld+json">' . wp_json_encode(
            $schema,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) . '</script>' . "\n";
    }
}
