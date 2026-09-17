<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Administrator-managed HTML/JS snippets, deliberately kept out of content filters. */
final class LV_News_Counters
{
    const OPTION = 'lv_news_counters';
    private static $rendered = false;

    public static function boot()
    {
        add_action('admin_menu', [__CLASS__, 'menu'], 31);
        add_action('admin_post_lv_news_save_counters', [__CLASS__, 'save']);
        // Run late in wp_footer so service snippets stay close to the closing body tag.
        add_action('wp_footer', [__CLASS__, 'output'], 100);
    }

    public static function can_manage()
    {
        // Arbitrary scripts require both administration and WordPress's raw HTML permission.
        return current_user_can('manage_options') && current_user_can('unfiltered_html');
    }

    public static function menu()
    {
        add_submenu_page('edit.php?post_type=' . LV_News_Suite::POST_TYPE,
            'Счётчики новостей', 'Счётчики', 'manage_options', 'lv-news-counters', [__CLASS__, 'page']);
    }

    public static function items()
    {
        $items = get_option(self::OPTION, []);
        return is_array($items) ? $items : [];
    }

    public static function save()
    {
        if (!self::can_manage()) {
            wp_die('Недостаточно прав для сохранения кода счётчиков.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Используйте форму сохранения счётчиков.', '', ['response' => 405]);
        }
        check_admin_referer('lv_news_save_counters');
        $items = self::normalize(isset($_POST['counters']) ? wp_unslash($_POST['counters']) : []);
        update_option(self::OPTION, $items, false);
        wp_safe_redirect(add_query_arg(['post_type' => LV_News_Suite::POST_TYPE,
            'page' => 'lv-news-counters', 'saved' => '1'], admin_url('edit.php')));
        exit;
    }

    public static function normalize($input)
    {
        $items = [];
        if (!is_array($input)) {
            return $items;
        }
        foreach ($input as $row) {
            if (!is_array($row) || !isset($row['code']) || !is_string($row['code']) || trim($row['code']) === '') {
                continue;
            }
            $items[] = [
                'name' => isset($row['name']) && is_string($row['name']) ? sanitize_text_field($row['name']) : '',
                // Do not KSES, escape, compact or run shortcodes on trusted administrator JS.
                'code' => $row['code'],
                'enabled' => isset($row['enabled']) && $row['enabled'] === '1',
            ];
        }
        return $items;
    }

    private static function row($index, $item)
    {
        ?>
        <fieldset class="lv-counter-row" style="margin:16px 0;padding:16px;border:1px solid #c3c4c7;background:#fff">
            <legend>Счётчик</legend>
            <p><label>Название<br><input class="regular-text" type="text" name="counters[<?php echo esc_attr($index); ?>][name]" value="<?php echo esc_attr($item['name'] ?? ''); ?>" placeholder="Например, LiveInternet"></label></p>
            <p><label><input type="checkbox" name="counters[<?php echo esc_attr($index); ?>][enabled]" value="1" <?php checked(!empty($item['enabled'])); ?>> Включён</label></p>
            <p><label>Код счётчика (HTML / JavaScript)<br><textarea class="large-text code" rows="9" spellcheck="false" name="counters[<?php echo esc_attr($index); ?>][code]"><?php echo esc_textarea($item['code'] ?? ''); ?></textarea></label></p>
            <button type="button" class="button lv-counter-remove">Удалить счётчик</button>
        </fieldset>
        <?php
    }

    public static function page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo '<div class="wrap"><h1>Счётчики новостей</h1>';
        if (!self::can_manage()) {
            echo '<p>Для изменения счётчиков нужны права администратора и разрешение WordPress на вставку HTML/JavaScript (unfiltered_html).</p></div>';
            return;
        }
        $items = self::items();
        if (!$items) {
            $items = [['name' => '', 'code' => '', 'enabled' => true]];
        }
        ?>
        <p>Счётчики выводятся внизу страниц архива и отдельных новостей. На главной странице и в блоке «Последние новости» их нет.</p>
        <p>Вставляйте полный код каждого сервиса в отдельное поле. Используйте только доверенный код: скрипты выполняются у посетителей сайта. Названия видны только здесь.</p>
        <p>Плагин выводит код без дополнительных контейнеров и стилей, поэтому невидимые счётчики не создают пустой отступ перед подвалом.</p>
        <?php if (isset($_GET['saved']) && $_GET['saved'] === '1') : ?>
            <div class="notice notice-success is-dismissible"><p>Счётчики сохранены. Если на сайте включён кеш, очистите его.</p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:1000px">
            <input type="hidden" name="action" value="lv_news_save_counters">
            <?php wp_nonce_field('lv_news_save_counters'); ?>
            <div id="lv-counter-rows">
                <?php foreach (array_values($items) as $index => $item) { self::row((string) $index, $item); } ?>
            </div>
            <button type="button" class="button" id="lv-counter-add">Добавить счётчик</button>
            <?php submit_button('Сохранить счётчики'); ?>
        </form>
        <template id="lv-counter-template"><?php self::row('__INDEX__', ['enabled' => true]); ?></template>
        <script>
        (function () {
            const rows = document.getElementById('lv-counter-rows');
            let nextIndex = <?php echo (int) count($items); ?>;
            document.getElementById('lv-counter-add').addEventListener('click', function () {
                const fragment = document.getElementById('lv-counter-template').content.cloneNode(true);
                fragment.querySelectorAll('[name]').forEach(function (field) {
                    field.name = field.name.replace('__INDEX__', String(nextIndex));
                });
                nextIndex++;
                rows.appendChild(fragment);
                rows.lastElementChild.querySelector('input[type="text"]').focus();
            });
            rows.addEventListener('click', function (event) {
                if (event.target.classList.contains('lv-counter-remove')) {
                    event.target.closest('.lv-counter-row').remove();
                }
            });
        })();
        </script>
        </div>
        <?php
    }

    public static function output()
    {
        if (self::$rendered || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)
            || is_feed() || is_embed() || is_preview() || is_404() || is_front_page() || is_home()
            || isset($_GET['elementor-preview']) || is_customize_preview()) {
            return;
        }
        if (!is_singular(LV_News_Suite::POST_TYPE) && !LV_News_Suite::is_archive_context()) {
            return;
        }
        if (is_singular() && post_password_required()) {
            return;
        }
        $code = [];
        foreach (self::items() as $item) {
            if (is_array($item) && !empty($item['enabled']) && isset($item['code']) && is_string($item['code']) && trim($item['code']) !== '') {
                $code[] = $item['code'];
            }
        }
        if (!$code) {
            return;
        }
        self::$rendered = true;
        // Keep third-party snippets byte-for-byte intact and do not introduce visible layout.
        foreach ($code as $snippet) {
            echo $snippet . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Saved only by users with unfiltered_html AND manage_options.
        }
    }
}
