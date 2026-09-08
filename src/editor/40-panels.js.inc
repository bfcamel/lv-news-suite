    function Progress(props) {
        var e = props.evaluation;
        var missing = e.missing || [];
        var stateText = e.missingRequired && e.missingRequired.length
            ? 'Не хватает обязательных полей'
            : (missing.length ? 'Можно публиковать — есть рекомендации' : 'Все проверки пройдены');
        var missingText = missing.map(function (item) { return item.label; }).join(' · ');

        return el('div', { className: 'lvn-progress' },
            el('div', { className: 'lvn-progress__score', title: 'Выполнено ' + e.quality + '% редакционных проверок' }, e.quality + '%'),
            el('div', { className: 'lvn-progress__copy' },
                el('strong', null, stateText),
                el('span', null, e.words + ' слов · ≈ ' + e.minutes + ' мин. чтения'),
                missing.length
                    ? el('span', { className: 'lvn-progress__missing' }, el('b', null, 'До 100%: '), missingText)
                    : el('span', { className: 'lvn-progress__complete' }, 'Готовность 100%: обязательные поля и рекомендации выполнены.')
            )
        );
    }

    function CheckList(props) {
        return el('div', { className: 'lvn-checks' }, props.items.map(function (item) {
            return el('div', { className: 'lvn-check ' + (item.ready ? 'is-ok' : 'is-warn'), key: item.key },
                el('span', { className: 'lvn-check__icon', 'aria-hidden': 'true' }, item.ready ? '✓' : '!'),
                el('div', null,
                    el('strong', null, item.label),
                    !item.ready && item.hint ? el('small', null, item.hint) : null
                )
            );
        }));
    }

    function BasicPanel(props) {
        var data = props.data;
        var e = props.evaluation;
        var editorActions = useDispatch('core/editor');
        var selectedTerm = Array.isArray(data.termIds) && data.termIds.length ? String(data.termIds[0]) : '';
        var options = [{ label: 'Выберите тип новости', value: '' }].concat((config.terms || []).map(function (term) {
            return { label: term.name, value: String(term.id) };
        }));

        function generateExcerpt() {
            if (e.body.length < 30) {
                notify('warning', 'Сначала добавьте основной текст новости.');
                return;
            }
            var source = e.body;
            var result = source;
            if (source.length > 220) {
                var cut = source.slice(0, 221);
                var end = Math.max(cut.lastIndexOf('. '), cut.lastIndexOf('! '), cut.lastIndexOf('? '));
                if (end >= 100) {
                    result = cut.slice(0, end + 1);
                } else {
                    var wordEnd = cut.lastIndexOf(' ');
                    result = cut.slice(0, wordEnd > 0 ? wordEnd : 220) + '…';
                }
            }
            editorActions.editPost({ excerpt: result });
            notify('success', 'Анонс создан. Проверьте формулировку.');
        }

        return el(Fragment, null,
            el(TextControl, {
                label: 'Заголовок',
                value: data.title || '',
                help: e.title.length + ' знаков',
                onChange: function (value) { editorActions.editPost({ title: value }); }
            }),
            el(TextControl, {
                label: 'Ярлык URL',
                value: data.slug || '',
                help: 'Только латиница. После публикации меняйте URL только осознанно.',
                onChange: function (value) { editorActions.editPost({ slug: transliterate(value) }); }
            }),
            el(Button, {
                variant: 'secondary',
                className: 'lvn-full-button',
                onClick: function () { editorActions.editPost({ slug: transliterate(e.title) }); }
            }, 'Сформировать ярлык из заголовка'),
            el(SelectControl, {
                label: 'Тип новости',
                value: selectedTerm,
                options: options,
                onChange: function (value) {
                    var patch = {};
                    patch[config.taxonomy] = value ? [parseInt(value, 10)] : [];
                    editorActions.editPost(patch);
                }
            }),
            el(TextareaControl, {
                label: 'Краткий анонс',
                value: data.excerpt || '',
                rows: 5,
                help: e.excerpt.length + ' знаков',
                onChange: function (value) { editorActions.editPost({ excerpt: value }); }
            }),
            el(Button, { variant: 'secondary', className: 'lvn-full-button', onClick: generateExcerpt }, 'Создать анонс из текста'),
            el('div', { className: 'lvn-meta-note' }, 'Автор: ', el('strong', null, data.author && data.author.name ? data.author.name : config.currentUser.name))
        );
    }

    function ImagePanel(props) {
        var data = props.data;
        var editorActions = useDispatch('core/editor');
        var x = parseInt(getMetaValue(data.meta, config.meta.focusX, 50), 10);
        var y = parseInt(getMetaValue(data.meta, config.meta.focusY, 50), 10);
        var zoom = parseInt(getMetaValue(data.meta, config.meta.focusZoom, 100), 10);
        var localAltState = useState(data.media ? (data.media.alt_text || '') : '');
        var localAlt = localAltState[0];
        var setLocalAlt = localAltState[1];

        useEffect(function () {
            setLocalAlt(data.media ? (data.media.alt_text || '') : '');
        }, [data.featuredId, data.media && data.media.alt_text]);

        function mediaUrl(media) {
            if (!media) { return ''; }
            if (media.media_details && media.media_details.sizes) {
                if (media.media_details.sizes.medium_large) { return media.media_details.sizes.medium_large.source_url; }
                if (media.media_details.sizes.medium) { return media.media_details.sizes.medium.source_url; }
            }
            return media.source_url || media.url || '';
        }

        function saveAlt() {
            if (!data.featuredId || !apiFetch) { return; }
            apiFetch({ path: '/wp/v2/media/' + data.featuredId, method: 'POST', data: { alt_text: localAlt } })
                .then(function (media) {
                    var coreActions = wp.data.dispatch('core');
                    if (coreActions && typeof coreActions.receiveEntityRecords === 'function' && media) {
                        coreActions.receiveEntityRecords('postType', 'attachment', media);
                    } else if (coreActions && typeof coreActions.invalidateResolution === 'function') {
                        coreActions.invalidateResolution('getMedia', [data.featuredId]);
                    }
                    notify('success', 'Alt-текст сохранён.');
                })
                .catch(function () { notify('error', 'Не удалось сохранить alt-текст.'); });
        }

        var previewUrl = mediaUrl(data.media);
        return el(Fragment, null,
            previewUrl ? el('div', { className: 'lvn-image-preview' },
                el('img', {
                    src: previewUrl,
                    alt: '',
                    style: imageCropStyle(data)
                })
            ) : el('div', { className: 'lvn-image-empty' }, 'Главное изображение не выбрано'),
            el(MediaUploadCheck, null,
                el(MediaUpload, {
                    onSelect: function (media) {
                        var nextMeta = Object.assign({}, data.meta || {});
                        nextMeta[config.meta.focusX] = 50;
                        nextMeta[config.meta.focusY] = 50;
                        nextMeta[config.meta.focusZoom] = 100;
                        if (!config.canPublish && config.meta.featured) {
                            delete nextMeta[config.meta.featured];
                        }
                        editorActions.editPost({ featured_media: media.id, meta: nextMeta });
                    },
                    allowedTypes: ['image'],
                    value: data.featuredId,
                    render: function (obj) {
                        return el(Button, { variant: 'secondary', className: 'lvn-full-button', onClick: obj.open }, data.featuredId ? 'Заменить изображение' : 'Выбрать изображение');
                    }
                })
            ),
            data.featuredId ? el(Button, { variant: 'tertiary', className: 'lvn-full-button', isDestructive: true, onClick: function () { editorActions.editPost({ featured_media: 0 }); } }, 'Удалить изображение') : null,
            data.featuredId ? el(Fragment, null,
                el(TextareaControl, { label: 'Alt-текст', value: localAlt, rows: 3, onChange: setLocalAlt, onBlur: saveAlt }),
                el('p', { className: 'lvn-subheading' }, 'Фокус главного изображения'),
                el(RangeControl, { label: 'По горизонтали', min: 0, max: 100, value: x, onChange: function (value) { var p = {}; p[config.meta.focusX] = value; updateMeta(editorActions, data, p); } }),
                el(RangeControl, { label: 'По вертикали', min: 0, max: 100, value: y, onChange: function (value) { var p = {}; p[config.meta.focusY] = value; updateMeta(editorActions, data, p); } }),
                el(RangeControl, { label: 'Масштаб', min: 100, max: 180, step: 1, value: zoom, onChange: function (value) { var p = {}; p[config.meta.focusZoom] = value; updateMeta(editorActions, data, p); } }),
                el(Button, { variant: 'secondary', className: 'lvn-full-button', onClick: function () {
                    var p = {}; p[config.meta.focusX] = 50; p[config.meta.focusY] = 50; p[config.meta.focusZoom] = 100; updateMeta(editorActions, data, p);
                } }, 'Сбросить кадрирование')
            ) : null
        );
    }

    function FeaturedPanel(props) {
        var data = props.data;
        var editorActions = useDispatch('core/editor');
        var featured = !!getMetaValue(data.meta, config.meta.featured, false);
        return el(Fragment, null,
            el(ToggleControl, {
                label: 'Главная новость',
                checked: featured,
                disabled: !config.canPublish,
                help: featured ? 'После публикации эта новость станет главной в архиве и на главной странице.' : 'Одновременно главной может быть только одна опубликованная новость.',
                onChange: function (value) { var p = {}; p[config.meta.featured] = !!value; updateMeta(editorActions, data, p); }
            }),
            !config.canPublish ? el(Notice, { status: 'warning', isDismissible: false }, 'Назначать главную новость может пользователь с правом публикации.') : null
        );
    }

    function SeoPanel(props) {
        var data = props.data;
        var editorActions = useDispatch('core/editor');
        var rankStatePair = useState(rankMathRuntimeState);
        var rankState = rankStatePair[0];
        var seoState = useSeoSaveState();
        function fieldDisabled(name) {
            return !config.seoPermissions || config.seoPermissions[config.meta[name]] !== true
                || (config.rankMath && config.rankMath.active && rankState.status !== 'ready');
        }
        var setRankState = rankStatePair[1];

        function field(name, value) {
            var key = config.meta && config.meta[name];
            if (!key) {
                return;
            }
            if (!config.seoPermissions || config.seoPermissions[key] !== true) { return; }
            if (config.rankMath && config.rankMath.active) {
                syncRankMathField(name, value);
            } else {
                var p = {}; p[key] = value;
                updateMeta(editorActions, data, p);
            }
        }

        function clip(value, limit) {
            var clean = String(value || '').replace(/\s+/g, ' ').trim();
            if (clean.length <= limit) {
                return clean;
            }
            var cut = clean.slice(0, Math.max(1, limit - 1)).replace(/\s+\S*$/, '').replace(/[\s,.;:—-]+$/, '');
            return cut + '…';
        }

        function autoFill() {
            var title = textFromHtml(data.title, false);
            var excerpt = textFromHtml(data.excerpt, false);
            var body = textFromHtml(data.content, false);
            var siteName = String(config.siteName || 'Люди и Верблюды').trim();
            var seoTitle = clip(title + (title ? ' | ' + siteName : ''), 60);
            var focus = focusKeywordFromTitle(title);
            var description = clip(excerpt || body, 158);
            description = ensureFocusInDescription(description, focus, 158);
            var patch = {};

            patch[config.meta.seoTitle] = seoTitle;
            patch[config.meta.seoDescription] = description;
            patch[config.meta.ogTitle] = seoTitle || title;
            patch[config.meta.ogDescription] = description;
            patch[config.meta.seoFocusKeyword] = focus;

            var filled = 0;
            var localPatch = {};
            ['seoTitle', 'seoDescription', 'ogTitle', 'ogDescription', 'seoFocusKeyword'].forEach(function (name) {
                var key = config.meta[name];
                if (config.seoPermissions[key] === true && !String(data.meta[key] || '').trim()) {
                    if (config.rankMath && config.rankMath.active) { field(name, patch[key]); }
                    else { localPatch[key] = patch[key]; }
                    filled++;
                }
            });
            if (Object.keys(localPatch).length) { updateMeta(editorActions, data, localPatch); }
            if (filled) { refreshRankMath('content'); }
            notify('success', filled ? 'Пустые SEO-поля заполнены. Проверьте формулировки и сохраните новость.' : 'Нет доступных пустых полей. Ручные значения сохранены.');
        }

        function audit() {
            var focus = String(getMetaValue(data.meta, config.meta.seoFocusKeyword, '') || '').trim();
            var seoTitle = String(getMetaValue(data.meta, config.meta.seoTitle, '') || '').trim();
            var description = String(getMetaValue(data.meta, config.meta.seoDescription, '') || '').trim();
            var title = textFromHtml(data.title, false);
            var body = textFromHtml(data.content, false);
            var needle = normalizeForSearch(focus);
            var focusWords = needle ? needle.split(/\s+/).filter(Boolean).length : 0;
            var titleNorm = normalizeForSearch(title + ' ' + seoTitle);
            var descriptionNorm = normalizeForSearch(description);
            var bodyNorm = normalizeForSearch(body);

            var htmlNode = document.createElement('div');
            htmlNode.innerHTML = data.content || '';
            var anchors = Array.prototype.slice.call(htmlNode.querySelectorAll('a[href]'));
            var host = '';
            try { host = window.location.hostname || ''; } catch (e) { host = ''; }
            var internal = 0;
            var external = 0;
            anchors.forEach(function (a) {
                var href = String(a.getAttribute('href') || '').trim();
                if (!href || href === '#' || /^(mailto:|tel:|javascript:)/i.test(href)) {
                    return;
                }
                try {
                    var u = new URL(href, window.location.origin);
                    if (!u.hostname || u.hostname === host) { internal += 1; }
                    else { external += 1; }
                } catch (e) {}
            });

            return [
                { label: 'Фокусная фраза: 2–5 слов', ok: focusWords >= 2 && focusWords <= 5, detail: focus ? (focusWords + ' сл.') : 'не заполнена' },
                { label: 'Фокусная фраза есть в заголовке', ok: !!needle && titleNorm.indexOf(needle) !== -1 },
                { label: 'Фокусная фраза есть в description', ok: !!needle && descriptionNorm.indexOf(needle) !== -1 },
                { label: 'Фокусная фраза есть в тексте', ok: !!needle && bodyNorm.indexOf(needle) !== -1 },
                { label: 'SEO title около 35–60 знаков', ok: seoTitle.length >= 35 && seoTitle.length <= 60, detail: seoTitle.length + ' зн.' },
                { label: 'Meta description 120–160 знаков', ok: description.length >= 120 && description.length <= 160, detail: description.length + ' зн.' },
                { label: 'Есть внутренняя ссылка', ok: internal > 0, detail: String(internal) },
                { label: 'Есть внешняя ссылка', ok: external > 0, detail: String(external) }
            ];
        }

        useEffect(function () {
            return subscribeRankMathRuntime(setRankState);
        }, []);

        useEffect(function () {
            if (!config.rankMath || !config.rankMath.active) {
                return;
            }
            var timer = window.setTimeout(function () {
                var runtime = probeRankMathRuntime();
                if (runtime.editorReady) {
                    refreshRankMath('content');
                }
            }, 700);
            return function () { window.clearTimeout(timer); };
        }, []);

        function rankMathSettingsLink() {
            if (!config.canManage || !config.rankMath || !config.rankMath.settingsUrl) {
                return null;
            }
            return el('a', {
                href: config.rankMath.settingsUrl,
                target: '_blank',
                rel: 'noreferrer noopener'
            }, 'Открыть настройки Rank Math');
        }

        function rankMathNotice() {
            var settingsLink = rankMathSettingsLink();
            if (!config.rankMath || !config.rankMath.active || rankState.status === 'inactive') {
                return el(Notice, { status: 'warning', isDismissible: false },
                    'Rank Math не обнаружен. SEO-поля сохраняются и выводятся самим LV News Suite.'
                );
            }

            if (rankState.status === 'ready') {
                return el(Notice, { status: 'success', isDismissible: false },
                    'Rank Math подключён. Изменения мгновенно передаются в его SEO-поля и анализатор; сохранение выполняет Rank Math, а резервная копия обновляется на сервере.'
                );
            }

            if (rankState.status === 'loading') {
                return el(Notice, { status: 'info', isDismissible: false },
                    el('span', { className: 'lvn-rankmath-loading' },
                        el(Spinner, null),
                        el('span', null, 'Подключение к редактору Rank Math…')
                    )
                );
            }

            if (rankState.status === 'disabled') {
                return el(Notice, { status: 'warning', isDismissible: false },
                    el(Fragment, null,
                        'SEO Controls для типа записи «Новости» выключены. ',
                        settingsLink
                    )
                );
            }

            if (rankState.status === 'denied') {
                return el(Notice, { status: 'warning', isDismissible: false },
                    'Rank Math разрешён для «Новостей», но у текущего пользователя нет доступа к его on-page SEO редактору.'
                );
            }

            if (rankState.status === 'partial') {
                return el(Notice, { status: 'warning', isDismissible: false },
                    'Хранилище SEO Rank Math подключено, но анализатор не завершил загрузку. Для редактирования SEO обновите страницу после завершения загрузки.'
                );
            }

            return el(Notice, { status: 'error', isDismissible: false },
                el(Fragment, null,
                    'Rank Math включён для «Новостей», но его редактор не загрузился за 12 секунд. Обновите страницу и проверьте ошибки JavaScript. ',
                    settingsLink
                )
            );
        }

        var checks = audit();
        var passed = checks.filter(function (item) { return item.ok; }).length;
        return el(Fragment, null,
            rankMathNotice(),
            el('p', { role: 'status' }, seoStatusLabel(seoState)),
            seoState === 'error' ? el(Button, { variant: 'secondary', onClick: retrySeoSave }, 'Повторить сохранение SEO') : null,
            el(Button, { variant: 'secondary', className: 'lvn-full-button', disabled: !!(config.rankMath && config.rankMath.active && rankState.status !== 'ready'), onClick: autoFill }, 'Заполнить пустые SEO-поля'),
            config.rankMath && config.rankMath.active
                ? el(Button, {
                    variant: 'tertiary',
                    className: 'lvn-full-button lvn-rankmath-refresh',
                    onClick: function () {
                        refreshRankMath('content', true);
                    }
                }, 'Пересчитать анализ Rank Math')
                : null,
            el(TextControl, { label: 'Фокусная ключевая фраза', value: getMetaValue(data.meta, config.meta.seoFocusKeyword, ''), help: 'Лучше 2–5 слов и точное естественное вхождение в заголовке, description и тексте. Автозаполнение больше не копирует длинный заголовок целиком.', disabled: fieldDisabled('seoFocusKeyword'), onChange: function (v) { field('seoFocusKeyword', v); } }),
            el(TextControl, { label: 'SEO title', value: getMetaValue(data.meta, config.meta.seoTitle, ''), help: 'Оптимально около 35–60 знаков.', disabled: fieldDisabled('seoTitle'), onChange: function (v) { field('seoTitle', v); } }),
            el(TextareaControl, { label: 'Meta description', value: getMetaValue(data.meta, config.meta.seoDescription, ''), rows: 4, help: 'Рекомендуем 120–160 знаков.', disabled: fieldDisabled('seoDescription'), onChange: function (v) { field('seoDescription', v); } }),
            el(TextControl, { label: 'Canonical URL', value: getMetaValue(data.meta, config.meta.seoCanonical, ''), help: 'Обычно оставляйте пустым — будет использован URL самой новости.', disabled: fieldDisabled('seoCanonical'), onChange: function (v) { field('seoCanonical', v); } }),
            el(TextControl, { label: 'Open Graph title', value: getMetaValue(data.meta, config.meta.ogTitle, ''), help: 'Для социальных сетей; по умолчанию используется SEO title.', disabled: fieldDisabled('ogTitle'), onChange: function (v) { field('ogTitle', v); } }),
            el(TextareaControl, { label: 'Open Graph description', value: getMetaValue(data.meta, config.meta.ogDescription, ''), rows: 3, disabled: fieldDisabled('ogDescription'), onChange: function (v) { field('ogDescription', v); } }),
            el('div', { className: 'lvn-seo-audit' },
                el('div', { className: 'lvn-seo-audit__head' },
                    el('strong', null, 'Проверка SEO: ' + passed + '/' + checks.length)
                ),
                el('ul', { className: 'lvn-seo-audit__list' }, checks.map(function (item, index) {
                    return el('li', { key: index, className: item.ok ? 'is-ok' : 'is-missing' },
                        el('span', { className: 'lvn-seo-audit__mark', 'aria-hidden': true }, item.ok ? '✓' : '•'),
                        el('span', null, item.label),
                        item.detail ? el('small', null, item.detail) : null
                    );
                }))
            )
        );
    }

    function TemplatePanel(props) {
        var data = props.data;
        var state = useState('standard');
        var selected = state[0];
        var setSelected = state[1];
        var actions = useDispatch('core/block-editor');

        var templates = {
            standard: [
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Что произошло' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Почему это важно' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Что будет дальше' }],
                ['core/paragraph', {}]
            ],
            ward: [
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Что случилось' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Как мы помогаем' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Как можно помочь' }],
                ['core/paragraph', {}]
            ],
            event: [
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Как всё прошло' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Главный результат' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Благодарим' }],
                ['core/paragraph', {}]
            ],
            help: [
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Какая помощь нужна' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Что уже сделано' }],
                ['core/paragraph', {}],
                ['lv-news/heading-2', { content: 'Как помочь' }],
                ['core/paragraph', {}]
            ]
        };

        function insertTemplate() {
            if (textFromHtml(data.content, false).length > 0 && !window.confirm('В новости уже есть текст. Добавить структуру в текущую позицию курсора?')) {
                return;
            }
            var blocks = (templates[selected] || templates.standard).map(function (definition) {
                return createBlock(definition[0], definition[1]);
            });
            insertAtCaret(blocks, actions);
            notify('success', 'Структура добавлена в текущую позицию. Заполните разделы и удалите ненужные.');
        }

        return el(Fragment, null,
            el(Notice, { status: 'info', isDismissible: false }, 'Структура добавляет подзаголовки и пустые абзацы, не заменяя ваш текст.'),
            el(SelectControl, {
                label: 'Шаблон',
                value: selected,
                options: [
                    { label: 'Обычная новость', value: 'standard' },
                    { label: 'История подопечного', value: 'ward' },
                    { label: 'Событие или мероприятие', value: 'event' },
                    { label: 'Сбор или просьба о помощи', value: 'help' }
                ],
                onChange: setSelected
            }),
            el(Button, { variant: 'secondary', className: 'lvn-full-button', onClick: insertTemplate }, 'Добавить структуру')
        );
    }

    function QuickBlocks() {
        var actions = useDispatch('core/block-editor');
        var items = [
            ['Абзац', 'core/paragraph', {}],
            ['H2 раздел', 'lv-news/heading-2', {}],
            ['H3 подзаголовок', 'lv-news/heading-3', {}],
            ['Лид', 'lv-news/lead', {}],
            ['Фирменная врезка', 'lv-news/brand-accent', {}],
            ['Важно', 'lv-news/notice', {}],
            ['Факт', 'lv-news/fact', {}],
            ['Цитата', 'core/quote', {}],
            ['Список', 'core/list', {}],
            ['Фото', 'core/image', {}],
            ['Галерея', 'core/gallery', {}],
            ['Источник', 'lv-news/source-note', {}],
            ['Кнопка', 'lv-news/cta', {}]
        ];
        return el(Fragment, null,
            el('p', { className: 'lvn-quick-help' }, 'Блок добавится в позицию текущей каретки.'),
            el('div', { className: 'lvn-quick-grid' }, items.map(function (item) {
            return el(Button, { key: item[0], variant: 'secondary', onClick: function () {
                var block = createBlock(item[1], item[2]);
                insertAtCaret(block, actions);
            } }, item[0]);
        }))
        );
    }

    function PreviewPanel(props) {
        var data = props.data;
        var e = props.evaluation;
        var openState = useState(false);
        var isOpen = openState[0];
        var setOpen = openState[1];
        var deviceState = useState('desktop');
        var device = deviceState[0];
        var setDevice = deviceState[1];
        var editorActions = useDispatch('core/editor');
        var thumb = data.media && (data.media.source_url || data.media.url) ? (data.media.source_url || data.media.url) : '';

        var savingPair = useState(false);
        var previewSaving = savingPair[0];
        var setPreviewSaving = savingPair[1];
        function openPagePreview() {
            if (!editorActions.autosave || previewSaving) { return; }
            setPreviewSaving(true);
            Promise.resolve().then(function () { return editorActions.autosave(); }).then(function (result) {
                var editor = wp.data.select('core/editor');
                if (result === false || (editor.didPostSaveRequestFail && editor.didPostSaveRequestFail())) {
                    throw new Error('Не удалось сохранить текущую версию. Предпросмотр не открыт.');
                }
                setOpen(true);
            }).catch(function (error) {
                notify('error', error && error.message || 'Не удалось сохранить текущую версию. Повторите предпросмотр.');
            }).finally(function () { setPreviewSaving(false); });
        }

        var width = device === 'mobile' ? '390px' : (device === 'tablet' ? '820px' : '100%');
        return el(Fragment, null,
            el('div', { className: 'lvn-placement-preview' },
                el('div', { className: 'lvn-placement-preview__media' }, thumb ? el('img', { src: thumb, alt: '', style: imageCropStyle(data) }) : el('span', null, 'Фото')),
                el('div', { className: 'lvn-placement-preview__copy' },
                    el('small', null, 'Карточка в архиве'),
                    el('strong', null, e.title || 'Заголовок новости'),
                    el('p', null, e.excerpt || 'Краткий анонс появится здесь.')
                )
            ),
            el(Button, { variant: 'primary', className: 'lvn-full-button', onClick: openPagePreview, disabled: !data.previewLink || previewSaving }, previewSaving ? 'Сохраняем для предпросмотра…' : 'Открыть предпросмотр страницы'),
            isOpen ? el(Modal, { title: 'Предпросмотр новости', className: 'lvn-preview-modal', onRequestClose: function () { setOpen(false); } },
                el('div', { className: 'lvn-device-switch' },
                    ['desktop','tablet','mobile'].map(function (name) {
                        var labels = { desktop: 'Компьютер', tablet: 'Планшет', mobile: 'Телефон' };
                        return el(Button, { key: name, variant: device === name ? 'primary' : 'secondary', onClick: function () { setDevice(name); } }, labels[name]);
                    })
                ),
                el('div', { className: 'lvn-preview-frame-wrap' },
                    el('iframe', { title: 'Предпросмотр новости', src: data.previewLink, style: { width: width } })
                )
            ) : null
        );
    }

