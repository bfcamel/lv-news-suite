<?php
/**
 * Plugin Name: Люди и Верблюды — Новости
 * Description: Новости фонда: WYSIWYG-редактор, архив /news/, главная новость, SEO, предпросмотр и редакционный workflow.
 * Version: 1.5.2
 * Requires at least: 7.1
 * Requires PHP: 7.4
 * Author: БФ «Люди и Верблюды»
 * Text Domain: lv-news-suite
 */

if (!defined('ABSPATH')) {
    exit;
}

define('LV_NEWS_SUITE_VERSION', '1.5.2');
define('LV_NEWS_SUITE_DB_VERSION', 6);
define('LV_NEWS_SUITE_ROUTES_VERSION', 1);
define('LV_NEWS_SUITE_FILE', __FILE__);
define('LV_NEWS_SUITE_DIR', plugin_dir_path(__FILE__));
define('LV_NEWS_SUITE_URL', plugin_dir_url(__FILE__));

require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-lock.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-migrations.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-rank-math.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-suite.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-editor.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-public.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-seo.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-admin.php';
require_once LV_NEWS_SUITE_DIR . 'includes/class-lv-news-counters.php';

register_activation_hook(__FILE__, ['LV_News_Suite', 'activate']);
register_deactivation_hook(__FILE__, ['LV_News_Suite', 'deactivate']);

LV_News_Suite::boot();
