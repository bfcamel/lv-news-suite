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

