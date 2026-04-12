/* medicina-lightbox.js
   Lightbox + gallery interaction for medicine pages.
   Expects window.GALLERY_ITEMS = [{src, caption, type},...] */

(function () {
    'use strict';

    var items   = window.GALLERY_ITEMS || [];
    var current = -1;

    var lb      = document.getElementById('lb');
    var lbMedia = document.getElementById('lb-media');
    var lbCap   = document.getElementById('lb-caption');
    var lbCnt   = document.getElementById('lb-counter');
    var lbClose = lb.querySelector('.lb-close');
    var lbBg    = lb.querySelector('.lb-backdrop');
    var lbPrev  = lb.querySelector('.lb-prev');
    var lbNext  = lb.querySelector('.lb-next');

    /* ── helpers ── */

    function cleanup() {
        var v = lbMedia.querySelector('video');
        if (v) { v.pause(); v.removeAttribute('src'); v.load(); }
        lbMedia.innerHTML = '';
    }

    function updateNav() {
        lbPrev.disabled = current <= 0;
        lbNext.disabled = current >= items.length - 1;
    }

    function open(idx) {
        if (idx < 0 || idx >= items.length) return;
        cleanup();
        current = idx;
        var item = items[idx];

        if (item.type === 'video') {
            var v = document.createElement('video');
            v.src         = item.src;
            v.controls    = true;
            v.autoplay    = true;
            v.playsInline = true;
            lbMedia.appendChild(v);
        } else {
            var img = document.createElement('img');
            img.src = item.src;
            img.alt = item.caption || '';
            lbMedia.appendChild(img);
        }

        lbCap.textContent   = item.caption || '';
        lbCap.style.display = item.caption ? '' : 'none';
        lbCnt.textContent   = (idx + 1) + ' / ' + items.length;
        updateNav();

        lb.classList.add('lb-open');
        document.body.style.overflow = 'hidden';
    }

    function close() {
        cleanup();
        lb.classList.remove('lb-open');
        document.body.style.overflow = '';
        current = -1;
    }

    /* ── event wiring ── */

    lbBg.addEventListener('click',    close);
    lbClose.addEventListener('click', close);
    lbPrev.addEventListener('click',  function () { open(current - 1); });
    lbNext.addEventListener('click',  function () { open(current + 1); });

    document.addEventListener('keydown', function (e) {
        if (!lb.classList.contains('lb-open')) return;
        if (e.key === 'Escape')     { close();            return; }
        if (e.key === 'ArrowLeft')  { open(current - 1); return; }
        if (e.key === 'ArrowRight') { open(current + 1); return; }
    });

    /* Gallery items open lightbox on click / keyboard */
    document.querySelectorAll('[data-lb-index]').forEach(function (el) {
        el.addEventListener('click', function () {
            open(parseInt(this.dataset.lbIndex, 10));
        });
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                open(parseInt(this.dataset.lbIndex, 10));
            }
        });
    });

    /* Seek video thumbnails past the (often black) first frame */
    document.querySelectorAll('.gallery-thumb video').forEach(function (v) {
        v.addEventListener('loadedmetadata', function () {
            if (v.duration > 0.6) v.currentTime = 0.5;
        });
    });

}());
