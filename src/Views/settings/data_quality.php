<div class="max-w-3xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Data Quality</h1>
        <span class="px-3 py-1 rounded-full text-sm font-medium <?= $totalIssues === 0 ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
            <?= $totalIssues === 0 ? 'All clear' : $totalIssues . ' issue' . ($totalIssues === 1 ? '' : 's') ?>
        </span>
    </div>
    <p class="text-sm text-slate-500">Checks for missing links and incomplete records that skew the P&amp;L or hide renewals. Assign clients inline where offered; everything else links to where it's fixed.</p>

    <?php foreach ($checks as $check): ?>
        <?php $count = count($check['rows']); ?>
        <div class="bg-white border <?= $count ? 'border-amber-200' : 'border-slate-200' ?> rounded-lg overflow-hidden">
            <div class="px-5 py-3 flex items-center justify-between gap-3 <?= $count ? 'bg-amber-50/50' : '' ?>">
                <div>
                    <h2 class="text-sm font-semibold text-slate-700"><?= e($check['title']) ?></h2>
                    <p class="text-xs text-slate-500 mt-0.5"><?= e($check['description']) ?></p>
                </div>
                <span class="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium <?= $count ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700' ?>">
                    <?= $count ?: '✓' ?>
                </span>
            </div>
            <?php if ($check['error']): ?>
                <p class="px-5 py-2 text-xs text-slate-400 border-t border-slate-100">Check unavailable: <?= e($check['error']) ?></p>
            <?php elseif ($count): ?>
                <ul class="border-t border-slate-100 divide-y divide-slate-50 max-h-64 overflow-y-auto">
                    <?php foreach ($check['rows'] as $row): ?>
                        <li class="px-5 py-2 text-sm flex flex-wrap items-center justify-between gap-2" data-dq-row
                            data-fix="<?= e($check['fix'] ?? '') ?>" data-id="<?= (int)$row['id'] ?>">
                            <a href="<?= e($row['url']) ?>" class="text-accent-600 hover:underline"><?= e($row['label']) ?></a>
                            <?php if (!empty($check['fix'])): ?>
                                <span class="flex items-center gap-1.5 text-xs">
                                    <?php if (!empty($row['suggest_id'])): ?>
                                        <button type="button" data-dq-assign="<?= (int)$row['suggest_id'] ?>"
                                                class="px-2 py-1 border border-slate-300 text-accent-700 rounded hover:bg-accent-50">Use <?= e($row['suggest_name']) ?></button>
                                    <?php endif ?>
                                    <select data-dq-client class="border border-slate-300 rounded px-1.5 py-1 text-xs max-w-[12rem]">
                                        <option value="">Assign client…</option>
                                    </select>
                                </span>
                            <?php endif ?>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    <?php endforeach ?>
</div>

<!-- Client options rendered once; each picker is filled on first use -->
<template id="dq-client-options">
    <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
    <?php endforeach ?>
</template>

<script>
// Inline fixes: a suggestion button or the client picker assigns immediately.
(function () {
    function assign(row, clientId) {
        const token = document.querySelector('meta[name="csrf-token"]').content;
        row.style.opacity = '.5';
        fetch('/settings/data-quality/fix', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': token, 'Accept': 'application/json' },
            body: new URLSearchParams({ action: row.dataset.fix, id: row.dataset.id, client_id: clientId })
        })
        .then(r => r.json().then(j => { if (!r.ok || j.error) throw new Error(j.error || 'Failed'); return j; }))
        .then(() => {
            const list = row.parentElement;
            row.remove();
            const badge = list.closest('.rounded-lg').querySelector('.rounded-full');
            if (badge) badge.textContent = list.children.length || '✓';
        })
        .catch(err => { row.style.opacity = ''; alert(err.message); });
    }
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-dq-assign]');
        if (btn) assign(btn.closest('[data-dq-row]'), btn.dataset.dqAssign);
    });
    const fill = e => {
        const sel = e.target.closest && e.target.closest('[data-dq-client]');
        if (sel && sel.options.length === 1) sel.append(document.getElementById('dq-client-options').content.cloneNode(true));
    };
    document.addEventListener('focusin', fill);
    document.addEventListener('mousedown', fill);
    document.addEventListener('change', e => {
        if (e.target.matches('[data-dq-client]') && e.target.value) assign(e.target.closest('[data-dq-row]'), e.target.value);
    });
})();
</script>
