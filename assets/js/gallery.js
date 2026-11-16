(function () {
    'use strict';

    var lightbox = null;
    var state = { links: [], index: 0, opener: null, i18n: {} };

    function readI18n(gallery) {
        try {
            return JSON.parse(gallery.getAttribute('data-i18n') || '{}') || {};
        } catch (e) {
            return {};
        }
    }

    function visibleLinks(gallery) {
        return Array.prototype.slice.call(
            gallery.querySelectorAll('.dwdc-gallery__item:not([hidden]) .dwdc-gallery__link')
        );
    }

    function updateMore(gallery) {
        var button = gallery.querySelector('.dwdc-gallery__more');
        if (!button) {
            return;
        }
        button.hidden = !gallery.querySelector('.dwdc-gallery__item[hidden]');
    }

    function revealMore(gallery) {
        var step = parseInt(gallery.getAttribute('data-step'), 10) || 12;
        var hidden = gallery.querySelectorAll('.dwdc-gallery__item[hidden]');
        for (var i = 0; i < hidden.length && i < step; i++) {
            hidden[i].hidden = false;
        }
        updateMore(gallery);
    }

    function buildLightbox() {
        var root = document.createElement('div');
        root.className = 'dwdc-lb';
        root.hidden = true;
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');

        var backdrop = document.createElement('div');
        backdrop.className = 'dwdc-lb__backdrop';

        var figure = document.createElement('figure');
        figure.className = 'dwdc-lb__figure';
        var img = document.createElement('img');
        img.className = 'dwdc-lb__img';
        img.alt = '';
        var caption = document.createElement('figcaption');
        caption.className = 'dwdc-lb__caption';
        figure.appendChild(img);
        figure.appendChild(caption);

        function button(cls, text) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'dwdc-lb__btn ' + cls;
            b.textContent = text;
            return b;
        }
        var close = button('dwdc-lb__close', '×');
        var prev = button('dwdc-lb__prev', '‹');
        var next = button('dwdc-lb__next', '›');
        var count = document.createElement('span');
        count.className = 'dwdc-lb__count';
        count.setAttribute('aria-live', 'polite');

        root.appendChild(backdrop);
        root.appendChild(figure);
        root.appendChild(close);
        root.appendChild(prev);
        root.appendChild(next);
        root.appendChild(count);
        document.body.appendChild(root);

        backdrop.addEventListener('click', closeLightbox);
        close.addEventListener('click', closeLightbox);
        prev.addEventListener('click', function () { step(-1); });
        next.addEventListener('click', function () { step(1); });

        var startX = null;
        root.addEventListener('touchstart', function (event) {
            startX = event.touches.length === 1 ? event.touches[0].clientX : null;
        }, { passive: true });
        root.addEventListener('touchend', function (event) {
            if (startX === null || !event.changedTouches.length) {
                return;
            }
            var dx = event.changedTouches[0].clientX - startX;
            startX = null;
            if (Math.abs(dx) > 50) {
                step(dx > 0 ? -1 : 1);
            }
        }, { passive: true });

        return { root: root, img: img, caption: caption, close: close, prev: prev, next: next, count: count };
    }

    function show(index) {
        var total = state.links.length;
        if (!total) {
            return;
        }
        state.index = (index + total) % total;
        var token = String(state.index) + ':' + String(Date.now());
        state.token = token;
        var link = state.links[state.index];
        var thumb = link.querySelector('img');
        var img = lightbox.img;
        var full = link.href;
        var preview = thumb ? (thumb.currentSrc || thumb.getAttribute('src') || '') : '';
        img.alt = thumb ? thumb.getAttribute('alt') || '' : '';
        img.onload = null;
        img.onerror = null;
        if (preview && preview !== full) {
            img.src = preview;
            img.classList.add('is-ready');
            var loader = new Image();
            loader.onload = function () {
                if (state.token !== token) {
                    return;
                }
                img.src = full;
            };
            loader.src = full;
        } else {
            img.classList.remove('is-ready');
            img.onload = function () { img.classList.add('is-ready'); };
            img.onerror = function () { img.classList.add('is-ready'); };
            img.src = full;
        }
        lightbox.caption.textContent = link.getAttribute('data-caption') || '';
        var template = state.i18n.count || '%1 / %2';
        lightbox.count.textContent = template.replace('%1', String(state.index + 1)).replace('%2', String(total));
        if (total > 1) {
            [1, -1].forEach(function (offset) {
                var neighbor = state.links[(state.index + offset + total) % total];
                if (neighbor) {
                    var preload = new Image();
                    preload.src = neighbor.href;
                }
            });
        }
    }

    function step(delta) {
        if (state.links.length > 1) {
            show(state.index + delta);
        }
    }

    function onKey(event) {
        if (!lightbox || lightbox.root.hidden) {
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeLightbox();
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            step(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            step(1);
        } else if (event.key === 'Tab') {
            var focusable = [lightbox.close];
            if (state.links.length > 1) {
                focusable.push(lightbox.prev, lightbox.next);
            }
            var at = focusable.indexOf(document.activeElement);
            event.preventDefault();
            if (event.shiftKey) {
                focusable[(at <= 0 ? focusable.length : at) - 1].focus();
            } else {
                focusable[(at + 1) % focusable.length].focus();
            }
        }
    }

    function openLightbox(gallery, link) {
        if (!lightbox) {
            lightbox = buildLightbox();
            document.addEventListener('keydown', onKey);
        }
        state.i18n = readI18n(gallery);
        state.links = visibleLinks(gallery);
        state.opener = link;
        lightbox.root.setAttribute('aria-label', state.i18n.dialog || '');
        lightbox.close.setAttribute('aria-label', state.i18n.close || '');
        lightbox.prev.setAttribute('aria-label', state.i18n.prev || '');
        lightbox.next.setAttribute('aria-label', state.i18n.next || '');
        lightbox.root.classList.toggle('dwdc-lb--single', state.links.length < 2);
        lightbox.root.hidden = false;
        document.documentElement.classList.add('dwdc-lb-open');
        show(Math.max(0, state.links.indexOf(link)));
        lightbox.close.focus();
    }

    function closeLightbox() {
        if (!lightbox || lightbox.root.hidden) {
            return;
        }
        lightbox.root.hidden = true;
        lightbox.img.removeAttribute('src');
        document.documentElement.classList.remove('dwdc-lb-open');
        if (state.opener && document.contains(state.opener)) {
            state.opener.focus();
        }
        state.opener = null;
    }

    document.addEventListener('click', function (event) {
        var more = event.target.closest ? event.target.closest('.dwdc-gallery__more') : null;
        if (more) {
            var host = more.closest('.dwdc-gallery');
            if (host) {
                revealMore(host);
            }
            return;
        }
        var link = event.target.closest ? event.target.closest('.dwdc-gallery__link') : null;
        if (!link) {
            return;
        }
        var gallery = link.closest('.dwdc-gallery');
        if (!gallery || gallery.getAttribute('data-lightbox') !== '1') {
            return;
        }
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }
        event.preventDefault();
        openLightbox(gallery, link);
    });

    /**
     * Páginas em cache mostram o HTML da época em que foram guardadas. Cada galeria pergunta ao servidor se o
     * conjunto de imagens mudou e, se mudou, troca a lista sem recarregar a página.
     */
    function refresh(gallery) {
        var endpoint = gallery.getAttribute('data-endpoint');
        if (!endpoint || !window.fetch || !window.URL) {
            return;
        }
        var url;
        try {
            url = new URL(endpoint, window.location.href);
        } catch (e) {
            return;
        }
        if (url.origin !== window.location.origin) {
            return;
        }
        url.searchParams.set('ver', gallery.getAttribute('data-version') || '');
        window.fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !data.changed || typeof data.html !== 'string') {
                    return;
                }
                var list = gallery.querySelector('.dwdc-gallery__list');
                if (!list) {
                    return;
                }
                var shown = list.querySelectorAll('.dwdc-gallery__item:not([hidden])').length;
                list.innerHTML = data.html;
                var items = list.querySelectorAll('.dwdc-gallery__item');
                for (var i = 0; i < items.length && i < shown; i++) {
                    items[i].hidden = false;
                }
                gallery.setAttribute('data-version', data.version || '');
                updateMore(gallery);
            })
            .catch(function () {});
    }

    function watch() {
        var galleries = document.querySelectorAll('.dwdc-gallery[data-endpoint]');
        if (!galleries.length) {
            return;
        }
        if (!('IntersectionObserver' in window)) {
            Array.prototype.forEach.call(galleries, refresh);
            return;
        }
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    observer.unobserve(entry.target);
                    refresh(entry.target);
                }
            });
        }, { rootMargin: '400px 0px' });
        Array.prototype.forEach.call(galleries, function (gallery) {
            observer.observe(gallery);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', watch);
    } else {
        watch();
    }
})();
