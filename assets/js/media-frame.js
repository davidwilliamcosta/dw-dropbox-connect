(function (wp, settings) {
    'use strict';

    if (!wp || !wp.media || !wp.media.view || !settings || window.dwdcMediaLoaded) {
        return;
    }
    window.dwdcMediaLoaded = true;

    var api = wp.apiFetch;
    if (api && api.createNonceMiddleware) {
        api.use(api.createNonceMiddleware(settings.nonce));
    }

    var i18n = settings.i18n || {};

    function root() {
        var value = settings.root || '';
        return value.charAt(value.length - 1) === '/' ? value : value + '/';
    }

    function request(path, query) {
        var params = new URLSearchParams();
        Object.keys(query || {}).forEach(function (key) {
            if (query[key] !== undefined && query[key] !== null && query[key] !== '') {
                params.append(key, query[key]);
            }
        });
        var qs = params.toString();
        return api({ url: root() + path + (qs ? '?' + qs : '') });
    }

    function errorText(error, fallback) {
        var message = error && error.message ? String(error.message) : '';
        var holder = document.createElement('div');
        holder.innerHTML = message;
        var text = (holder.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text || /erro cr[ií]tico|critical error/i.test(text)) {
            return fallback || text;
        }
        return text;
    }

    function fill(template, first, second) {
        return String(template || '')
            .replace('%1', first === undefined ? '' : String(first))
            .replace('%2', second === undefined ? '' : String(second));
    }

    function resolveFrame(candidate) {
        if (candidate && typeof candidate.state === 'function' && candidate.toolbar) {
            return candidate;
        }
        var model = candidate && candidate.models && candidate.models[0];
        if (model && model.frame && typeof model.frame.state === 'function') {
            return model.frame;
        }
        if (wp.media && wp.media.frame && typeof wp.media.frame.state === 'function') {
            return wp.media.frame;
        }
        return null;
    }

    function allowsMultiple(controller) {
        controller = resolveFrame(controller) || controller;
        var state = controller && controller.state ? controller.state() : null;
        var value = state && state.get ? state.get('multiple') : null;
        if (value === true || value === 'add' || value === 'reset') {
            return true;
        }
        if (value === false) {
            return false;
        }
        return !!(controller && controller.options && controller.options.multiple);
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    var Browser = wp.media.View.extend({
        tagName: 'div',
        className: 'dwdc-browser',
        dwdcBrowser: true,
        events: {
            'click .dwdc-up': 'onUp',
            'click .dwdc-search-go': 'onSearch',
            'keydown .dwdc-search': 'onSearchKey',
            'click .dwdc-clear': 'onClearSearch',
            'click .dwdc-more': 'onMore',
            'click .dwdc-folder': 'onFolder',
            'click .dwdc-import': 'onImport',
            'click .dwdc-crumb': 'onCrumb',
            'click .dwdc-refresh': 'onRefresh',
            'change .dwdc-pick': 'onPick',
            'change .dwdc-pick-all': 'onPickAll',
            'change .dwdc-keep': 'onKeep',
            'click .dwdc-import-selected': 'onBulk',
            'click .dwdc-send': 'onSend'
        },
        initialize: function () {
            this.controller = this.options.controller;
            this.path = '';
            this.displayPath = '';
            this.cursor = '';
            this.mode = 'list';
            this.query = '';
            this.requestId = 0;
            this.busy = false;
        },
        render: function () {
            var self = this;
            if (this.built) {
                return this;
            }
            this.built = true;
            this.el.innerHTML = '';

            var bar = el('div', 'dwdc-toolbar');
            var nav = el('div', 'dwdc-navline');
            this.upButton = el('button', 'button dwdc-up', i18n.up || 'Back');
            this.upButton.type = 'button';
            this.refreshButton = el('button', 'button dwdc-refresh', i18n.refresh || 'Refresh');
            this.refreshButton.type = 'button';
            this.crumbs = el('div', 'dwdc-crumbs');
            nav.appendChild(this.upButton);
            nav.appendChild(this.refreshButton);
            nav.appendChild(this.crumbs);

            var searchLine = el('div', 'dwdc-searchline');
            this.searchInput = el('input', 'dwdc-search');
            this.searchInput.type = 'search';
            this.searchInput.placeholder = i18n.search || '';
            this.searchInput.autocomplete = 'off';
            var go = el('button', 'button dwdc-search-go', i18n.searchAction || 'Search');
            go.type = 'button';
            this.clearButton = el('button', 'button dwdc-clear', i18n.clear || 'Clear');
            this.clearButton.type = 'button';
            this.clearButton.hidden = true;
            searchLine.appendChild(this.searchInput);
            searchLine.appendChild(go);
            searchLine.appendChild(this.clearButton);

            var bulk = el('div', 'dwdc-bulk');
            this.pickAll = el('input', 'dwdc-pick-all');
            this.pickAll.type = 'checkbox';
            var pickAllLabel = el('label', 'dwdc-checklabel dwdc-bulk-all');
            pickAllLabel.appendChild(this.pickAll);
            pickAllLabel.appendChild(document.createTextNode(i18n.selectAll || 'Select all'));
            this.bulkButton = el('button', 'button button-primary dwdc-import-selected', i18n.importSelected || 'Import selected');
            this.bulkButton.type = 'button';
            this.bulkButton.disabled = true;
            this.keepBox = el('input', 'dwdc-keep');
            this.keepBox.type = 'checkbox';
            this.keepBox.checked = true;
            var keepLabel = el('label', 'dwdc-checklabel dwdc-keep-label');
            keepLabel.appendChild(this.keepBox);
            keepLabel.appendChild(document.createTextNode(i18n.selectImported || 'Select imported files'));
            this.sendButton = el('button', 'button dwdc-send', i18n.sendSelection || 'Send selection');
            this.sendButton.type = 'button';
            this.sendButton.disabled = true;
            bulk.appendChild(keepLabel);
            bulk.appendChild(pickAllLabel);
            bulk.appendChild(this.bulkButton);
            bulk.appendChild(this.sendButton);

            bar.appendChild(nav);
            bar.appendChild(searchLine);
            bar.appendChild(bulk);

            this.grid = el('div', 'dwdc-grid');
            this.grid.setAttribute('aria-live', 'polite');
            this.moreButton = el('button', 'button dwdc-more', i18n.more || 'More');
            this.moreButton.type = 'button';
            this.moreButton.hidden = true;
            this.message = el('p', 'dwdc-message');
            this.el.appendChild(bar);
            this.el.appendChild(this.grid);
            this.el.appendChild(this.moreButton);
            this.el.appendChild(this.message);
            this.setMessage(i18n.loading || '');
            this.refreshSend();
            request('status').then(function (data) {
                if (!data || !data.connected) {
                    self.showDisconnected();
                    return;
                }
                self.load();
            }).catch(function (error) {
                self.setMessage(errorText(error, i18n.failed));
            });
            return this;
        },
        showDisconnected: function () {
            this.grid.innerHTML = '';
            this.moreButton.hidden = true;
            this.upButton.disabled = true;
            this.bulkButton.disabled = true;
            this.message.textContent = i18n.disconnected || '';
            if (settings.canManage && settings.settingsUrl) {
                var link = el('a', '', i18n.manage || '');
                link.href = settings.settingsUrl;
                this.message.appendChild(link);
            }
        },
        setMessage: function (text) {
            this.message.textContent = text || '';
        },
        wantsSelection: function () {
            return !!(this.keepBox && this.keepBox.checked);
        },
        currentSelection: function () {
            var frame = resolveFrame(this.controller);
            var state = frame && typeof frame.state === 'function' ? frame.state() : null;
            var selection = state && state.get ? state.get('selection') : null;
            if (selection && typeof selection.add === 'function') {
                return selection;
            }
            var toolbar = frame && frame.toolbar && typeof frame.toolbar.get === 'function' ? frame.toolbar.get() : null;
            if (toolbar && toolbar.selection && typeof toolbar.selection.add === 'function') {
                return toolbar.selection;
            }
            return null;
        },
        refreshToolbar: function () {
            var frame = resolveFrame(this.controller);
            var toolbar = frame && frame.toolbar && typeof frame.toolbar.get === 'function' ? frame.toolbar.get() : null;
            if (toolbar && typeof toolbar.refresh === 'function') {
                toolbar.refresh();
            }
        },
        forgetOthers: function (card) {
            if (allowsMultiple(this.controller) || !this.grid) {
                return;
            }
            Array.prototype.forEach.call(this.grid.querySelectorAll('.dwdc-item.is-chosen'), function (other) {
                if (other === card) {
                    return;
                }
                other.classList.remove('is-chosen');
                var button = other.querySelector('.dwdc-import');
                if (button && other.getAttribute('data-library-id')) {
                    button.textContent = i18n.already || '';
                    button.classList.add('is-library');
                    button.classList.remove('is-done', 'button-primary');
                }
            });
        },
        holds: function (selection, attachment) {
            if (!selection || !attachment) {
                return false;
            }
            var id = attachment.id || attachment.get('id');
            return !!(selection.get(attachment) || (id && selection.get(id)) || (id && selection.get(String(id))));
        },
        addToSelection: function (attachment) {
            var selection = this.currentSelection();
            if (!selection || typeof selection.add !== 'function' || !attachment) {
                return false;
            }
            var id = parseInt(attachment.get('id') || attachment.id, 10);
            if (id) {
                attachment.set('id', id);
            }
            var mime = attachment.get('mime') || attachment.get('mime_type') || '';
            if (!mime || mime.indexOf('image/') !== 0) {
                mime = 'image/jpeg';
            }
            attachment.set({
                type: 'image',
                mime: mime
            });
            var multiple = selection.multiple === true || selection.multiple === 'add';
            var filters = selection.filters;
            selection.filters = {};
            try {
                if (multiple) {
                    if (!this.holds(selection, attachment)) {
                        selection.add(attachment, { silent: true });
                    }
                } else if (!this.holds(selection, attachment) || selection.length !== 1) {
                    selection.reset([attachment], { silent: true });
                }
            } catch (error) {
                // The collection rejected the model; the manual insert below keeps it.
            }
            selection.filters = filters;
            if (!this.holds(selection, attachment)) {
                if (!multiple && selection.length) {
                    selection.reset([], { silent: true });
                }
                selection.models.push(attachment);
                selection.length = selection.models.length;
                if (typeof selection._addReference === 'function') {
                    selection._addReference(attachment);
                } else {
                    selection._byId = selection._byId || {};
                    selection._byId[attachment.cid] = attachment;
                    if (attachment.id) {
                        selection._byId[attachment.id] = attachment;
                    }
                }
            }
            if (selection._source && typeof selection._source.add === 'function' && !selection._source.get(attachment)) {
                try {
                    selection._source.add(attachment, { silent: true });
                } catch (error) {
                    // The mirror is only a fallback for a later filter reset.
                }
            }
            if (typeof selection.single === 'function') {
                selection.single(attachment);
            }
            var self = this;
            var keep = function () {
                if (!self.holds(selection, attachment)) {
                    selection.models.push(attachment);
                    selection.length = selection.models.length;
                    if (typeof selection._addReference === 'function') {
                        selection._addReference(attachment);
                    }
                }
            };
            selection.on('remove', keep);
            try {
                selection.trigger('add', attachment, selection, {});
            } catch (error) {
                // A sidebar listener can fail before the attachment details load.
            }
            selection.off('remove', keep);
            if (typeof selection.single === 'function') {
                selection.single(attachment);
            }
            this.refreshToolbar();
            this.enableToolbar();
            return this.holds(selection, attachment);
        },
        enableToolbar: function () {
            var frame = resolveFrame(this.controller);
            var toolbar = frame && frame.toolbar && typeof frame.toolbar.get === 'function' ? frame.toolbar.get() : null;
            var selection = this.currentSelection();
            if (!toolbar || typeof toolbar.get !== 'function' || !selection || !selection.length) {
                return;
            }
            ['select', 'gallery', 'insert', 'playlist', 'video-playlist'].forEach(function (id) {
                var button = toolbar.get(id);
                if (button && button.model) {
                    button.model.set('disabled', false);
                }
            });
        },
        removeFromSelection: function (id) {
            var selection = this.currentSelection();
            if (!selection) {
                return;
            }
            var model = selection.get(id);
            if (model) {
                selection.remove(model);
            }
            this.refreshToolbar();
        },
        attach: function (id, select) {
            var self = this;
            var numeric = parseInt(id, 10);
            var attachment = wp.media.attachment(numeric);
            if (select && !this.addToSelection(attachment)) {
                return Promise.reject(new Error(i18n.notIncluded || i18n.importFailed || ''));
            }
            var request = attachment.fetch();
            if (request && typeof request.done === 'function') {
                request.done(function () {
                    var mime = attachment.get('mime') || '';
                    if (mime.indexOf('image/') === 0 || attachment.get('type') === 'image' || !attachment.get('type')) {
                        attachment.set('type', 'image');
                    }
                    if (select) {
                        self.addToSelection(attachment);
                    }
                });
            }
            return Promise.resolve(attachment);
        },
        refreshBulk: function () {
            if (!this.bulkButton || !this.grid) {
                return;
            }
            var boxes = this.grid.querySelectorAll('.dwdc-pick');
            var count = this.grid.querySelectorAll('.dwdc-pick:checked').length;
            var label = i18n.importSelected || '';
            this.bulkButton.textContent = count ? label + ' (' + count + ')' : label;
            this.bulkButton.disabled = this.busy || count === 0;
            if (this.pickAll) {
                this.pickAll.checked = boxes.length > 0 && count === boxes.length;
                this.pickAll.indeterminate = count > 0 && count < boxes.length;
            }
            this.refreshSend();
        },
        exportIds: function () {
            var ids = [];
            var push = function (id) {
                id = parseInt(id, 10);
                if (id && ids.indexOf(id) === -1) {
                    ids.push(id);
                }
            };
            var read = function (selection) {
                if (!selection || !selection.models) {
                    return;
                }
                selection.models.forEach(function (model) {
                    push(model && (model.id || (model.get && model.get('id'))));
                });
            };
            read(this.currentSelection());
            if (!ids.length && settings.libraryPage && wp.media.frames && wp.media.frames.browse && wp.media.frames.browse.state) {
                read(wp.media.frames.browse.state().get('selection'));
            }
            return ids;
        },
        refreshSend: function () {
            if (!this.sendButton) {
                return;
            }
            var count = this.exportIds().length;
            var label = i18n.sendSelection || '';
            this.sendButton.textContent = count ? label + ' (' + count + ')' : label;
            this.sendButton.disabled = this.busy || count === 0;
        },
        onSend: function () {
            var self = this;
            var ids = this.exportIds();
            if (this.busy) {
                return;
            }
            if (!ids.length) {
                this.setMessage(i18n.sendNone || '');
                return;
            }
            var folder = this.path || '';
            var index = 0;
            var done = 0;
            var failed = 0;
            var lastError = '';
            this.busy = true;
            this.refreshSend();
            this.refreshBulk();
            var next = function () {
                if (index >= ids.length) {
                    self.busy = false;
                    self.refreshSend();
                    self.refreshBulk();
                    if (done && !failed) {
                        self.load(fill(i18n.sendDone, done));
                        return;
                    }
                    if (done && failed) {
                        self.load(fill(i18n.sendPartial, done, failed));
                        return;
                    }
                    self.setMessage(lastError || i18n.sendFailed || '');
                    return;
                }
                var id = ids[index];
                index += 1;
                self.setMessage(fill(i18n.sendProgress, index, ids.length));
                api({
                    url: root() + 'export',
                    method: 'POST',
                    data: { id: id, path: folder }
                }).then(function () {
                    done += 1;
                    next();
                }).catch(function (error) {
                    failed += 1;
                    lastError = errorText(error, i18n.sendFailed || '');
                    next();
                });
            };
            next();
        },
        markCard: function (button, mode) {
            var card = button.closest('.dwdc-item');
            button.disabled = false;
            button.classList.remove('button-primary');
            if (mode === 'selected') {
                button.textContent = i18n.selected || '';
                button.classList.add('is-done');
                button.classList.remove('is-library');
                if (card) {
                    card.classList.add('is-chosen');
                }
                this.forgetOthers(card);
                if (!this.busy) {
                    this.setMessage(i18n.included || '');
                }
            } else {
                button.textContent = i18n.already || '';
                button.classList.add('is-library');
                button.classList.remove('is-done');
                if (card) {
                    card.classList.add('is-library');
                    card.classList.remove('is-chosen');
                }
            }
            this.refreshBulk();
        },
        paintCrumbs: function () {
            var self = this;
            this.crumbs.innerHTML = '';
            if (this.mode === 'search') {
                this.upButton.disabled = false;
                this.crumbs.textContent = (i18n.searchAction || '') + ': ' + this.query;
                return;
            }
            var button = el('button', 'button-link dwdc-crumb', i18n.root || 'Dropbox');
            button.type = 'button';
            button.setAttribute('data-index', '-1');
            this.crumbs.appendChild(button);
            var parts = this.displayPath ? this.displayPath.split('/').filter(Boolean) : [];
            parts.forEach(function (part, index) {
                this.crumbs.appendChild(el('span', 'dwdc-sep', '/'));
                if (index === parts.length - 1) {
                    this.crumbs.appendChild(el('span', 'dwdc-crumb-current', part));
                } else {
                    var crumb = el('button', 'button-link dwdc-crumb', part);
                    crumb.type = 'button';
                    crumb.setAttribute('data-index', String(index));
                    this.crumbs.appendChild(crumb);
                }
            }, self);
            this.upButton.disabled = !this.path;
        },
        badge: function (text) {
            return el('span', 'dwdc-ext', text || i18n.file || 'FILE');
        },
        fileButton: function (item) {
            var existing = item.library_id ? parseInt(item.library_id, 10) : 0;
            var button = el(
                'button',
                'button dwdc-import' + (existing ? ' is-library' : ' button-primary'),
                existing ? (i18n.already || 'Already in library') : (i18n.import || 'Import')
            );
            button.type = 'button';
            button.setAttribute('data-path', item.path);
            if (existing) {
                button.setAttribute('data-library-id', String(existing));
            }
            return button;
        },
        paint: function (data, append) {
            var self = this;
            var entries = data && Array.isArray(data.entries) ? data.entries : [];
            if (!append) {
                this.grid.innerHTML = '';
            }
            entries.forEach(function (item) {
                if (!item || !item.path || !item.name) {
                    return;
                }
                var card = el('div', 'dwdc-item');
                if (item.tag === 'folder') {
                    var folder = el('button', 'dwdc-folder');
                    folder.type = 'button';
                    folder.setAttribute('data-path', item.path);
                    folder.setAttribute('data-display', item.path_display || item.path);
                    folder.setAttribute('aria-label', (i18n.open || '') + ' ' + item.name);
                    folder.appendChild(self.badge(i18n.folder || 'DIR'));
                    var folderName = el('span', 'dwdc-name', item.name);
                    folderName.title = item.path_display || item.name;
                    folder.appendChild(folderName);
                    card.appendChild(folder);
                    self.grid.appendChild(card);
                    return;
                }

                card.classList.add('is-file');
                if (item.library_id) {
                    card.classList.add('is-library');
                    card.setAttribute('data-library-id', String(item.library_id));
                }
                var pick = el('input', 'dwdc-pick');
                pick.type = 'checkbox';
                pick.setAttribute('aria-label', item.name);
                card.appendChild(pick);

                var preview = el('div', 'dwdc-preview');
                if (item.thumb) {
                    var image = el('img');
                    image.alt = item.name;
                    image.loading = 'lazy';
                    image.src = root() + 'thumbnail?path=' + encodeURIComponent(item.path) + '&_wpnonce=' + encodeURIComponent(settings.nonce);
                    image.addEventListener('error', function () {
                        image.replaceWith(self.badge(item.extension));
                    });
                    preview.appendChild(image);
                } else {
                    preview.appendChild(self.badge(item.extension));
                }
                card.appendChild(preview);
                var name = el('div', 'dwdc-name', item.name);
                name.title = item.path_display || item.name;
                card.appendChild(name);
                card.appendChild(self.fileButton(item));
                self.grid.appendChild(card);
            });
            this.cursor = data && data.has_more && data.cursor ? data.cursor : '';
            this.moreButton.hidden = !this.cursor;
            this.clearButton.hidden = this.mode !== 'search';
            this.paintCrumbs();
            this.refreshBulk();
            if (!append && !entries.length) {
                this.setMessage(this.mode === 'search' ? (i18n.emptySearch || '') : (i18n.empty || ''));
            } else if (!this.busy) {
                this.setMessage('');
            }
        },
        load: function (note) {
            var self = this;
            var id = ++this.requestId;
            this.mode = 'list';
            this.cursor = '';
            this.clearButton.hidden = true;
            this.setMessage(i18n.loading || '');
            request('files', { path: this.path, fresh: '1' }).then(function (data) {
                if (id !== self.requestId) {
                    return;
                }
                self.paint(data, false);
                if (note) {
                    self.setMessage(note);
                }
            }).catch(function (error) {
                if (id !== self.requestId) {
                    return;
                }
                self.setMessage(errorText(error, i18n.failed));
            });
        },
        onMore: function () {
            var self = this;
            if (!this.cursor || this.busy) {
                return;
            }
            var id = ++this.requestId;
            var cursor = this.cursor;
            var pathName = this.mode === 'search' ? 'search' : 'files';
            this.moreButton.disabled = true;
            request(pathName, { cursor: cursor, path: this.path, q: this.query, fresh: '1' }).then(function (data) {
                self.moreButton.disabled = false;
                if (id !== self.requestId) {
                    return;
                }
                self.paint(data, true);
            }).catch(function (error) {
                self.moreButton.disabled = false;
                self.setMessage(errorText(error, i18n.failed));
            });
        },
        onSearch: function () {
            var query = (this.searchInput.value || '').trim();
            if (query.length < 2) {
                this.setMessage(i18n.shortQuery || '');
                return;
            }
            var self = this;
            var id = ++this.requestId;
            this.mode = 'search';
            this.query = query;
            this.cursor = '';
            this.setMessage(i18n.searching || '');
            request('search', { q: query, fresh: '1' }).then(function (data) {
                if (id !== self.requestId) {
                    return;
                }
                self.paint(data, false);
            }).catch(function (error) {
                if (id !== self.requestId) {
                    return;
                }
                self.setMessage(errorText(error, i18n.failed));
            });
        },
        onSearchKey: function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.onSearch();
            }
        },
        onClearSearch: function () {
            this.searchInput.value = '';
            this.query = '';
            this.mode = 'list';
            this.load();
        },
        goTo: function (path, displayPath) {
            this.path = path || '';
            this.displayPath = displayPath || path || '';
            this.query = '';
            this.searchInput.value = '';
            this.load();
        },
        onUp: function () {
            if (!this.path || this.mode === 'search') {
                if (this.mode === 'search') {
                    this.onClearSearch();
                }
                return;
            }
            var path = this.path.split('/').slice(0, -1).join('/');
            var display = this.displayPath.split('/').slice(0, -1).join('/');
            this.goTo(path, display);
        },
        onCrumb: function (event) {
            var index = parseInt(event.currentTarget.getAttribute('data-index'), 10);
            if (index === -1) {
                this.goTo('', '');
                return;
            }
            var pathParts = this.path.split('/').filter(Boolean).slice(0, index + 1);
            var displayParts = this.displayPath.split('/').filter(Boolean).slice(0, index + 1);
            this.goTo('/' + pathParts.join('/'), '/' + displayParts.join('/'));
        },
        onFolder: function (event) {
            var button = event.currentTarget;
            this.goTo(button.getAttribute('data-path') || '', button.getAttribute('data-display') || '');
        },
        onRefresh: function () {
            if (this.busy) {
                return;
            }
            if (this.mode === 'search' && this.query) {
                this.onSearch();
                return;
            }
            this.load();
        },
        checkedLibraryIds: function () {
            var ids = [];
            Array.prototype.forEach.call(this.grid.querySelectorAll('.dwdc-item.is-file'), function (card) {
                var box = card.querySelector('.dwdc-pick');
                var id = parseInt(card.getAttribute('data-library-id') || '0', 10);
                if (box && box.checked && id) {
                    ids.push(id);
                }
            });
            return ids;
        },
        includeIds: function (ids) {
            var self = this;
            var index = 0;
            var failed = 0;
            var next = function () {
                if (index >= ids.length) {
                    self.refreshToolbar();
                    self.enableToolbar();
                    if (failed && !self.busy) {
                        self.setMessage(i18n.notIncluded || '');
                    } else if (ids.length && !self.busy) {
                        self.setMessage(i18n.included || '');
                    }
                    return;
                }
                var id = ids[index];
                index += 1;
                self.attach(id, true).then(next).catch(function () {
                    failed += 1;
                    next();
                });
            };
            next();
        },
        onPick: function (event) {
            var box = event.currentTarget;
            var card = box.closest('.dwdc-item');
            var id = card ? parseInt(card.getAttribute('data-library-id') || '0', 10) : 0;
            this.refreshBulk();
            if (!id || !this.wantsSelection()) {
                return;
            }
            if (box.checked) {
                this.includeIds([id]);
                return;
            }
            this.removeFromSelection(id);
            if (!allowsMultiple(this.controller)) {
                var rest = this.checkedLibraryIds();
                if (rest.length) {
                    this.includeIds([rest[rest.length - 1]]);
                    return;
                }
            }
            this.refreshToolbar();
        },
        onKeep: function () {
            var self = this;
            var ids = this.checkedLibraryIds();
            if (this.wantsSelection()) {
                this.includeIds(ids);
                return;
            }
            ids.forEach(function (id) {
                self.removeFromSelection(id);
            });
            this.refreshToolbar();
        },
        onPickAll: function () {
            var self = this;
            var checked = !!(this.pickAll && this.pickAll.checked);
            var ids = [];
            Array.prototype.forEach.call(this.grid.querySelectorAll('.dwdc-item.is-file'), function (card) {
                var box = card.querySelector('.dwdc-pick');
                var id = parseInt(card.getAttribute('data-library-id') || '0', 10);
                if (box) {
                    box.checked = checked;
                }
                if (id) {
                    ids.push(id);
                }
            });
            this.refreshBulk();
            if (!this.wantsSelection()) {
                return;
            }
            if (!checked) {
                ids.forEach(function (id) {
                    self.removeFromSelection(id);
                });
                this.refreshToolbar();
                return;
            }
            this.includeIds(ids);
        },
        importPath: function (path, button, select) {
            var self = this;
            var want = typeof select === 'boolean' ? select : this.wantsSelection();
            if (!path) {
                return Promise.reject(new Error(i18n.importFailed || ''));
            }
            button.disabled = true;
            button.textContent = i18n.importing || '';
            return api({
                url: root() + 'import',
                method: 'POST',
                data: { path: path }
            }).then(function (item) {
                var id = item && item.id ? parseInt(item.id, 10) : 0;
                if (!id) {
                    throw new Error(i18n.importFailed || '');
                }
                button.setAttribute('data-library-id', String(id));
                var card = button.closest('.dwdc-item');
                if (card) {
                    card.classList.add('is-library');
                    card.setAttribute('data-library-id', String(id));
                }
                var importedFrame = resolveFrame(self.controller) || self.controller;
                if (importedFrame) {
                    importedFrame.dwdcImported = true;
                }
                return self.attach(id, want).then(function () {
                    self.markCard(button, want ? 'selected' : 'library');
                });
            }).catch(function (error) {
                button.disabled = false;
                if (!button.getAttribute('data-library-id')) {
                    button.textContent = i18n.import || '';
                }
                throw error;
            });
        },
        useLibrary: function (button, id, select) {
            var self = this;
            var want = typeof select === 'boolean' ? select : true;
            button.disabled = true;
            return this.attach(id, want).then(function () {
                self.markCard(button, want ? 'selected' : 'library');
            }).catch(function (error) {
                button.disabled = false;
                button.textContent = i18n.already || '';
                throw error;
            });
        },
        onImport: function (event) {
            var self = this;
            var button = event.currentTarget;
            var path = button.getAttribute('data-path') || '';
            var libraryId = parseInt(button.getAttribute('data-library-id') || '0', 10);
            if (this.busy || button.disabled) {
                return;
            }
            var task = libraryId ? this.useLibrary(button, libraryId, this.wantsSelection()) : this.importPath(path, button);
            task.catch(function (error) {
                self.setMessage(errorText(error, i18n.importFailed));
            });
        },
        onBulk: function () {
            var self = this;
            if (this.busy) {
                return;
            }
            var buttons = [];
            Array.prototype.forEach.call(this.grid.querySelectorAll('.dwdc-item.is-file'), function (card) {
                var pick = card.querySelector('.dwdc-pick');
                var button = card.querySelector('.dwdc-import');
                if (pick && pick.checked && button) {
                    buttons.push(button);
                }
            });
            if (!buttons.length) {
                return;
            }
            var select = this.wantsSelection();
            var index = 0;
            var done = 0;
            var failed = 0;
            this.busy = true;
            this.bulkButton.disabled = true;
            var next = function () {
                if (index >= buttons.length) {
                    self.busy = false;
                    self.refreshBulk();
                    if (failed) {
                        self.setMessage(fill(i18n.bulkPartial, done, failed));
                    } else if (select) {
                        self.setMessage(fill(i18n.bulkDone, done));
                    } else {
                        self.setMessage(fill(i18n.bulkImported, done));
                    }
                    return;
                }
                var button = buttons[index];
                index += 1;
                self.setMessage(fill(i18n.importProgress, index, buttons.length));
                var libraryId = parseInt(button.getAttribute('data-library-id') || '0', 10);
                var task = libraryId
                    ? self.useLibrary(button, libraryId, select)
                    : self.importPath(button.getAttribute('data-path') || '', button, select);
                task.then(function () {
                    done += 1;
                    next();
                }).catch(function () {
                    failed += 1;
                    next();
                });
            };
            next();
        }
    });

    function addTab(routerView) {
        if (!routerView || typeof routerView.set !== 'function') {
            return;
        }
        routerView.set({
            dwdc: {
                text: i18n.tab || 'Dropbox',
                priority: 80
            }
        });
    }

    function keepDropboxMounted(frame) {
        var states = frame && frame.states;
        if (!states || typeof states.each !== 'function') {
            return;
        }
        states.each(function (state) {
            if (!state || state.dwdcRefreshGuarded || typeof state.refreshContent !== 'function') {
                return;
            }
            var original = state.refreshContent;
            state.refreshContent = function () {
                var mode = frame.content && typeof frame.content.mode === 'function' ? frame.content.mode() : '';
                if (mode === 'dwdc') {
                    return;
                }
                return original.apply(this, arguments);
            };
            state.dwdcRefreshGuarded = true;
        });
    }

    function openDropbox(frame, region) {
        frame = resolveFrame(frame);
        if (!frame) {
            return;
        }
        keepDropboxMounted(frame);
        var view = new Browser({ controller: frame });
        view.render();
        if (region && typeof region === 'object' && Object.prototype.hasOwnProperty.call(region, 'view')) {
            region.view = view;
            return;
        }
        if (frame.content && typeof frame.content.set === 'function') {
            frame.content.set(view);
        }
    }

    function patch(Frame) {
        if (!Frame || !Frame.prototype || Frame.prototype.dwdcPatched) {
            return;
        }
        var originalBind = Frame.prototype.bindHandlers;
        var originalRouter = Frame.prototype.browseRouter;
        Frame.prototype.bindHandlers = function () {
            if (originalBind) {
                originalBind.apply(this, arguments);
            }
            this.dwdcHooked = true;
            var frame = this;
            this.on('content:create:dwdc', function (region) {
                openDropbox(frame, region);
            });
            this.on('router:render:browse', function (routerView) {
                addTab(routerView);
            });
        };
        if (originalRouter) {
            Frame.prototype.browseRouter = function (routerView) {
                originalRouter.apply(this, arguments);
                addTab(routerView);
            };
        }
        Frame.prototype.dwdcPatched = true;
    }

    patch(wp.media.view.MediaFrame.Select);
    if (wp.media.view.MediaFrame.Post && wp.media.view.MediaFrame.Post.prototype.browseRouter &&
        wp.media.view.MediaFrame.Post.prototype.browseRouter !== wp.media.view.MediaFrame.Select.prototype.browseRouter) {
        var postRouter = wp.media.view.MediaFrame.Post.prototype.browseRouter;
        wp.media.view.MediaFrame.Post.prototype.browseRouter = function (routerView) {
            postRouter.apply(this, arguments);
            addTab(routerView);
        };
    }

    var originalFactory = wp.media;
    function factory(attributes) {
        var frame = originalFactory.apply(wp, arguments);
        if (frame && !frame.dwdcBound) {
            frame.dwdcBound = true;
            if (!frame.dwdcHooked) {
                frame.on('content:create:dwdc', function (region) {
                    openDropbox(frame, region);
                });
            }
            frame.on('router:render:browse', function (routerView) {
                addTab(routerView);
            });
        }
        return frame;
    }
    Object.keys(originalFactory).forEach(function (key) {
        factory[key] = originalFactory[key];
    });
    wp.media = factory;

    function refreshLibraryGrid() {
        var browse = wp.media.frames && wp.media.frames.browse;
        var library = browse && typeof browse.state === 'function' ? browse.state().get('library') : null;
        if (!library || !library.props || typeof library.props.set !== 'function') {
            return false;
        }
        library.props.set('dwdcRefresh', Date.now());
        return true;
    }

    function openLibraryModal() {
        if (openLibraryModal.frame) {
            openLibraryModal.frame.open();
            return;
        }
        var frame = wp.media({
            title: i18n.tab || 'Dropbox',
            button: { text: i18n.done || 'Concluir' },
            multiple: true
        });
        openLibraryModal.frame = frame;
        frame.on('open', function () {
            var show = function () {
                if (frame.content && typeof frame.content.mode === 'function' && frame.content.mode() !== 'dwdc') {
                    frame.content.mode('dwdc');
                }
            };
            show();
            window.setTimeout(show, 0);
        });
        frame.on('close', function () {
            if (!frame.dwdcImported) {
                return;
            }
            frame.dwdcImported = false;
            if (!refreshLibraryGrid()) {
                window.location.reload();
            }
        });
        frame.open();
    }

    function mountLibraryButton() {
        if (!settings.libraryPage || document.querySelector('.dwdc-library-open')) {
            return;
        }
        var add = document.querySelector('.upload-php .page-title-action');
        if (!add) {
            return;
        }
        var button = document.createElement('a');
        button.className = 'page-title-action dwdc-library-open';
        button.href = '#';
        button.textContent = i18n.tab || 'Dropbox';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            openLibraryModal();
        });
        add.insertAdjacentElement('afterend', button);
    }

    if (settings.libraryPage && window.jQuery) {
        window.jQuery(function () {
            var grid = document.getElementById('wp-media-grid');
            if (!grid) {
                mountLibraryButton();
                return;
            }
            window.jQuery(grid).on('wp-media-grid-ready', mountLibraryButton);
        });
    }
})(window.wp, window.dwdcMedia);
