(function (wp, config) {
    'use strict';

    if (!wp || !config || !wp.plugins || !wp.element || !wp.data || !wp.components || !wp.blocks || !wp.blockEditor || !wp.editPost) {
        return;
    }

    var el = wp.element.createElement;
    var Fragment = wp.element.Fragment;
    var useEffect = wp.element.useEffect;
    var useMemo = wp.element.useMemo;
    var useState = wp.element.useState;
    var useSelect = wp.data.useSelect;
    var useDispatch = wp.data.useDispatch;
    var subscribe = wp.data.subscribe;
    var registerPlugin = wp.plugins.registerPlugin;
    var registerBlockType = wp.blocks.registerBlockType;
    var createBlock = wp.blocks.createBlock;

    var Button = wp.components.Button;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var SelectControl = wp.components.SelectControl;
    var ToggleControl = wp.components.ToggleControl;
    var RangeControl = wp.components.RangeControl;
    var PanelBody = wp.components.PanelBody;
    var Notice = wp.components.Notice;
    var Modal = wp.components.Modal;
    var Spinner = wp.components.Spinner;
    var TabPanel = wp.components.TabPanel;

    var MediaUpload = wp.blockEditor.MediaUpload;
    var MediaUploadCheck = wp.blockEditor.MediaUploadCheck;
    var RichText = wp.blockEditor.RichText;
    var InspectorControls = wp.blockEditor.InspectorControls;
    var URLInputButton = wp.blockEditor.URLInputButton;

    var PluginSidebar = wp.editPost.PluginSidebar;
    var PluginSidebarMoreMenuItem = wp.editPost.PluginSidebarMoreMenuItem;
    var PluginDocumentSettingPanel = wp.editPost.PluginDocumentSettingPanel;
    var PluginPrePublishPanel = wp.editPost.PluginPrePublishPanel;

    var apiFetch = wp.apiFetch;

    function getMetaValue(meta, key, fallback) {
        if (!meta || typeof meta[key] === 'undefined' || meta[key] === null || meta[key] === '') {
            return fallback;
        }
        return meta[key];
    }

    function textFromHtml(value, removeHeadings) {
        if (!value) {
            return '';
        }
        var node = document.createElement('div');
        node.innerHTML = String(value);
        if (removeHeadings) {
            Array.prototype.forEach.call(node.querySelectorAll('h1,h2,h3,h4,h5,h6'), function (heading) {
                heading.remove();
            });
        }
        return (node.textContent || '').replace(/\u00a0/g, ' ').replace(/\s+/g, ' ').trim();
    }

    function countWords(text) {
        var clean = String(text || '').trim();
        return clean ? clean.split(/\s+/u).filter(Boolean).length : 0;
    }

    function transliterate(value) {
        var map = {
            'А':'A','Б':'B','В':'V','Г':'G','Д':'D','Е':'E','Ё':'E','Ж':'Zh','З':'Z','И':'I','Й':'Y','К':'K','Л':'L','М':'M','Н':'N','О':'O','П':'P','Р':'R','С':'S','Т':'T','У':'U','Ф':'F','Х':'Kh','Ц':'Ts','Ч':'Ch','Ш':'Sh','Щ':'Shch','Ъ':'','Ы':'Y','Ь':'','Э':'E','Ю':'Yu','Я':'Ya',
            'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'y','к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f','х':'kh','ц':'ts','ч':'ch','ш':'sh','щ':'shch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya'
        };
        return String(value || '').split('').map(function (char) {
            return Object.prototype.hasOwnProperty.call(map, char) ? map[char] : char;
        }).join('').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
    }

    function notify(status, message) {
        var notices = wp.data.dispatch('core/notices');
        if (notices && notices.createNotice) {
            notices.createNotice(status, message, { type: 'snackbar', isDismissible: true });
        }
    }

    function normalizeForSearch(value) {
        return String(value || '')
            .toLocaleLowerCase('ru-RU')
            .replace(/[«»„“”"'()\[\]{}.,!?;:—–-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    function focusKeywordFromTitle(title) {
        var clean = String(title || '').replace(/\s+/g, ' ').trim();
        if (!clean) {
            return '';
        }

        var parts = clean.split(/\s*[:—–]\s*/, 2);
        var candidate = clean;
        if (parts.length > 1) {
            var firstPartWords = parts[0].trim().split(/\s+/).filter(Boolean);
            if (firstPartWords.length >= 2 && firstPartWords.length <= 5) {
                candidate = parts[0].trim();
            }
        }

        var words = candidate.split(/\s+/).filter(Boolean);
        var leadingStopwords = {
            'мы': true, 'нам': true, 'нас': true, 'наш': true, 'наша': true, 'наше': true, 'наши': true,
            'я': true, 'мне': true, 'мой': true, 'моя': true, 'это': true, 'этот': true, 'эта': true, 'эти': true,
            'и': true, 'а': true, 'но': true, 'в': true, 'во': true, 'на': true, 'о': true, 'об': true,
            'для': true, 'к': true, 'по': true, 'из': true
        };

        while (words.length > 2) {
            var first = normalizeForSearch(words[0]);
            if (!leadingStopwords[first]) {
                break;
            }
            words.shift();
        }

        return words.slice(0, 5).join(' ').trim();
    }

    function ensureFocusInDescription(description, focus, limit) {
        var clean = String(description || '').replace(/\s+/g, ' ').trim();
        var phrase = String(focus || '').replace(/\s+/g, ' ').trim();
        var max = parseInt(limit || 158, 10);

        if (!phrase || normalizeForSearch(clean).indexOf(normalizeForSearch(phrase)) !== -1) {
            return clean;
        }

        var prefix = phrase.charAt(0).toLocaleUpperCase('ru-RU') + phrase.slice(1) + '. ';
        var combined = prefix + clean;
        if (combined.length <= max) {
            return combined;
        }

        var cut = combined.slice(0, Math.max(1, max - 1)).replace(/\s+\S*$/, '').replace(/[\s,.;:—-]+$/, '');
        return cut + '…';
    }

    function currentEditedMeta() {
        var editor = wp.data.select('core/editor');
        return editor && editor.getEditedPostAttribute ? (editor.getEditedPostAttribute('meta') || {}) : {};
    }

    function currentEditedContent() {
        var editor = wp.data.select('core/editor');
        return editor && editor.getEditedPostContent ? (editor.getEditedPostContent() || '') : '';
    }

    /*
     * Rank Math применяет этот фильтр и при анализе, и при отправке своего
     * отдельного REST-запроса updateMeta. Фильтра rank_math_title в его
     * JavaScript API нет: SEO title обновляется через store `rank-math` ниже.
     */
    function installRankMathAnalysisBridge() {
        if (!config.rankMath || !config.rankMath.active || !wp.hooks || typeof wp.hooks.addFilter !== 'function') {
            return;
        }

        wp.hooks.addFilter('rank_math_content', 'lv-news-suite/content', function (existing) {
            var content = currentEditedContent();
            return content || existing || '';
        });
    }

    installRankMathAnalysisBridge();

    var rankMathPendingFields = {};
    var rankMathRuntimeStarted = Date.now();
    var rankMathRuntimeTimer = null;
    var rankMathMirrorUnsubscribe = null;
    var rankMathMirrorValues = null;
    var rankMathRuntimeListeners = [];
    var rankMathRuntimeState = {
        status: config.rankMath && config.rankMath.active ? 'loading' : 'inactive',
        storeReady: false,
        editorReady: false
    };

    function rankMathString(value) {
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function getRankMathRuntime() {
        var selectors = null;
        var actions = null;

        if (!config.rankMath || !config.rankMath.active) {
            return { selectors: null, actions: null, editor: null, storeReady: false, editorReady: false };
        }

        try {
            selectors = wp.data.select('rank-math');
            if (selectors && typeof selectors.getTitle === 'function') {
                actions = wp.data.dispatch('rank-math');
            }
        } catch (e) {
            selectors = null;
            actions = null;
        }

        var editor = window.rankMathEditor || null;
        return {
            selectors: selectors,
            actions: actions,
            editor: editor,
            storeReady: !!(selectors && actions && typeof actions.updateTitle === 'function'),
            editorReady: !!(editor && typeof editor.refresh === 'function')
        };
    }

    function readRankMathFields(selectors) {
        var result = {};
        var fields = config.rankMath && config.rankMath.fields ? config.rankMath.fields : {};

        Object.keys(fields).forEach(function (name) {
            var selectorName = fields[name].selector;
            if (selectors && selectorName && typeof selectors[selectorName] === 'function') {
                try {
                    result[name] = rankMathString(selectors[selectorName]());
                } catch (e) {
                    // A failed read is not a request to clear saved SEO.
                    return;
                }
            }
        });

        return result;
    }

    function emitRankMathRuntimeState(nextState) {
        var changed = nextState.status !== rankMathRuntimeState.status
            || nextState.storeReady !== rankMathRuntimeState.storeReady
            || nextState.editorReady !== rankMathRuntimeState.editorReady;

        rankMathRuntimeState = nextState;
        if (!changed) {
            return;
        }

        rankMathRuntimeListeners.slice().forEach(function (listener) {
            listener(rankMathRuntimeState);
        });
    }

    function subscribeRankMathRuntime(listener) {
        rankMathRuntimeListeners.push(listener);
        listener(rankMathRuntimeState);
        return function () {
            rankMathRuntimeListeners = rankMathRuntimeListeners.filter(function (item) {
                return item !== listener;
            });
        };
    }

    // Rank Math owns persistence. Its database hooks maintain local revision mirrors.
    // Never edit core/editor meta here: doing so creates a second independent writer.
    function startRankMathMirror(runtime) {
        if (rankMathMirrorUnsubscribe || !runtime.storeReady || !subscribe) { return; }
        rankMathMirrorValues = readRankMathFields(runtime.selectors);
        rankMathMirrorUnsubscribe = subscribe(function () {
            var next = readRankMathFields(getRankMathRuntime().selectors);
            if (JSON.stringify(next) !== JSON.stringify(rankMathMirrorValues)) {
                rankMathMirrorValues = next;
                seoEditGeneration++;
                setSeoSaveState('dirty');
            }
        });
    }

    var seoSaveState = 'idle';
    var seoSaveListeners = [];
    var seoBaseline = Object.assign({}, config.rankMath && config.rankMath.baseline || {});
    var seoEditGeneration = 0;
    var failedSeoRequest = null;

    function setSeoSaveState(state) {
        seoSaveState = state;
        seoSaveListeners.slice().forEach(function (listener) { listener(state); });
    }

    function useSeoSaveState() {
        var pair = useState(seoSaveState);
        useEffect(function () {
            seoSaveListeners.push(pair[1]);
            return function () { seoSaveListeners = seoSaveListeners.filter(function (x) { return x !== pair[1]; }); };
        }, []);
        return pair[0];
    }

    if (apiFetch && typeof apiFetch.use === 'function' && config.rankMath && config.rankMath.active) {
        apiFetch.use(function (options, next) {
            var target = String(options.path || options.url || '');
            var data = options.data;
            if (/(?:^|\/)wp\/v2\/lv_news(?:[\/?]|$)/.test(target) && data && data.meta) {
                var localMeta = Object.assign({}, data.meta);
                Object.keys(config.rankMath.fields || {}).forEach(function (name) { delete localMeta[config.meta[name]]; });
                return next(Object.assign({}, options, { data: Object.assign({}, data, { meta: localMeta }) }));
            }
            if (!/(?:^|\/)rankmath\/v1\/updateMeta(?:[?\/]|$)/.test(target) || !data || data.objectType !== 'post' || Number(data.objectID) !== Number(wp.data.select('core/editor').getCurrentPostId())) {
                return next(options);
            }
            var generation = seoEditGeneration;
            var request = Object.assign({}, options, { data: Object.assign({}, data, { lv_news_seo_baseline: Object.assign({}, seoBaseline) }) });
            setSeoSaveState('saving');
            return Promise.resolve().then(function () { return next(request); }).then(function (result) {
                if (result && result.lv_news_seo_snapshot) { seoBaseline = result.lv_news_seo_snapshot; }
                else {
                    Object.keys(data.meta || {}).forEach(function (key) {
                        if (Object.prototype.hasOwnProperty.call(seoBaseline, key)) { seoBaseline[key] = rankMathString(data.meta[key]); }
                    });
                }
                failedSeoRequest = null;
                setSeoSaveState(generation === seoEditGeneration ? 'saved' : 'dirty');
                return result;
            }).catch(function (error) {
                failedSeoRequest = options;
                setSeoSaveState(error && error.code === 'lv_news_seo_conflict' ? 'conflict' : 'error');
                notify('error', error && error.message || 'SEO не сохранено. Повторите сохранение.');
                throw error;
            });
        });
    }

    function retrySeoSave() {
        if (!failedSeoRequest || seoSaveState === 'conflict') { return; }
        // Retry current values, not the obsolete content of a failed request.
        var request = Object.assign({}, failedSeoRequest);
        var meta = Object.assign({}, request.data.meta);
        var values = readRankMathFields(getRankMathRuntime().selectors);
        Object.keys(values).forEach(function (name) { meta[config.rankMath.fields[name].meta] = values[name]; });
        request.data = Object.assign({}, request.data, { meta: meta });
        apiFetch(request).catch(function () {});
    }

    function syncRankMathField(name, value) {
        if (!config.rankMath || !config.rankMath.active || !config.rankMath.fields || !config.rankMath.fields[name]) {
            return false;
        }

        var localKey = config.meta && config.meta[name];
        if (!config.seoPermissions || config.seoPermissions[localKey] !== true) { return false; }
        var normalized = rankMathString(value);
        rankMathPendingFields[name] = normalized;
        var runtime = getRankMathRuntime();
        if (!runtime.storeReady || !runtime.editorReady) {
            probeRankMathRuntime();
            return false;
        }

        var actionNames = config.rankMath.fields[name].actions || [];
        try {
            var dispatched = false;
            // Rank Math uses separate preview and persisted-value actions. Both are required.
            actionNames.forEach(function (actionName) {
                if (typeof runtime.actions[actionName] === 'function') {
                    runtime.actions[actionName](normalized);
                    dispatched = true;
                }
            });
            if (!dispatched) {
                return false;
            }
            delete rankMathPendingFields[name];
            return true;
        } catch (e) {
            return false;
        }
    }

    function syncRankMathFields(values) {
        var synced = true;
        Object.keys(values || {}).forEach(function (name) {
            if (!syncRankMathField(name, values[name])) {
                synced = false;
            }
        });
        return synced;
    }

    function flushPendingRankMathFields() {
        var pending = Object.assign({}, rankMathPendingFields);
        if (Object.keys(pending).length) {
            syncRankMathFields(pending);
        }
    }

    function probeRankMathRuntime() {
        var runtime = getRankMathRuntime();
        var elapsed = Date.now() - rankMathRuntimeStarted;
        var status = 'loading';

        if (!config.rankMath || !config.rankMath.active) {
            status = 'inactive';
        } else if (runtime.storeReady && runtime.editorReady) {
            status = 'ready';
        } else if (runtime.storeReady && elapsed >= 12000) {
            status = 'partial';
        } else if (config.rankMath.seoControls === false) {
            status = 'disabled';
        } else if (config.rankMath.userAccess === false) {
            status = 'denied';
        } else if (elapsed >= 12000) {
            status = 'unavailable';
        }

        var wasReady = rankMathRuntimeState.status === 'ready';
        emitRankMathRuntimeState({
            status: status,
            storeReady: runtime.storeReady,
            editorReady: runtime.editorReady
        });

        if (runtime.storeReady && runtime.editorReady) {
            startRankMathMirror(runtime);
        }
        if (status === 'ready') {
            flushPendingRankMathFields();
            if (!wasReady) {
                window.setTimeout(function () { refreshRankMath('content'); }, 80);
            }
        }

        return runtime;
    }

    if (config.rankMath && config.rankMath.active) {
        if (wp.hooks && typeof wp.hooks.addAction === 'function') {
            wp.hooks.addAction('rank_math_loaded', 'lv-news-suite/runtime', function () {
                window.setTimeout(probeRankMathRuntime, 0);
            }, 20);
        }

        probeRankMathRuntime();
        rankMathRuntimeTimer = window.setInterval(function () {
            var runtime = probeRankMathRuntime();
            if ((runtime.storeReady && runtime.editorReady) || Date.now() - rankMathRuntimeStarted >= 15000) {
                window.clearInterval(rankMathRuntimeTimer);
                rankMathRuntimeTimer = null;
            }
        }, 400);
    }

    var rankMathWasSaving = false;
    if (subscribe && config.rankMath && config.rankMath.active) {
        subscribe(function () {
            var editor = wp.data.select('core/editor');
            if (!editor || typeof editor.isSavingPost !== 'function') {
                return;
            }
            var saving = !!editor.isSavingPost();
            if (rankMathWasSaving && !saving) {
                window.setTimeout(function () { refreshRankMath('content'); }, 250);
            }
            rankMathWasSaving = saving;
        });
    }

    var rankMathRefreshTimer = null;
    function refreshRankMath(source, reportResult) {
        if (!config.rankMath || !config.rankMath.active) {
            if (reportResult) {
                notify('warning', 'Rank Math не активен. SEO продолжает работать через LV News Suite.');
            }
            return false;
        }

        var initialRuntime = probeRankMathRuntime();
        window.clearTimeout(rankMathRefreshTimer);
        rankMathRefreshTimer = window.setTimeout(function () {
            var runtime = getRankMathRuntime();
            var refreshed = false;
            if (runtime.editorReady) {
                try {
                    var targets = source === 'title'
                        ? ['title']
                        : (source === 'description' ? ['description'] : (source === 'keyword' ? ['keyword'] : ['content', 'title', 'description', 'keyword']));
                    targets.forEach(function (target) {
                        runtime.editor.refresh(target);
                    });
                    if (runtime.actions && typeof runtime.actions.refreshResults === 'function') {
                        runtime.actions.refreshResults();
                    }
                    refreshed = true;
                } catch (e) {
                    // Rank Math is optional; never break the news editor.
                }
            }
            if (reportResult) {
                notify(
                    refreshed ? 'success' : 'warning',
                    refreshed
                        ? 'Анализ Rank Math обновлён по текущему заголовку и тексту.'
                        : (rankMathRuntimeState.status === 'disabled'
                            ? 'SEO Controls для «Новости» выключены в Rank Math.'
                            : (rankMathRuntimeState.status === 'denied'
                                ? 'У текущего пользователя нет прав на редактор Rank Math.'
                                : 'Rank Math активен, но его редактор ещё не готов. Обновите страницу, если состояние не изменится.'))
                );
            }
        }, 180);
        return initialRuntime.editorReady;
    }

    /*
     * Кнопки в боковой панели находятся вне iframe редактора. Поэтому вызов
     * insertBlocks() без index/rootClientId воспринимается Gutenberg как
     * «добавить в конец документа». Запоминаем последнюю контентную позицию и
     * всегда вставляем относительно выбранного блока. Пустой абзац заменяем —
     * это повторяет поведение стандартного slash-inserter.
     */
    var lastContentSelection = null;

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

    function useNewsData() {
        var runtimePair = useState(rankMathRuntimeState);
        useEffect(function () { return subscribeRankMathRuntime(runtimePair[1]); }, []);
        return useSelect(function (select) {
            var editor = select('core/editor');
            var core = select('core');
            var featuredId = parseInt(editor.getEditedPostAttribute('featured_media') || 0, 10);
            var media = null;
            if (featuredId && core) {
                if (typeof core.getMedia === 'function') {
                    media = core.getMedia(featuredId);
                } else if (typeof core.getEntityRecord === 'function') {
                    media = core.getEntityRecord('postType', 'attachment', featuredId);
                }
            }
            var authorId = parseInt(editor.getEditedPostAttribute('author') || 0, 10);
            var author = authorId && core && core.getUser ? core.getUser(authorId) : null;
            var previewLink = typeof editor.getEditedPostPreviewLink === 'function' ? (editor.getEditedPostPreviewLink() || '') : '';
            var content = typeof editor.getEditedPostContent === 'function' ? (editor.getEditedPostContent() || '') : '';
            var meta = Object.assign({}, editor.getEditedPostAttribute('meta') || {});
            if (config.rankMath && config.rankMath.active) {
                var rankSelectors = null;
                try { rankSelectors = select('rank-math'); } catch (error) {}
                // Use the tracked select supplied by useSelect so native SEO edits rerender fields.
                var rankValues = rankSelectors ? readRankMathFields(rankSelectors) : {};
                Object.keys(config.rankMath.fields || {}).forEach(function (name) {
                    var rankKey = config.rankMath.fields[name].meta;
                    meta[config.meta[name]] = Object.prototype.hasOwnProperty.call(rankValues, name) ? rankValues[name] : (seoBaseline[rankKey] || '');
                });
            }
            return {
                postId: editor.getCurrentPostId(),
                title: editor.getEditedPostAttribute('title') || '',
                excerpt: editor.getEditedPostAttribute('excerpt') || '',
                slug: editor.getEditedPostAttribute('slug') || '',
                status: editor.getEditedPostAttribute('status') || 'draft',
                content: content,
                featuredId: featuredId,
                media: media,
                author: author,
                authorId: authorId,
                termIds: editor.getEditedPostAttribute(config.taxonomy) || [],
                meta: meta,
                previewLink: previewLink,
                isSaving: !!editor.isSavingPost(),
                isAutosaving: typeof editor.isAutosavingPost === 'function' ? !!editor.isAutosavingPost() : false,
                isDirty: typeof editor.isEditedPostDirty === 'function' ? !!editor.isEditedPostDirty() : false
            };
        }, [runtimePair[0].status]);
    }

    function evaluate(data) {
        var title = textFromHtml(data.title, false);
        var excerpt = textFromHtml(data.excerpt, false);
        var body = textFromHtml(data.content, false);
        var words = countWords(body);
        var termIds = Array.isArray(data.termIds) ? data.termIds : [];
        var alt = data.media ? String(data.media.alt_text || '').trim() : '';
        var details = data.media && data.media.media_details ? data.media.media_details : {};
        var imageWidth = parseInt(details.width || (data.media && data.media.width) || 0, 10);
        var imageHeight = parseInt(details.height || (data.media && data.media.height) || 0, 10);
        var htmlNode = document.createElement('div');
        htmlNode.innerHTML = data.content || '';
        var anchors = Array.prototype.slice.call(htmlNode.querySelectorAll('a'));
        var linksReady = anchors.every(function (anchor) {
            var href = String(anchor.getAttribute('href') || '').trim();
            return href !== '' && href !== '#' && !/^javascript:/i.test(href);
        });
        var required = [
            { key: 'title', label: 'Заголовок', ready: title.length > 0, hint: 'Введите заголовок.' },
            { key: 'excerpt', label: 'Краткий анонс', ready: excerpt.length > 0, hint: 'Он используется в карточках и SEO.' },
            { key: 'content', label: 'Основной текст', ready: body.length > 0, hint: 'Добавьте хотя бы один содержательный абзац.' },
            { key: 'image', label: 'Главное изображение', ready: data.featuredId > 0, hint: 'Выберите фотографию.' },
            { key: 'type', label: 'Тип новости', ready: termIds.length > 0, hint: 'Выберите один тип новости.' }
        ];
        var recommended = [
            { key: 'title-length', label: 'Заголовок 15–110 знаков', ready: title.length >= 15 && title.length <= 110, hint: 'Сейчас: ' + title.length + '.' },
            { key: 'excerpt-length', label: 'Анонс 100–260 знаков', ready: excerpt.length >= 100 && excerpt.length <= 260, hint: 'Сейчас: ' + excerpt.length + '.' },
            { key: 'content-length', label: 'Текст не короче 300 знаков', ready: body.length >= 300, hint: 'Сейчас: ' + body.length + '.' },
            { key: 'alt', label: 'У изображения заполнен alt', ready: data.featuredId > 0 && alt.length > 0, hint: 'Добавьте описание изображения.' },
            { key: 'image-size', label: 'Главное изображение достаточно крупное', ready: data.featuredId > 0 && (imageWidth === 0 || (imageWidth >= 1200 && imageHeight >= 700)), hint: imageWidth > 0 ? ('Сейчас: ' + imageWidth + ' × ' + imageHeight + ' px.') : 'Рекомендуем 1200 × 860 px или больше.' },
            { key: 'links', label: 'Ссылки заполнены корректно', ready: linksReady, hint: 'У одной из ссылок пустой или служебный адрес.' },
            { key: 'heading-levels', label: 'Заголовки текста только H2/H3', ready: !/<h(?:1|4|5|6)(?:\s|>)/i.test(data.content), hint: 'H1 зарезервирован под заголовок страницы; H4–H6 в новостях не используются.' }
        ];
        var allChecks = required.concat(recommended);
        var done = allChecks.filter(function (x) { return x.ready; }).length;
        var missingRequired = required.filter(function (x) { return !x.ready; });
        var missingRecommended = recommended.filter(function (x) { return !x.ready; });
        return {
            title: title,
            excerpt: excerpt,
            body: body,
            words: words,
            minutes: Math.max(1, Math.ceil(words / 180)),
            required: required,
            recommended: recommended,
            missingRequired: missingRequired,
            missingRecommended: missingRecommended,
            missing: missingRequired.concat(missingRecommended),
            canPublish: missingRequired.length === 0,
            quality: Math.round((done / allChecks.length) * 100),
            alt: alt
        };
    }

    function updateMeta(editorActions, data, patch) {
        var meta = Object.assign({}, currentEditedMeta(), patch || {});
        if (config.rankMath && config.rankMath.active) {
            Object.keys(config.rankMath.fields || {}).forEach(function (name) { delete meta[config.meta[name]]; });
        }
        if (!config.canPublish && config.meta && config.meta.featured) {
            delete meta[config.meta.featured];
        }
        editorActions.editPost({ meta: meta });
    }

    function imageCropStyle(data) {
        var x = Math.max(0, Math.min(100, parseInt(getMetaValue(data.meta, config.meta.focusX, 50), 10) || 0));
        var y = Math.max(0, Math.min(100, parseInt(getMetaValue(data.meta, config.meta.focusY, 50), 10) || 0));
        var zoom = Math.max(100, Math.min(180, parseInt(getMetaValue(data.meta, config.meta.focusZoom, 100), 10) || 100));
        return {
            '--lv-news-focus-x': x + '%',
            '--lv-news-focus-y': y + '%',
            '--lv-news-focus-scale': zoom / 100,
            objectPosition: x + '% ' + y + '%',
            transform: 'scale(' + (zoom / 100) + ')',
            transformOrigin: x + '% ' + y + '%'
        };
    }

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

    function Sidebar(props) {
        var data = props.data;
        var evaluation = props.evaluation;

        useEffect(function () {
            if (config.rankMath && config.rankMath.active) {
                refreshRankMath('content');
            }
        }, [data.title, data.excerpt, data.content]);

        return el(Fragment, null,
            PluginSidebarMoreMenuItem ? el(PluginSidebarMoreMenuItem, { target: 'lv-news-editor-sidebar', icon: 'edit' }, 'Редактор новости') : null,
            el(PluginSidebar, { name: 'lv-news-editor-sidebar', title: 'Редактор новости', icon: 'edit' },
                el('div', { className: 'lvn-sidebar' },
                    el('p', { className: 'lvn-subheading' }, 'Материал → Фото → Проверка → Предпросмотр → Публикация'),
                    el(SaveStatus, { data: data }),
                    el(Progress, { evaluation: evaluation }),
                    el(PanelBody, { title: '1. Материал', initialOpen: true }, el(BasicPanel, { data: data, evaluation: evaluation })),
                    el(PanelBody, { title: '2. Фото', initialOpen: false }, el(ImagePanel, { data: data })),
                    el(PanelBody, { title: 'Главная новость', initialOpen: false }, el(FeaturedPanel, { data: data })),
                    el(PanelBody, { title: 'Структура материала', initialOpen: false }, el(TemplatePanel, { data: data })),
                    el(PanelBody, { title: 'Быстро добавить', initialOpen: false }, el(QuickBlocks)),
                    el(PanelBody, { title: 'SEO и соцсети', initialOpen: false }, el(SeoPanel, { data: data })),
                    el(PanelBody, { title: '3. Проверка перед публикацией', initialOpen: false },
                        el('p', { className: 'lvn-subheading' }, 'Обязательно'),
                        el(CheckList, { items: evaluation.required }),
                        el('p', { className: 'lvn-subheading' }, 'Рекомендации'),
                        el(CheckList, { items: evaluation.recommended })
                    ),
                    el(PanelBody, { title: '4. Предпросмотр', initialOpen: false }, el(PreviewPanel, { data: data, evaluation: evaluation }))
                )
            )
        );
    }

    function seoStatusLabel(state) {
        if (!config.rankMath || !config.rankMath.active) { return ''; }
        return { idle: 'SEO: нет новых правок.', dirty: 'Есть несохранённые SEO-правки.', saving: 'SEO сохраняется…', saved: 'SEO сохранено.', error: 'Ошибка сохранения SEO.', conflict: 'SEO изменено в другом окне. Скопируйте правки и обновите страницу.' }[state] || '';
    }

    function SaveStatus(props) {
        var state = useSeoSaveState();
        var data = props.data;
        return el('div', { className: 'lvn-save-state', role: 'status' },
            data.isSaving ? 'Материал сохраняется…' : (data.isDirty ? 'Есть несохранённые изменения материала.' : 'Материал сохранён.'),
            ' ', seoStatusLabel(state)
        );
    }

    function PrePublish(props) {
        var evaluation = props.evaluation;
        if (!PluginPrePublishPanel) { return null; }
        return el(PluginPrePublishPanel, { title: evaluation.canPublish ? 'Новость готова к публикации' : 'Проверьте новость', initialOpen: !evaluation.canPublish },
            evaluation.canPublish ? el(Notice, { status: 'success', isDismissible: false }, 'Обязательные поля заполнены. Рекомендации ниже не блокируют публикацию.') : el(Notice, { status: 'warning', isDismissible: false }, 'Перед публикацией или планированием заполните обязательные поля. Уровни заголовков автоматически приводятся к H2/H3.'),
            el(CheckList, { items: evaluation.required.concat(evaluation.recommended) })
        );
    }

    function Workspace() {
        var data = useNewsData();
        var evaluation = useMemo(function () { return evaluate(data); }, [data.title, data.excerpt, data.content, data.featuredId, data.termIds, data.media]);
        var props = { data: data, evaluation: evaluation };
        return el(Fragment, null, el(Sidebar, props), el(PrePublish, props));
    }
    registerPlugin('lv-news-editor-workspace', { render: Workspace });
}(window.wp, window.LVNewsEditorConfig));
