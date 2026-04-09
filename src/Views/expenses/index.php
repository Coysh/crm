<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Expenses</h1>
        <?php if (($tab ?? 'expenses') === 'recurring'): ?>
            <a href="/expenses/recurring/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                + Add Recurring Cost
            </a>
        <?php elseif (($tab ?? 'expenses') === 'bills'): ?>
            <a href="/freeagent" class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-slate-300 text-sm font-medium rounded hover:bg-slate-50">
                Sync FreeAgent
            </a>
        <?php else: ?>
            <a href="/expenses/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                + Add Expense
            </a>
        <?php endif ?>
    </div>

    <!-- Tabs -->
    <div class="flex items-center gap-1 border-b border-slate-200">
        <a href="/expenses" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?= ($tab ?? 'expenses') === 'expenses' ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">Expenses</a>
        <a href="/expenses?tab=recurring" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?= ($tab ?? 'expenses') === 'recurring' ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">Recurring Costs</a>
        <a href="/expenses?tab=bills" class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?= ($tab ?? 'expenses') === 'bills' ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-700' ?>">FreeAgent Bills</a>
    </div>

<?php if (($tab ?? 'expenses') === 'expenses'): ?>

    <!-- Suggested Recurring Costs banner -->
    <?php if (!empty($suggestions)): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 space-y-3">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold text-amber-800">Suggested Recurring Costs</p>
            <span class="text-xs text-amber-600"><?= count($suggestions) ?> pattern<?= count($suggestions) !== 1 ? 's' : '' ?> detected</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-xs text-amber-700 uppercase tracking-wide">
                <tr>
                    <th class="pr-4 py-1 text-left">Name</th>
                    <th class="pr-4 py-1 text-right">Amount</th>
                    <th class="pr-4 py-1 text-left">Frequency</th>
                    <th class="pr-4 py-1 text-right">Seen</th>
                    <th class="pr-4 py-1 text-left">Last</th>
                    <th class="py-1"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-amber-100">
                <?php foreach ($suggestions as $sug):
                    $createUrl = '/expenses/recurring/create?' . http_build_query(array_filter([
                        'from_expense' => $sug['expense_ids'][0] ?? null,
                    ]));
                ?>
                <tr>
                    <td class="pr-4 py-1.5 font-medium text-amber-900"><?= e($sug['name']) ?></td>
                    <td class="pr-4 py-1.5 tabular-nums text-right"><?= money($sug['amount']) ?></td>
                    <td class="pr-4 py-1.5 text-amber-700"><?= ucfirst($sug['frequency']) ?></td>
                    <td class="pr-4 py-1.5 tabular-nums text-right"><?= $sug['occurrences'] ?>×</td>
                    <td class="pr-4 py-1.5 text-xs text-amber-600"><?= formatDate($sug['last_seen']) ?></td>
                    <td class="py-1.5 text-right whitespace-nowrap">
                        <a href="<?= $createUrl ?>" class="text-xs px-2 py-1 bg-amber-600 text-white rounded hover:bg-amber-700 mr-1">Create Recurring Cost</a>
                        <form method="POST" action="/expenses/suggestions/dismiss" class="inline">
                            <input type="hidden" name="key" value="<?= e($sug['key']) ?>">
                            <button type="submit" class="text-xs text-amber-600 hover:text-amber-800">Dismiss</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif ?>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label for="category" class="block text-xs text-slate-500 mb-1">Category</label>
            <select id="category" name="category" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Categories</option>
                <?php foreach ($categories as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($category ?? null) === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="client_id" class="block text-xs text-slate-500 mb-1">Client</label>
            <select id="client_id" name="client_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($clientId ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="server_id" class="block text-xs text-slate-500 mb-1">Server</label>
            <select id="server_id" name="server_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Servers</option>
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($serverId ?? null) == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="source" class="block text-xs text-slate-500 mb-1">Source</label>
            <select id="source" name="source" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All</option>
                <option value="manual" <?= ($source ?? null) === 'manual' ? 'selected' : '' ?>>Manual only</option>
                <option value="freeagent" <?= ($source ?? null) === 'freeagent' ? 'selected' : '' ?>>FreeAgent only</option>
            </select>
        </div>
        <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">Filter</button>
        <?php if (($category ?? null) || ($clientId ?? null) || ($serverId ?? null) || ($source ?? null)): ?>
            <a href="/expenses" class="text-xs text-slate-400 hover:text-slate-700 self-center">Clear</a>
        <?php endif ?>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Name</th>
                        <th class="px-4 py-2.5 text-left">Category</th>
                        <th class="px-4 py-2.5 text-right">Amount</th>
                        <th class="px-4 py-2.5 text-left">Cycle</th>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-left">Server</th>
                        <th class="px-4 py-2.5 text-left">Date</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($expenses as $exp):
                        $isFa = ($exp['source'] ?? 'manual') === 'freeagent';
                    ?>
                        <tr class="hover:bg-slate-50 <?= $exp['ignore_from_stats'] ? 'opacity-50' : '' ?>">
                            <td class="px-4 py-2.5 font-medium">
                                <?= e($exp['name']) ?>
                                <?php if ($isFa): ?>
                                    <span class="ml-1.5 inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-600 border border-blue-100">FreeAgent</span>
                                <?php endif ?>
                                <?php if ($exp['ignore_from_stats']): ?>
                                    <span class="excluded-badge ml-1.5 inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-600 border border-amber-200">excluded</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($categories[$exp['category']] ?? $exp['category']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= money($exp['amount']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= ucfirst($exp['billing_cycle'] ?: '—') ?></td>
                            <td class="px-4 py-2.5" id="exp-client-cell-<?= $exp['id'] ?>">
                                <?php if ($exp['client_id']): ?>
                                    <a href="/clients/<?= $exp['client_id'] ?>" class="hover:text-accent-600"><?= e($exp['client_name']) ?></a>
                                <?php else: ?>
                                    <select onchange="assignExpenseClient(<?= $exp['id'] ?>, this)"
                                            class="border border-slate-200 rounded px-2 py-0.5 text-xs text-slate-500 focus:outline-none focus:ring-1 focus:ring-accent-400">
                                        <option value="">— Assign client —</option>
                                        <?php foreach ($clients as $c): ?>
                                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                                        <?php endforeach ?>
                                    </select>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($exp['server_name'] ?: '—') ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= formatDate($exp['date']) ?></td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                <a href="/expenses/recurring/create?from_expense=<?= $exp['id'] ?>" class="text-xs text-slate-400 hover:text-accent-600 mr-2" title="Convert to recurring cost">→ Recurring</a>
                                <button onclick="toggleExpenseIgnore(<?= $exp['id'] ?>, this)"
                                        data-ignored="<?= $exp['ignore_from_stats'] ? '1' : '0' ?>"
                                        class="text-xs mr-2 <?= $exp['ignore_from_stats'] ? 'text-amber-500 hover:text-amber-700' : 'text-slate-300 hover:text-amber-500' ?>"
                                        title="<?= $exp['ignore_from_stats'] ? 'Re-include in stats' : 'Exclude from stats' ?>">⊘</button>
                                <?php if (!$isFa): ?>
                                    <a href="/expenses/<?= $exp['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                    <form method="POST" action="/expenses/<?= $exp['id'] ?>/delete" class="inline">
                                        <button type="submit" onclick="return confirm('Delete this expense?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                    </form>
                                <?php else: ?>
                                    <a href="/expenses/<?= $exp['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-600">View</a>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No expenses found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

<script>
function toggleExpenseIgnore(id, btn) {
    fetch('/expenses/' + id + '/toggle-ignore', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const ignored = data.ignore_from_stats === 1;
            const row = btn.closest('tr');
            row.classList.toggle('opacity-50', ignored);
            btn.dataset.ignored = ignored ? '1' : '0';
            btn.className = 'text-xs mr-2 ' + (ignored ? 'text-amber-500 hover:text-amber-700' : 'text-slate-300 hover:text-amber-500');
            btn.title = ignored ? 'Re-include in stats' : 'Exclude from stats';
            // Update or remove the "excluded" badge
            const nameCell = row.querySelector('td:first-child');
            const badge = nameCell.querySelector('.excluded-badge');
            if (ignored && !badge) {
                const span = document.createElement('span');
                span.className = 'excluded-badge ml-1.5 inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-600 border border-amber-200';
                span.textContent = 'excluded';
                nameCell.appendChild(span);
            } else if (!ignored && badge) {
                badge.remove();
            }
        });
}

function assignExpenseClient(expenseId, select) {
    const clientId = select.value;
    if (!clientId) return;
    const cell = document.getElementById('exp-client-cell-' + expenseId);
    fetch('/expenses/' + expenseId + '/client', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'client_id=' + encodeURIComponent(clientId),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            cell.innerHTML = '<a href="/clients/' + clientId + '" class="hover:text-accent-600">' + data.client_name + '</a>';
        }
    })
    .catch(() => select.value = '');
}
</script>

