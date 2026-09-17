<?php
// Run only against a disposable WordPress install: WP_LOAD_PATH=/path/wp-load.php php tests/counters.php
require getenv('WP_LOAD_PATH');
require_once ABSPATH . 'wp-admin/includes/template.php';
delete_option('lv_news_counters');
require_once dirname(__DIR__) . '/lv-news-suite.php';
wp_set_current_user(1);
LV_News_Suite::register_content_types();
LV_News_Suite::ensure_archive_page();
$failures = 0;
function check_counter($ok, $label) {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
}
function reset_counter_rendered() {
    $property = new ReflectionProperty('LV_News_Counters', 'rendered');
    if (PHP_VERSION_ID < 80100) $property->setAccessible(true);
    $property->setValue(null, false);
}
function counter_render($url, $repeat = false) {
    $_GET = [];
    $_SERVER['REQUEST_URI'] = parse_url($url, PHP_URL_PATH) ?: '/';
    $GLOBALS['wp']->main(parse_url($url, PHP_URL_QUERY) ?: '');
    reset_counter_rendered();
    $out = LV_News_Counters::render();
    if ($repeat) $out .= LV_News_Counters::render();
    return $out;
}
$script = "<script>\n// keep this newline\nwindow.testCounter = 'a\\\\b';\n</script>\n<noscript><img src=\"https://example.test/pixel?a=1&b=2\"></noscript>";
$rows = [
    ['name'=>'<b>First</b>', 'code'=>$script, 'enabled'=>'1'],
    ['name'=>'Second', 'code'=>'<a id="second-counter">Badge</a>', 'enabled'=>'1'],
    ['name'=>'Disabled', 'code'=>'<script>disabledCounter()</script>'],
    ['code'=>'   '], ['code'=>['invalid']], 'invalid',
];
$normalized = LV_News_Counters::normalize($rows);
check_counter(count($normalized) === 3, 'multiple snippets; empty/malformed rows ignored');
check_counter($normalized[0]['code'] === $script && $normalized[0]['name'] === 'First', 'raw code preserved, name sanitized');
check_counter(!$normalized[2]['enabled'], 'disabled state retained');
check_counter(LV_News_Counters::can_manage(), 'administrator can manage');
$editor = wp_insert_user(['user_login'=>'counter_editor_'.uniqid(), 'user_pass'=>wp_generate_password(), 'role'=>'editor']);
wp_set_current_user($editor);
check_counter(!LV_News_Counters::can_manage(), 'editor cannot manage raw scripts');
wp_set_current_user(1);
add_filter('wp_die_handler', function () { return function ($message) { throw new RuntimeException('blocked'); }; });
add_filter('wp_redirect', function ($location) { throw new RuntimeException('redirect'); });
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = ['counters'=>wp_slash($rows)];
$_REQUEST = ['_wpnonce'=>'invalid'];
try { LV_News_Counters::save(); } catch (RuntimeException $e) { check_counter($e->getMessage() === 'blocked', 'invalid nonce rejected'); }
check_counter(get_option(LV_News_Counters::OPTION, null) === null, 'invalid nonce cannot write settings');
$_REQUEST['_wpnonce'] = wp_create_nonce('lv_news_save_counters');
try { LV_News_Counters::save(); } catch (RuntimeException $e) { check_counter($e->getMessage() === 'redirect', 'valid POST redirects after saving'); }
check_counter(LV_News_Counters::items() === $normalized, 'saved multiple counters survive WordPress slash roundtrip');
wp_set_current_user($editor);
try { LV_News_Counters::save(); } catch (RuntimeException $e) { check_counter($e->getMessage() === 'blocked', 'save endpoint enforces permissions'); }
wp_set_current_user(1);
ob_start(); LV_News_Counters::page(); $admin = ob_get_clean();
check_counter(strpos($admin, esc_textarea($script)) !== false && strpos($admin, $script) === false, 'admin textarea escapes scripts instead of executing them');
$archive = LV_News_Suite::get_archive_page_id();
$out = counter_render('/?page_id='.$archive, true);
check_counter(substr_count($out, $script) === 1 && strpos($out, 'second-counter') !== false && strpos($out, 'disabledCounter') === false, 'archive renders both enabled counters exactly once');
check_counter(strpos($out, 'class="lv-news-counters"') !== false, 'visible counter area is present in news content');
check_counter(strpos($out, $script) > strpos($out, 'lv-news-counters'), 'saved counter code is inside the counter area');
$out = counter_render('/news/page/2/?page_id='.$archive.'&lv_news_page=2');
check_counter(strpos($out, $script) !== false, 'paginated archive renders counters');

reset_counter_rendered();
$_GET = [];
$_SERVER['REQUEST_URI'] = '/?page_id='.$archive;
$GLOBALS['wp']->main('page_id='.$archive);
$archive_html = LV_News_Public::archive_shortcode();
$counter_pos = strpos($archive_html, 'class="lv-news-counters"');
$news_pos = max((int) strpos($archive_html, 'class="na-grid"'), (int) strpos($archive_html, 'class="na-featured"'));
check_counter($counter_pos !== false && $counter_pos > $news_pos, 'archive places counters after the news block');

$news = wp_insert_post(['post_type'=>'lv_news','post_status'=>'publish','post_title'=>'Counter QA','post_content'=>'News content']);
// Model an existing published news item independently of editor readiness validation.
$GLOBALS['wpdb']->update($GLOBALS['wpdb']->posts, ['post_status'=>'publish'], ['ID'=>$news]);
clean_post_cache($news);
wp_set_current_user(0);
$out = counter_render('/?post_type=lv_news&p='.$news);
check_counter(strpos($out, $script) !== false, 'single news context renders counters');
reset_counter_rendered();
$_GET['elementor-preview'] = '1';
check_counter(LV_News_Counters::render() === '', 'Elementor preview excluded');
wp_set_current_user(1);
$home = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Home','post_content'=>'[lv_home_news]']);
update_option('show_on_front','page'); update_option('page_on_front',$home);
check_counter(counter_render('/?page_id='.$home) === '', 'static homepage excluded');
$other = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Other','post_content'=>'[lv_home_news]']);
check_counter(counter_render('/?page_id='.$other) === '', 'home snippet on unrelated page excluded');
check_counter(counter_render('/?post_type=lv_news&feed=rss2') === '', 'news feed excluded');
check_counter(counter_render('/?post_type=lv_news&p='.$news.'&preview=true') === '', 'preview excluded');
check_counter(counter_render('/?post_type=lv_news&p=99999999') === '', '404 excluded');
update_option(LV_News_Counters::OPTION, []);
check_counter(counter_render('/?page_id='.$archive) === '', 'empty settings produce no markup');
$_POST = []; $_REQUEST['_wpnonce'] = wp_create_nonce('lv_news_save_counters');
try { LV_News_Counters::save(); } catch (RuntimeException $e) {}
check_counter(LV_News_Counters::items() === [], 'all counters can be removed');
echo "Failures: $failures\n";
exit($failures ? 1 : 0);
