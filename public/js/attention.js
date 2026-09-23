// Snooze / dismiss / restore / mark-renewed buttons on the Today list, the
// dashboard attention block and /renewals. Wired by data attributes so the
// markup stays free of inline handlers. Classes here aren't in Tailwind's
// scan path, so styling is inline.
(function () {
    'use strict';

    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token(), 'Accept': 'application/json' },
            body: new URLSearchParams(data)
        }).then(function (res) {
            return res.json().catch(function () { return { error: 'Unexpected response (' + res.status + ')' }; })
                .then(function (json) {
                    if (!res.ok || json.error) throw new Error(json.error || 'Request failed');
                    return json;
                });
        });
    }

    function toast(message, isError) {
        var el = document.createElement('div');
        el.textContent = message;
        el.setAttribute('role', 'status');
        el.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:60;max-width:360px;padding:10px 14px;'
            + 'border-radius:6px;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,.15);color:#fff;'
            + 'background:' + (isError ? '#b91c1c' : '#1e293b');
        document.body.appendChild(el);
        setTimeout(function () { el.remove(); }, 3500);
    }

    function adjustBadge(severity) {
        if (severity !== 'high' && severity !== 'medium') return;
        var badge = document.getElementById('attention-badge');
        if (!badge) return;
        var n = Math.max(0, (parseInt(badge.textContent, 10) || 0) - 1);
        badge.textContent = String(n);
        if (n === 0) badge.style.display = 'none';
    }

    function removeRow(row) {
        row.style.transition = 'opacity .2s';
        row.style.opacity = '0';
        setTimeout(function () {
            var group = row.closest('[data-attention-group]');
            row.remove();
            if (!group) return;
            var left = group.querySelectorAll('[data-attention-row]').length;
            var count = group.querySelector('[data-attention-count]');
            if (count) count.textContent = String(left);
            if (left === 0) group.style.display = 'none';
        }, 200);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-attention-snooze],[data-attention-unsnooze],[data-attention-renew]');
        if (!btn) return;
        e.preventDefault();
        var row = btn.closest('[data-attention-row]');
        var req;

        if (btn.hasAttribute('data-attention-renew')) {
            if (!confirm('Mark as renewed? The renewal date rolls forward one term.')) return;
            req = post('/renewals/renew', { type: btn.dataset.type, id: btn.dataset.id });
        } else if (btn.hasAttribute('data-attention-unsnooze')) {
            req = post('/attention/unsnooze', { key: row.dataset.key });
        } else {
            req = post('/attention/snooze', { key: row.dataset.key, days: btn.dataset.attentionSnooze });
        }

        btn.disabled = true;
        req.then(function (res) {
            toast(res.message || 'Done');
            if (row) {
                adjustBadge(row.dataset.severity);
                removeRow(row);
            }
        }).catch(function (err) {
            btn.disabled = false;
            toast(err.message, true);
        });
    });
})();
