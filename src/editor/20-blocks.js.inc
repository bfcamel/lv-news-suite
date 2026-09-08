    function readContentSelection() {
        var store = wp.data.select('core/block-editor');
        if (!store) {
            return lastContentSelection;
        }

        var selection = store.getSelectionStart ? store.getSelectionStart() : null;
        var clientId = selection && selection.clientId
            ? selection.clientId
            : (store.getSelectedBlockClientId ? store.getSelectedBlockClientId() : null);

        if (clientId) {
            lastContentSelection = {
                clientId: clientId,
                attributeKey: selection && selection.attributeKey ? selection.attributeKey : null,
                offset: selection && typeof selection.offset === 'number' ? selection.offset : null
            };
        }

        return lastContentSelection;
    }

    if (subscribe) {
        subscribe(function () {
            readContentSelection();
        });
    }

    function insertionContext() {
        var store = wp.data.select('core/block-editor');
        var remembered = readContentSelection();

        if (!store) {
            return { index: undefined, rootClientId: undefined, replaceClientId: null };
        }

        var clientId = remembered && remembered.clientId ? remembered.clientId : null;
        if (!clientId || !store.getBlock || !store.getBlock(clientId)) {
            var fallback = store.getBlockInsertionPoint ? store.getBlockInsertionPoint() : null;
            return {
                index: fallback && typeof fallback.index === 'number' ? fallback.index : undefined,
                rootClientId: fallback ? fallback.rootClientId : undefined,
                replaceClientId: null
            };
        }

        var selectedBlock = store.getBlock(clientId);
        var anchorClientId = clientId;

        /*
         * Если курсор находится внутри внутреннего блока (например, list-item),
         * обычная редакционная врезка не должна становиться ребёнком списка.
         * В таком случае вставляем рядом с верхнеуровневым контейнером.
         */
        if (store.getBlockParents) {
            var parents = store.getBlockParents(clientId, false) || [];
            if (parents.length) {
                anchorClientId = parents[0];
            }
        }

        var rootClientId = store.getBlockRootClientId ? store.getBlockRootClientId(anchorClientId) : undefined;
        var index = store.getBlockIndex ? store.getBlockIndex(anchorClientId) : -1;
        if (typeof index !== 'number' || index < 0) {
            index = undefined;
        }

        var isDirectAnchor = anchorClientId === clientId;
        var isEmptyParagraph = isDirectAnchor && selectedBlock && selectedBlock.name === 'core/paragraph' &&
            textFromHtml(selectedBlock.attributes && selectedBlock.attributes.content, false).length === 0;

        if (isEmptyParagraph) {
            return { index: index, rootClientId: rootClientId, replaceClientId: clientId };
        }

        /*
         * Каретка в самом начале блока означает вставку перед ним. Во всех
         * остальных случаях новый блок появляется сразу после текущего блока.
         */
        if (index !== undefined && !(remembered && remembered.clientId === clientId && remembered.offset === 0)) {
            index += 1;
        }

        return { index: index, rootClientId: rootClientId, replaceClientId: null };
    }

    function insertAtCaret(blocks, actions) {
        var list = Array.isArray(blocks) ? blocks : [blocks];
        var context = insertionContext();

        if (!actions || !list.length) {
            return;
        }

        if (context.replaceClientId && actions.replaceBlocks) {
            actions.replaceBlocks(context.replaceClientId, list, 0, 0);
        } else if (actions.insertBlocks) {
            actions.insertBlocks(list, context.index, context.rootClientId, true, 0);
        }

        if (actions.selectBlock && list[0] && list[0].clientId) {
            actions.selectBlock(list[0].clientId, 0);
        }

        lastContentSelection = list[0] && list[0].clientId
            ? { clientId: list[0].clientId, attributeKey: null, offset: 0 }
            : lastContentSelection;
    }

    function registerEditorialBlocks() {
        if (!registerBlockType || !RichText) {
            return;
        }

        function registerSimpleHeading(name, level, title, description) {
            if (wp.blocks.getBlockType(name)) {
                return;
            }
            registerBlockType(name, {
                apiVersion: 3,
                title: title,
                icon: level === 2 ? 'heading' : 'editor-textcolor',
                category: 'lv-news',
                description: description,
                keywords: ['заголовок', 'подзаголовок', 'h' + level, 'раздел'],
                supports: { html: false, reusable: false, anchor: true },
                attributes: {
                    content: { type: 'string', source: 'html', selector: 'h' + level }
                },
                edit: function (props) {
                    return el(RichText, {
                        tagName: 'h' + level,
                        className: 'lv-news-heading lv-news-heading--' + level,
                        value: props.attributes.content,
                        placeholder: level === 2 ? 'Заголовок раздела…' : 'Подзаголовок…',
                        allowedFormats: ['core/bold', 'core/italic'],
                        onChange: function (value) { props.setAttributes({ content: value }); }
                    });
                },
                save: function (props) {
                    return el(RichText.Content, {
                        tagName: 'h' + level,
                        className: 'lv-news-heading lv-news-heading--' + level,
                        value: props.attributes.content
                    });
                }
            });
        }

        registerSimpleHeading('lv-news/heading-2', 2, 'Заголовок раздела (H2)', 'Основной подзаголовок внутри новости.');
        registerSimpleHeading('lv-news/heading-3', 3, 'Подзаголовок (H3)', 'Вложенный подзаголовок внутри раздела.');

        if (!wp.blocks.getBlockType('lv-news/lead')) {
            registerBlockType('lv-news/lead', {
                apiVersion: 3,
                title: 'Лид / вступление',
                icon: 'editor-paragraph',
                category: 'lv-news',
                description: 'Крупный вводный абзац для начала материала.',
                keywords: ['лид', 'вступление', 'анонс', 'первый абзац'],
                supports: { html: false, reusable: false },
                attributes: { content: { type: 'string', source: 'html', selector: '.lv-news-lead' } },
                edit: function (props) {
                    return el(RichText, {
                        tagName: 'p',
                        className: 'lv-news-lead',
                        value: props.attributes.content,
                        placeholder: 'Коротко объясните главное в 1–2 предложениях…',
                        allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                        onChange: function (value) { props.setAttributes({ content: value }); }
                    });
                },
                save: function (props) {
                    return el(RichText.Content, { tagName: 'p', className: 'lv-news-lead', value: props.attributes.content });
                }
            });
        }

        if (!wp.blocks.getBlockType('lv-news/brand-accent')) {
            registerBlockType('lv-news/brand-accent', {
                apiVersion: 3,
                title: 'Фирменная врезка',
                icon: 'editor-quote',
                category: 'lv-news',
                description: 'Красная врезка фонда с вертикальной линией слева.',
                keywords: ['врезка', 'фирменная', 'акцент', 'красная', 'важный тезис'],
                supports: { html: false, reusable: false },
                attributes: { content: { type: 'string', source: 'html', selector: '.lv-news-brand-accent' } },
                edit: function (props) {
                    return el(RichText, {
                        tagName: 'span',
                        className: 'lv-news-brand-accent',
                        value: props.attributes.content,
                        placeholder: 'Нужны заботливые руки',
                        allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                        onChange: function (value) { props.setAttributes({ content: value }); }
                    });
                },
                save: function (props) {
                    return el(RichText.Content, { tagName: 'span', className: 'lv-news-brand-accent', value: props.attributes.content });
                }
            });
        }

        if (!wp.blocks.getBlockType('lv-news/notice')) {
            registerBlockType('lv-news/notice', {
                apiVersion: 3,
                title: 'Важная информация',
                icon: 'warning',
                category: 'lv-news',
                description: 'Отдельный информационный блок для важных условий, сроков и предупреждений.',
                keywords: ['важно', 'информация', 'предупреждение', 'обратите внимание'],
                supports: { html: false, reusable: false },
                attributes: {
                    label: { type: 'string', source: 'html', selector: '.lv-news-notice__label', default: 'Важно' },
                    content: { type: 'string', source: 'html', selector: '.lv-news-notice__text' }
                },
                edit: function (props) {
                    return el('aside', { className: 'lv-news-notice' },
                        el(RichText, {
                            tagName: 'strong', className: 'lv-news-notice__label',
                            value: props.attributes.label || 'Важно', placeholder: 'Важно', allowedFormats: [],
                            onChange: function (value) { props.setAttributes({ label: value }); }
                        }),
                        el(RichText, {
                            tagName: 'p', className: 'lv-news-notice__text', value: props.attributes.content,
                            placeholder: 'Добавьте важную информацию…',
                            allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                            onChange: function (value) { props.setAttributes({ content: value }); }
                        })
                    );
                },
                save: function (props) {
                    return el('aside', { className: 'lv-news-notice' },
                        el(RichText.Content, { tagName: 'strong', className: 'lv-news-notice__label', value: props.attributes.label || 'Важно' }),
                        el(RichText.Content, { tagName: 'p', className: 'lv-news-notice__text', value: props.attributes.content })
                    );
                }
            });
        }

        if (!wp.blocks.getBlockType('lv-news/source-note')) {
            registerBlockType('lv-news/source-note', {
                apiVersion: 3,
                title: 'Источник / примечание',
                icon: 'admin-links',
                category: 'lv-news',
                description: 'Небольшая служебная подпись для источника, пояснения или примечания.',
                keywords: ['источник', 'примечание', 'справка', 'подпись'],
                supports: { html: false, reusable: false },
                attributes: { content: { type: 'string', source: 'html', selector: '.lv-news-source-note' } },
                edit: function (props) {
                    return el(RichText, {
                        tagName: 'p', className: 'lv-news-source-note', value: props.attributes.content,
                        placeholder: 'Источник: …', allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                        onChange: function (value) { props.setAttributes({ content: value }); }
                    });
                },
                save: function (props) {
                    return el(RichText.Content, { tagName: 'p', className: 'lv-news-source-note', value: props.attributes.content });
                }
            });
        }

        if (!wp.blocks.getBlockType('lv-news/callout')) {
            registerBlockType('lv-news/callout', {
                apiVersion: 3,
                title: 'Врезка текста',
                icon: 'editor-quote',
                category: 'lv-news',
                description: 'Крупная редакционная врезка с акцентом.',
                keywords: ['врезка', 'цитата', 'акцент'],
                supports: { html: false, reusable: false },
                attributes: {
                    content: { type: 'string', source: 'html', selector: '.lv-news-callout__text' }
                },
                edit: function (props) {
                    return el(
                        'aside',
                        { className: 'lv-news-callout' },
                        el(RichText, {
                            tagName: 'p',
                            className: 'lv-news-callout__text',
                            value: props.attributes.content,
                            placeholder: 'Введите текст врезки…',
                            allowedFormats: ['core/bold', 'core/italic', 'core/link'],
                            onChange: function (value) { props.setAttributes({ content: value }); }
                        })
                    );
                },
                save: function (props) {
                    return el(
                        'aside',
                        { className: 'lv-news-callout' },
                        el(RichText.Content, { tagName: 'p', className: 'lv-news-callout__text', value: props.attributes.content })
                    );
                }
            });
        }

        if (!wp.blocks.getBlockType('lv-news/fact')) {
            registerBlockType('lv-news/fact', {
                apiVersion: 3,
                title: 'Выделенный факт',
                icon: 'info-outline',
                category: 'lv-news',
                keywords: ['факт', 'цифра', 'тезис', 'данные'],
                supports: { html: false, reusable: false },
                attributes: {
                    label: { type: 'string', source: 'html', selector: '.lv-news-fact__label' },
                    value: { type: 'string', source: 'html', selector: '.lv-news-fact__value' },
                    text: { type: 'string', source: 'html', selector: '.lv-news-fact__text' }
                },
                edit: function (props) {
                    return el('section', { className: 'lv-news-fact' },
                        el(RichText, { tagName: 'span', className: 'lv-news-fact__label', value: props.attributes.label, placeholder: 'Факт', allowedFormats: [], onChange: function (v) { props.setAttributes({ label: v }); } }),
                        el(RichText, { tagName: 'strong', className: 'lv-news-fact__value', value: props.attributes.value, placeholder: 'Крупное число или тезис', allowedFormats: [], onChange: function (v) { props.setAttributes({ value: v }); } }),
                        el(RichText, { tagName: 'p', className: 'lv-news-fact__text', value: props.attributes.text, placeholder: 'Короткое пояснение…', allowedFormats: ['core/bold', 'core/italic', 'core/link'], onChange: function (v) { props.setAttributes({ text: v }); } })
                    );
                },
                save: function (props) {
                    return el('section', { className: 'lv-news-fact' },
                        el(RichText.Content, { tagName: 'span', className: 'lv-news-fact__label', value: props.attributes.label }),
                        el(RichText.Content, { tagName: 'strong', className: 'lv-news-fact__value', value: props.attributes.value }),
                        el(RichText.Content, { tagName: 'p', className: 'lv-news-fact__text', value: props.attributes.text })
                    );
                }
            });
        }

        if (!wp.blocks.getBlockType('lv-news/cta')) {
            registerBlockType('lv-news/cta', {
                apiVersion: 3,
                title: 'Кнопка-ссылка',
                icon: 'admin-links',
                category: 'lv-news',
                keywords: ['кнопка', 'ссылка', 'cta', 'помочь'],
                supports: { html: false, reusable: false },
                attributes: {
                    text: { type: 'string', source: 'html', selector: '.lv-news-cta__button' },
                    url: { type: 'string', source: 'attribute', selector: '.lv-news-cta__button', attribute: 'href' }
                },
                edit: function (props) {
                    return el(Fragment, null,
                        InspectorControls ? el(InspectorControls, null,
                            el(PanelBody, { title: 'Ссылка', initialOpen: true },
                                URLInputButton ? el(URLInputButton, {
                                    url: props.attributes.url,
                                    onChange: function (url) { props.setAttributes({ url: url }); }
                                }) : el(TextControl, {
                                    label: 'URL',
                                    value: props.attributes.url || '',
                                    onChange: function (url) { props.setAttributes({ url: url }); }
                                })
                            )
                        ) : null,
                        el('div', { className: 'lv-news-cta' },
                            el(RichText, {
                                tagName: 'span',
                                className: 'lv-news-cta__button',
                                value: props.attributes.text,
                                placeholder: 'Текст кнопки',
                                allowedFormats: [],
                                onChange: function (text) { props.setAttributes({ text: text }); }
                            })
                        )
                    );
                },
                save: function (props) {
                    return el('div', { className: 'lv-news-cta' },
                        el(RichText.Content, {
                            tagName: 'a',
                            className: 'lv-news-cta__button',
                            href: props.attributes.url || '#',
                            value: props.attributes.text
                        })
                    );
                }
            });
        }
    }

    registerEditorialBlocks();

    /*
     * Старые core/heading остаются валидными для существующих материалов, но
     * новые через slash inserter больше не предлагаются. Если старый блок или
     * сторонний клиент выставит H1/H4/H5/H6, оставляем только H2/H3.
     */
    (function enforceHeadingLevels() {
        if (!subscribe || !wp.data.select || !wp.data.dispatch) {
            return;
        }

        var updating = false;
        var noticeShown = false;

        function walk(blocks, found) {
            (blocks || []).forEach(function (block) {
                if (block && block.name === 'core/heading') {
                    var level = parseInt((block.attributes && block.attributes.level) || 2, 10);
                    if (level !== 2 && level !== 3) {
                        found.push({ clientId: block.clientId, level: level <= 2 ? 2 : 3 });
                    }
                }
                if (block && block.innerBlocks && block.innerBlocks.length) {
                    walk(block.innerBlocks, found);
                }
            });
        }

        subscribe(function () {
            if (updating) {
                return;
            }
            var store = wp.data.select('core/block-editor');
            var actions = wp.data.dispatch('core/block-editor');
            if (!store || !actions || typeof store.getBlocks !== 'function' || typeof actions.updateBlockAttributes !== 'function') {
                return;
            }

            var targets = [];
            walk(store.getBlocks(), targets);
            if (!targets.length) {
                return;
            }

            updating = true;
            targets.forEach(function (item) {
                actions.updateBlockAttributes(item.clientId, { level: item.level });
            });
            updating = false;

            if (!noticeShown) {
                noticeShown = true;
                notify('info', 'В тексте новости разрешены только H2 и H3. Уровень заголовка исправлен автоматически.');
                window.setTimeout(function () { noticeShown = false; }, 2500);
            }
        });
    }());


    (function enforceAllowedBlockSettings() {
        var actions = wp.data.dispatch('core/block-editor');
        if (actions && typeof actions.updateSettings === 'function' && Array.isArray(config.allowedBlocks)) {
            actions.updateSettings({ allowedBlockTypes: config.allowedBlocks });
        }
    }());