<?php endif ?>

<?php if (($tab ?? 'expenses') === 'recurring'): ?>

    <!-- Recurring Costs Summary Bar -->
    <div class="flex items-center justify-between text-sm">
        <span class="text-slate-600">
            Total monthly recurring: <strong class="text-slate-800"><?= money($totalMonthly ?? 0) ?></strong>
            &nbsp;·&nbsp; Annual: <strong class="text-slate-800"><?= money(($totalMonthly ?? 0) * 12) ?></strong>
        </span>
        <a href="/expenses/categories" class="text-xs text-slate-400 hover:text-slate-700">Manage Categories</a>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <input type="hidden" name="tab" value="recurring">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Category</label>
            <select name="category_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Categories</option>
                <?php foreach ($dbCategories ?? [] as $catId => $catName): ?>
                    <option value="<?= $catId ?>" <?= ($categoryId ?? null) == $catId ? 'selected' : '' ?>><?= e($catName) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Status</label>
            <select name="status" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="active"   <?= ($status ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($status ?? 'active') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                <option value=""         <?= ($status ?? 'active') === ''         ? 'selected' : '' ?>>All</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Search</label>
            <input type="text" name="search" value="<?= e($search ?? '') ?>" placeholder="Name…"
                   class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>
        <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">Filter</button>
        <?php if (($categoryId ?? null) || ($status ?? 'active') !== 'active' || ($search ?? '')): ?>
            <a href="/expenses?tab=recurring" class="text-xs text-slate-400 hover:text-slate-700 self-center">Clear</a>
        <?php endif ?>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Name</th>
                        <th class="px-4 py-2.5 text-left">Category</th>
                        <th class="px-4 py-2.5 text-right">Amount</th>
                        <th class="px-4 py-2.5 text-left">Cycle</th>
                        <th class="px-4 py-2.5 text-right">Monthly Equiv.</th>
                        <th class="px-4 py-2.5 text-left">Assigned To</th>
                        <th class="px-4 py-2.5 text-right">Cost/Client</th>
                        <th class="px-4 py-2.5 text-left">Renewal</th>
                        <th class="px-4 py-2.5 text-center">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($recurringCosts ?? [] as $cost):
                        $assign       = $cost['assignments'];
                        $clientCount  = count($assign['clients']);
                        $siteCount    = count($assign['sites']);
                        $viaServer    = !empty($assign['via_server']);

                        if ($viaServer && $clientCount > 0) {
                            $assignText = $clientCount <= 2
                                ? implode(', ', array_column($assign['clients'], 'name')) . ' (via server)'
                                : $clientCount . ' clients (via server)';
                        } elseif ($clientCount > 0) {
                            $assignText = $clientCount <= 2
                                ? implode(', ', array_column($assign['clients'], 'name'))
                                : $clientCount . ' clients (shared)';
                        } elseif ($siteCount > 0) {
                            $assignText = $siteCount <= 2
                                ? implode(', ', array_column($assign['sites'], 'domain_label'))
                                : $siteCount . ' sites';
                        } elseif ($viaServer) {
                            $assignText = 'No clients on server yet';
                        } else {
                            $assignText = null; // will render muted
                        }

                        $divisor       = max(1, $clientCount + $siteCount);
                        $costPerClient = ($cost['monthly_equivalent_gbp'] ?? $cost['monthly_equivalent']) / $divisor;

                        // Renewal date highlight
                        $renewalClass = '';
                        if (!empty($cost['renewal_date'])) {
                            $renewalTs = strtotime($cost['renewal_date']);
                            if ($renewalTs !== false && $renewalTs <= strtotime('+30 days')) {
                                $renewalClass = 'text-amber-600 font-medium';
                            }
                        }
                    ?>
                        <tr class="hover:bg-slate-50 <?= $cost['is_active'] ? '' : 'opacity-60' ?>">
                            <td class="px-4 py-2.5 font-medium">
                                <?= e($cost['name']) ?>
                                <?php if (!empty($cost['server_id'])): ?>
                                    <span class="ml-1 inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-500">Server</span>
                                <?php elseif (!empty($cost['provider'])): ?>
                                    <span class="text-xs text-slate-400 ml-1"><?= e($cost['provider']) ?></span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($cost['category_name']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums">
                                <?php $cur = $cost['currency'] ?? 'GBP'; ?>
                                <?= formatCurrency($cost['amount'], $cur) ?>
                                <?php if ($cur !== 'GBP' && isset($fx)): ?>
                                    <br><span class="text-xs text-slate-400">≈ <?= money($fx->convertToGBP((float)$cost['amount'], $cur)) ?></span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= ucfirst($cost['billing_cycle']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= money($cost['monthly_equivalent_gbp'] ?? $cost['monthly_equivalent']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500 text-xs">
                                <?php if ($assignText !== null): ?>
                                    <?= e($assignText) ?>
                                <?php else: ?>
                                    <span class="text-slate-300">Unassigned</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">
                                <?= $assignText !== null ? money($costPerClient) : '—' ?>
                            </td>
                            <td class="px-4 py-2.5 text-xs <?= $renewalClass ?>">
                                <?= !empty($cost['renewal_date']) ? formatDate($cost['renewal_date']) : '<span class="text-slate-300">—</span>' ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <?php if ($cost['is_active']): ?>
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-500">Inactive</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-right whitespace-nowrap">
                                <a href="/expenses/recurring/<?= $cost['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/expenses/recurring/<?= $cost['id'] ?>/toggle" class="inline mr-2">
                                    <button type="submit" class="text-xs text-slate-400 hover:text-slate-700">
                                        <?= $cost['is_active'] ? 'Deactivate' : 'Activate' ?>
                                    </button>
                                </form>
                                <form method="POST" action="/expenses/recurring/<?= $cost['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this recurring cost?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($recurringCosts)): ?>
                        <tr><td colspan="10" class="px-4 py-8 text-center text-slate-400">No recurring costs found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

<?php endif ?>

<?php if (($tab ?? 'expenses') === 'bills'): ?>

    <!-- Bill filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <input type="hidden" name="tab" value="bills">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Status</label>
            <select name="bill_status" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="unreviewed" <?= ($billStatus ?? 'unreviewed') === 'unreviewed' ? 'selected' : '' ?>>Unreviewed</option>
                <option value="reviewed"   <?= ($billStatus ?? 'unreviewed') === 'reviewed'   ? 'selected' : '' ?>>Reviewed</option>
                <option value="recurring"  <?= ($billStatus ?? 'unreviewed') === 'recurring'  ? 'selected' : '' ?>>Recurring only</option>
                <option value=""           <?= ($billStatus ?? 'unreviewed') === ''           ? 'selected' : '' ?>>All</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Duplicates</label>
            <select name="show_duplicates" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="1" <?= ($showDuplicates ?? true) ? 'selected' : '' ?>>Show duplicates</option>
                <option value="0" <?= !($showDuplicates ?? true) ? 'selected' : '' ?>>Hide duplicates</option>
            </select>
        </div>
        <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">Filter</button>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Supplier</th>
                    <th class="px-4 py-2.5 text-left">Reference</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                    <th class="px-4 py-2.5 text-left">Date</th>
                    <th class="px-4 py-2.5 text-left">Status</th>
                    <th class="px-4 py-2.5 text-center">Recurring?</th>
                    <th class="px-4 py-2.5 text-center">Duplicate?</th>
                    <th class="px-4 py-2.5 text-left">Linked Cost</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($bills ?? [] as $bill): ?>
                    <tr class="hover:bg-slate-50 <?= $bill['potential_duplicate'] ? 'bg-amber-50' : '' ?>">
                        <td class="px-4 py-2.5 font-medium">
                            <?= freeagentLink($bill['freeagent_url'] ?? null, $bill['contact_name'] ?? '—') ?>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500 text-xs"><?= e($bill['reference'] ?: '—') ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums"><?= money($bill['total_value']) ?></td>
                        <td class="px-4 py-2.5 text-slate-500"><?= formatDate($bill['dated_on']) ?></td>
                        <td class="px-4 py-2.5 text-slate-500"><?= e(ucfirst($bill['status'] ?: '—')) ?></td>
                        <td class="px-4 py-2.5 text-center">
                            <?php if ($bill['is_recurring']): ?>
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Yes</span>
                            <?php else: ?>
                                <span class="text-slate-300 text-xs">No</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <?php if ($bill['potential_duplicate']): ?>
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700" title="Possible duplicate of a bank transaction">⚠️ Possible</span>
                            <?php else: ?>
                                <span class="text-slate-300 text-xs">—</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500 text-xs">
                            <?php if ($bill['linked_cost_name']): ?>
                                <a href="/expenses/recurring/<?= $bill['recurring_cost_id'] ?>/edit" class="text-accent-600 hover:underline"><?= e($bill['linked_cost_name']) ?></a>
                            <?php else: ?>
                                <span class="text-slate-300">—</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-right whitespace-nowrap">
                            <?php if (!$bill['recurring_cost_id']): ?>
                                <a href="/expenses/recurring/create?from_bill=<?= $bill['id'] ?>" class="text-xs text-accent-600 hover:underline mr-2">Create Recurring Cost</a>
                            <?php endif ?>
                            <?php if (!$bill['reviewed']): ?>
                                <form method="POST" action="/expenses/bills/<?= $bill['id'] ?>/dismiss" class="inline">
                                    <button type="submit" class="text-xs text-slate-400 hover:text-slate-700">Dismiss</button>
                                </form>
                            <?php else: ?>
                                <span class="text-xs text-slate-300">Reviewed</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
                <?php if (empty($bills)): ?>
                    <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400">
                        No bills found. <a href="/freeagent" class="text-accent-600 hover:underline">Run a FreeAgent sync</a> to import bills.
                    </td></tr>
                <?php endif ?>
            </tbody>
        </table>
        </div>
    </div>

<?php endif ?>

</div>
