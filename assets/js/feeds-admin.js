(function () {
    'use strict';

    var config = window.dwdcFeeds || {};
    var i18n = config.i18n || {};

    document.addEventListener('click', function (event) {
        var link = event.target.closest ? event.target.closest('.dwdc-delete') : null;
        if (link && !window.confirm(link.getAttribute('data-confirm') || '')) {
            event.preventDefault();
        }
    });

    var input = document.getElementById('dwdc-feed-path');
    var open = document.querySelector('.dwdc-pick-open');
    var panel = document.getElementById('dwdc-picker');
    if (!input || !open || !panel || !window.wp || !window.wp.apiFetch) {
        return;
    }

    var current = '';
    var cursor = '';
    var listEl = null;
    var moreEl = null;
    var infoEl = null;
    var images = 0;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function button(label, onClick, className) {
        var b = el('button', className || 'button', label);
        b.type = 'button';
        b.addEventListener('click', onClick);
        return b;
    }

    function request(path, after) {
        var query = after ? '&cursor=' + encodeURIComponent(after) : '';
        var url = '/dwdc/v1/files?path=' + encodeURIComponent(path) + query;
        return window.wp.apiFetch({ path: url });
    }

    function crumbs() {
        var bar = el('div', 'dwdc-picker-bar');
        var parts = current === '' ? [] : current.split('/').filter(Boolean);
        var trail = el('div', 'dwdc-picker-trail');
        trail.appendChild(button(i18n.root || 'Dropbox', function () { go(''); }, 'button-link'));
        var built = '';
        parts.forEach(function (part) {
            built += '/' + part;
            var target = built;
            trail.appendChild(el('span', 'dwdc-picker-sep', '/'));
            trail.appendChild(button(part, function () { go(target); }, 'button-link'));
        });
        bar.appendChild(trail);
        var use = button(i18n.use || 'Usar esta pasta', function () {
            input.value = current;
            panel.hidden = true;
        }, 'button button-primary');
        use.disabled = current === '';
        bar.appendChild(use);
        return bar;
    }

    function fill(data, append) {
        var entries = (data && data.entries) || [];
        entries.forEach(function (entry) {
            if (entry.tag === 'folder') {
                var item = el('li');
                item.appendChild(button(entry.name, function () { go(entry.path_display || entry.path); }, 'button-link dwdc-picker-folder'));
                listEl.appendChild(item);
            } else if (entry.thumb) {
                images += 1;
            }
        });
        cursor = data && data.has_more ? data.cursor : '';
        moreEl.hidden = !cursor;
        infoEl.textContent = (i18n.images || '%d imagens nesta pasta.').replace('%d', String(images)) + (cursor ? ' ' + (i18n.partial || '') : '');
        if (!append && !listEl.children.length) {
            listEl.appendChild(el('li', 'dwdc-note', i18n.noFolders || 'Nenhuma subpasta.'));
        }
    }

    function fail() {
        panel.textContent = '';
        panel.appendChild(el('p', 'dwdc-note', i18n.failed || 'Não foi possível carregar o Dropbox.'));
    }

    function go(path) {
        current = path;
        cursor = '';
        images = 0;
        panel.hidden = false;
        panel.textContent = '';
        panel.appendChild(el('p', 'dwdc-note', i18n.loading || 'Carregando…'));
        request(path, '').then(function (data) {
            panel.textContent = '';
            panel.appendChild(crumbs());
            infoEl = el('p', 'dwdc-note');
            listEl = el('ul', 'dwdc-picker-list');
            moreEl = button(i18n.more || 'Carregar mais', function () {
                moreEl.disabled = true;
                request(current, cursor).then(function (next) {
                    moreEl.disabled = false;
                    fill(next, true);
                }).catch(function () {
                    moreEl.disabled = false;
                });
            });
            moreEl.hidden = true;
            panel.appendChild(infoEl);
            panel.appendChild(listEl);
            panel.appendChild(moreEl);
            fill(data, false);
        }).catch(fail);
    }

    open.addEventListener('click', function () {
        if (!panel.hidden) {
            panel.hidden = true;
            return;
        }
        var start = input.value.trim();
        go(start.charAt(0) === '/' ? start : '');
    });
})();
