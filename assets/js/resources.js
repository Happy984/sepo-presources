/* Sepo Resources — likes, download counters, citation copy, and the
   listing's search, group filter and pagination. One instance per .spr-wrap,
   so several listings can sit on one page. */
(function () {
  'use strict';

  var cfg = window.SepoResources || {};
  var api = (cfg.api || '').replace(/\/$/, '');
  var t = cfg.i18n || {};

  function fmt(str) {
    var args = Array.prototype.slice.call(arguments, 1);
    var i = 0;
    return String(str || '').replace(/%(\d+)\$s|%s/g, function (m, n) {
      return n ? args[parseInt(n, 10) - 1] : args[i++];
    });
  }

  function post(path, payload) {
    return fetch(api + path, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'X-WP-Nonce': cfg.nonce || ''
      },
      body: JSON.stringify(payload)
    }).then(function (r) {
      return r.text().then(function (txt) {
        var b = {};
        try { b = JSON.parse(txt); } catch (e) { throw new Error(t.failed || 'Request failed.'); }
        if (!r.ok || b.status === 'error') throw new Error(b.message || t.failed || 'Request failed.');
        return b;
      });
    });
  }

  function bumpDownloads(id) {
    var nodes = document.querySelectorAll('[data-spr-dl-n="' + id + '"] .spr-dl-v');
    Array.prototype.forEach.call(nodes, function (n) {
      n.textContent = (parseInt(n.textContent, 10) || 0) + 1;
    });
  }

  // ---- shared clicks (delegated once, document-wide) ----------------------
  document.addEventListener('click', function (ev) {
    var el = ev.target;
    if (!el || !el.closest || !el.closest('.spr-wrap')) return;

    var dl = el.closest('[data-spr-dl]');
    if (dl) {
      // The streamer counts on the server; this only updates the numbers on screen.
      bumpDownloads(dl.getAttribute('data-spr-dl'));
      return;
    }

    var like = el.closest('button[data-spr-like]');
    if (like) {
      ev.preventDefault();
      if (like.classList.contains('is-busy')) return;
      like.classList.add('is-busy');
      var id = like.getAttribute('data-spr-like');

      post('/like', { doc: id })
        .then(function (b) {
          var d = b.data || {};
          var on = !!d.liked;
          Array.prototype.forEach.call(document.querySelectorAll('button[data-spr-like="' + id + '"]'), function (btn) {
            btn.classList.toggle('is-on', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
            btn.setAttribute('title', on ? (t.unlike || '') : (t.like || ''));
            var heart = btn.querySelector('.spr-heart');
            if (heart) heart.innerHTML = on ? '&#9829;' : '&#9825;';
            var n = btn.querySelector('.spr-like-n');
            if (n && typeof d.count === 'number') n.textContent = d.count;
          });
          Array.prototype.forEach.call(document.querySelectorAll('[data-spr-like-total="' + id + '"]'), function (n) {
            if (typeof d.count === 'number') n.textContent = d.count;
          });
        })
        .catch(function (err) { window.alert(err.message); })
        .then(function () { like.classList.remove('is-busy'); });
      return;
    }

    var copy = el.closest('[data-spr-copy]');
    if (copy) {
      ev.preventDefault();
      var src = document.getElementById(copy.getAttribute('data-spr-copy'));
      if (!src) return;
      var text = src.textContent.trim();
      var done = function () {
        var was = copy.textContent;
        copy.textContent = t.copied || 'Copied';
        setTimeout(function () { copy.textContent = was; }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done).catch(function () {});
      } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); done(); } catch (e) {}
        ta.remove();
      }
    }
  });

  // ---- listings -----------------------------------------------------------
  function initIndex(root) {
    var listRoot = root.querySelector('[data-spr-rows]');
    if (!listRoot) return;

    var rows = Array.prototype.slice.call(root.querySelectorAll('[data-spr-row]'));
    var heads = Array.prototype.slice.call(root.querySelectorAll('[data-spr-group-head]'));
    var perPage = parseInt(root.getAttribute('data-spr-per-page'), 10) || 10;
    var countEl = root.querySelector('[data-spr-count]');
    var noneEl = root.querySelector('[data-spr-noresults]');
    var pager = root.querySelector('[data-spr-pager]');
    var searchEl = root.querySelector('[data-spr-search]');
    var filtered = rows.slice();
    var page = 1;
    var needle = '';
    var group = '';

    function pageCount() { return Math.max(1, Math.ceil(filtered.length / perPage)); }

    function windowed(cur, total) {
      var out = [], i;
      if (total <= 7) {
        for (i = 1; i <= total; i++) out.push(i);
        return out;
      }
      out.push(1);
      var from = Math.max(2, cur - 1), to = Math.min(total - 1, cur + 1);
      if (from > 2) out.push('gap');
      for (i = from; i <= to; i++) out.push(i);
      if (to < total - 1) out.push('gap');
      out.push(total);
      return out;
    }

    function draw() {
      var total = pageCount();
      if (page > total) page = total;
      if (page < 1) page = 1;
      var start = (page - 1) * perPage;
      var end = start + perPage;

      rows.forEach(function (row) { row.hidden = true; });
      var visible = filtered.slice(start, end);
      visible.forEach(function (row) { row.hidden = false; });

      if (heads.length) {
        heads.forEach(function (h) { h.hidden = true; });
        var placed = {};
        visible.forEach(function (row) {
          var g = row.getAttribute('data-spr-group') || '~none';
          if (placed[g]) return;
          placed[g] = true;
          for (var i = 0; i < heads.length; i++) {
            if (heads[i].getAttribute('data-spr-group-head') === g) {
              row.parentNode.insertBefore(heads[i], row);
              heads[i].hidden = false;
              break;
            }
          }
        });
      }

      if (countEl) {
        countEl.textContent = filtered.length
          ? (filtered.length > perPage
              ? fmt(t.showing, start + 1, Math.min(end, filtered.length), filtered.length)
              : (filtered.length === 1 ? t.one : fmt(t.many, filtered.length)))
          : '';
      }
      if (noneEl) noneEl.hidden = filtered.length !== 0;

      if (pager) {
        if (filtered.length <= perPage) {
          pager.innerHTML = '';
          pager.hidden = true;
        } else {
          var h = '<button type="button" class="spr-page" data-spr-page="' + (page - 1) + '"'
            + (page === 1 ? ' disabled' : '') + ' aria-label="' + (t.prev || '') + '">&lsaquo;</button>';
          windowed(page, total).forEach(function (p) {
            h += p === 'gap'
              ? '<span class="spr-page-gap">&hellip;</span>'
              : '<button type="button" class="spr-page' + (p === page ? ' is-on' : '') + '" data-spr-page="' + p + '"'
                + (p === page ? ' aria-current="page"' : '') + '>' + p + '</button>';
          });
          h += '<button type="button" class="spr-page" data-spr-page="' + (page + 1) + '"'
            + (page === total ? ' disabled' : '') + ' aria-label="' + (t.next || '') + '">&rsaquo;</button>';
          pager.innerHTML = h;
          pager.hidden = false;
        }
      }
    }

    function refilter() {
      filtered = rows.filter(function (row) {
        if (group !== '' && (row.getAttribute('data-spr-group') || '~none') !== group) return false;
        if (needle === '') return true;
        return (row.getAttribute('data-spr-search-text') || '').indexOf(needle) !== -1;
      });
      page = 1;
      draw();
    }

    if (searchEl) {
      searchEl.addEventListener('input', function () {
        needle = searchEl.value.trim().toLowerCase();
        refilter();
      });
    }

    root.addEventListener('click', function (ev) {
      var pill = ev.target.closest ? ev.target.closest('[data-spr-group-filter]') : null;
      if (pill && root.contains(pill)) {
        ev.preventDefault();
        group = pill.getAttribute('data-spr-group-filter') || '';
        Array.prototype.forEach.call(root.querySelectorAll('[data-spr-group-filter]'), function (b) {
          b.classList.toggle('is-on', b === pill);
          b.setAttribute('aria-pressed', b === pill ? 'true' : 'false');
        });
        refilter();
        return;
      }

      var btn = ev.target.closest ? ev.target.closest('[data-spr-page]') : null;
      if (!btn || btn.disabled || !root.contains(btn)) return;
      ev.preventDefault();
      var want = parseInt(btn.getAttribute('data-spr-page'), 10);
      if (!want || want === page) return;
      page = want;
      draw();
      var top = listRoot.getBoundingClientRect().top + window.pageYOffset - 20;
      var smooth = !(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
      window.scrollTo({ top: top < 0 ? 0 : top, behavior: smooth ? 'smooth' : 'auto' });
    });

    draw();
  }

  function boot() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-spr-index]'), initIndex);
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
