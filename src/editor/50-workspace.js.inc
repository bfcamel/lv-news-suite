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
