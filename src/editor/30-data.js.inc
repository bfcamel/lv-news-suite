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

