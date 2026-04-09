<div class="max-w-lg space-y-4">

    <h1 class="text-xl font-semibold text-slate-800"><?= e($expense['name']) ?></h1>

    <div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 text-sm text-blue-800">
        This expense is synced from FreeAgent and cannot be edited here.
        Changes in FreeAgent will be reflected on the next sync.
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <dl class="divide-y divide-slate-100">
            <?php
            $fields = [
                'Category' => $categories[$expense['category']] ?? $expense['category'],
                'Amount'   => money($expense['amount']),
                'Date'     => formatDate($expense['date']),
                'Notes'    => $expense['notes'],
            ];
            foreach ($fields as $label => $value): ?>
                <div class="px-5 py-3 flex gap-4">
                    <dt class="text-sm text-slate-500 w-28 shrink-0"><?= $label ?></dt>
                    <dd class="text-sm text-slate-800"><?= e($value) ?: '<span class="text-slate-300">—</span>' ?></dd>
                </div>
            <?php endforeach ?>
            <div class="px-5 py-3 flex gap-4 items-center">
                <dt class="text-sm text-slate-500 w-28 shrink-0">Client</dt>
                <dd class="text-sm text-slate-800" id="readonly-exp-client">
                    <?php if ($expense['client_id']): ?>
                        <a href="/clients/<?= $expense['client_id'] ?>" class="text-accent-600 hover:underline">
                            <?php
                            $clientName = '';
                            foreach ($clients as $c) { if ($c['id'] == $expense['client_id']) { $clientName = $c['name']; break; } }
                            echo e($clientName ?: 'Client #' . $expense['client_id']);
                            ?>
                        </a>
                    <?php else: ?>
                        <select onchange="assignReadonlyExpenseClient(<?= $expense['id'] ?>, this)"
                                class="border border-slate-200 rounded px-2 py-0.5 text-xs text-slate-500 focus:outline-none focus:ring-1 focus:ring-accent-400">
                            <option value="">— Assign client —</option>
                            <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                            <?php endforeach ?>
                        </select>
                    <?php endif ?>
                </dd>
            </div>
        </dl>
    </div>

    <div id="ignore-toggle-wrap" class="flex items-center gap-2 py-2 px-3 rounded border <?= $expense['ignore_from_stats'] ? 'bg-amber-50 border-amber-200' : 'bg-slate-50 border-slate-200' ?>">
        <span id="ignore-indicator" class="text-sm <?= $expense['ignore_from_stats'] ? 'text-amber-800 font-medium' : 'text-slate-500' ?>">
            <?= $expense['ignore_from_stats'] ? 'Excluded from stats and breakdowns' : 'Included in stats and breakdowns' ?>
        </span>
        <button onclick="toggleIgnoreExpense(<?= $expense['id'] ?>)"
                class="ml-auto text-xs px-2 py-1 rounded border <?= $expense['ignore_from_stats'] ? 'border-amber-300 text-amber-700 hover:bg-amber-100' : 'border-slate-300 text-slate-600 hover:bg-slate-100' ?>"
                id="ignore-btn">
            <?= $expense['ignore_from_stats'] ? 'Re-include' : 'Exclude from stats' ?>
        </button>
    </div>

    <div class="flex items-center gap-4">
        <a href="/expenses" class="text-sm text-slate-500 hover:text-slate-800">← Back to Expenses</a>
        <a href="/expenses/recurring/create?from_expense=<?= $expense['id'] ?>" class="text-sm text-accent-600 hover:underline">→ Convert to Recurring Cost</a>
    </div>

</div>

<script>
function toggleIgnoreExpense(id) {
    fetch('/expenses/' + id + '/toggle-ignore', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const ignored = data.ignore_from_stats === 1;
            const wrap = document.getElementById('ignore-toggle-wrap');
            const indicator = document.getElementById('ignore-indicator');
            const btn = document.getElementById('ignore-btn');
            wrap.className = 'flex items-center gap-2 py-2 px-3 rounded border ' + (ignored ? 'bg-amber-50 border-amber-200' : 'bg-slate-50 border-slate-200');
            indicator.className = 'text-sm ' + (ignored ? 'text-amber-800 font-medium' : 'text-slate-500');
            indicator.textContent = ignored ? 'Excluded from stats and breakdowns' : 'Included in stats and breakdowns';
            btn.className = 'ml-auto text-xs px-2 py-1 rounded border ' + (ignored ? 'border-amber-300 text-amber-700 hover:bg-amber-100' : 'border-slate-300 text-slate-600 hover:bg-slate-100');
            btn.textContent = ignored ? 'Re-include' : 'Exclude from stats';
        });
}

function assignReadonlyExpenseClient(expenseId, select) {
    const clientId = select.value;
    if (!clientId) return;
    const cell = document.getElementById('readonly-exp-client');
    fetch('/expenses/' + expenseId + '/client', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'client_id=' + encodeURIComponent(clientId),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            cell.innerHTML = '<a href="/clients/' + clientId + '" class="text-accent-600 hover:underline">' + data.client_name + '</a>';
        }
    })
    .catch(() => select.value = '');
}
</script>
