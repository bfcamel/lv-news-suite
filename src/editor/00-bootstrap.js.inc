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

