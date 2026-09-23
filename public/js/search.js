// Global search in the sidebar. "/" focuses it from anywhere; arrow keys move
// through results; Enter opens; Escape closes. Result rows are built here, so
// they use inline styles (Tailwind doesn't scan public/js).
(function () {
    'use strict';
    var input = document.getElementById('global-search');
    var panel = document.getElementById('global-search-results');
    if (!input || !panel) return;

    var timer = null, seq = 0, active = -1, rows = [];

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function close() { panel.style.display = 'none'; active = -1; }

    function highlight(i) {
        rows.forEach(function (r, n) { r.style.background = n === i ? '#eef2ff' : ''; });
        active = i;
        if (rows[i]) rows[i].scrollIntoView({ block: 'nearest' });
    }

    function render(results, q) {
        if (!results.length) {
            panel.innerHTML = '<div style="padding:10px 12px;font-size:13px;color:#64748b;">No matches for “' + esc(q) + '”</div>';
        } else {
            var html = '', lastType = null;
            results.forEach(function (r) {
                if (r.type !== lastType) {
                    html += '<div style="padding:8px 12px 2px;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;">' + esc(r.type) + 's</div>';
                    lastType = r.type;
                }
                html += '<a href="' + esc(r.url) + '" data-search-row style="display:block;padding:6px 12px;text-decoration:none;color:#0f172a;'
                    + (r.inactive ? 'opacity:.55;' : '') + '">'
                    + '<div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(r.title) + '</div>'
                    + (r.sub ? '<div style="font-size:11px;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + esc(r.sub) + (r.inactive ? ' · inactive' : '') + '</div>' : '')
                    + '</a>';
            });
            panel.innerHTML = html;
        }
        rows = Array.prototype.slice.call(panel.querySelectorAll('[data-search-row]'));
        rows.forEach(function (r, n) { r.addEventListener('mouseenter', function () { highlight(n); }); });
        panel.style.display = 'block';
        highlight(rows.length ? 0 : -1);
    }

    input.addEventListener('input', function () {
        var q = input.value.trim();
        clearTimeout(timer);
        if (q.length < 2) { close(); return; }
        timer = setTimeout(function () {
            var mine = ++seq;
            fetch('/search?q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (data) { if (mine === seq) render(data.results || [], q); })
                .catch(function () { /* offline or session expired — ignore */ });
        }, 150);
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown' && rows.length) { e.preventDefault(); highlight(Math.min(active + 1, rows.length - 1)); }
        else if (e.key === 'ArrowUp' && rows.length) { e.preventDefault(); highlight(Math.max(active - 1, 0)); }
        else if (e.key === 'Enter' && rows[active]) { e.preventDefault(); window.location = rows[active].href; }
        else if (e.key === 'Escape') { close(); input.blur(); }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key !== '/' || e.metaKey || e.ctrlKey || e.altKey) return;
        var t = e.target;
        if (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
        e.preventDefault();
        // The sidebar is an off-canvas drawer on small screens; open it first.
        var sidebar = document.getElementById('sidebar');
        var toggle  = document.getElementById('nav-toggle');
        if (toggle && toggle.offsetParent !== null && sidebar && !sidebar.classList.contains('open')) toggle.click();
        input.focus();
        input.select();
    });

    document.addEventListener('click', function (e) {
        if (!panel.contains(e.target) && e.target !== input) close();
    });
})();
