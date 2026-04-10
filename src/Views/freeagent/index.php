<?php if (!$connected): ?>

<div class="max-w-lg space-y-4">
    <h1 class="text-xl font-semibold text-slate-800">FreeAgent</h1>
    <div class="bg-white border border-slate-200 rounded-lg p-8 text-center space-y-3">
        <p class="text-sm text-slate-500">FreeAgent is not connected.</p>
        <a href="/settings/freeagent" class="inline-block px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            Connect FreeAgent in Settings →
        </a>
    </div>
</div>

<?php return; endif ?>

<div class="space-y-6">

    <!-- Header -->
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">FreeAgent</h1>
        <div class="flex items-center gap-3">
            <?php if ($lastSync): ?>
                <span class="text-xs text-slate-400">
                    Last synced <?= formatDate($lastSync['completed_at']) ?>
                    · <?= number_format($lastSync['records_synced']) ?> records
                </span>
            <?php endif ?>
            <button id="sync-btn" onclick="runSync()"
                    class="px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700 flex items-center gap-2">
                <span id="sync-label">Sync Now</span>
                <svg id="sync-spinner" class="hidden w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Last sync error -->
    <?php if ($lastError && (!$lastSync || $lastError['started_at'] > $lastSync['completed_at'])): ?>
        <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-800">
            <strong>Last sync failed</strong>
            (<?= formatDate($lastError['started_at']) ?>):
            <?= e($lastError['error_message']) ?>
        </div>
    <?php endif ?>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <?php
        $cards = [
            ['Total Invoiced',     money($totalInvoiced),     'text-slate-800', 'All time'],
            ['Invoiced This Year', money($thisYearInvoiced),  'text-slate-800', date('Y')],
            ['Total Expenses',     money($totalExpenses),     'text-slate-800', 'Bank transactions'],
            ['Unpaid Invoices',    money($unpaidInvoiced),    $unpaidInvoiced > 0 ? 'text-amber-600' : 'text-slate-800', 'Sent + overdue'],
        ];
        foreach ($cards as [$label, $value, $color, $sub]): ?>
            <div class="bg-white border border-slate-200 rounded-lg p-4">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide"><?= $label ?></p>
                <p class="mt-1 text-xl font-semibold <?= $color ?>"><?= $value ?></p>
                <p class="text-xs text-slate-400 mt-0.5"><?= $sub ?></p>
            </div>
        <?php endforeach ?>
    </div>

    <!-- Recurring Income -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                <h2 class="text-sm font-semibold text-slate-700">Recurring Income</h2>
                <span class="text-sm text-slate-800 font-medium"><?= money($confirmedMrr) ?> <span class="text-xs font-normal text-slate-400">confirmed monthly recurring</span></span>
                <?php if ($pipelineMrr > 0): ?>
                    <span class="text-sm text-amber-600 font-medium"><?= money($pipelineMrr) ?> <span class="text-xs font-normal text-amber-500">pipeline monthly recurring</span></span>
                    <span class="text-xs text-slate-400"><?= money(($confirmedMrr + $pipelineMrr) * 12) ?> / yr (all)</span>
                <?php else: ?>
                    <span class="text-xs text-slate-400"><?= money($confirmedMrr * 12) ?> / yr</span>
                <?php endif ?>
            </div>
            <div class="flex gap-2 shrink-0">
                <button onclick="filterRecurring('all')" id="rfil-all"
                        class="px-2.5 py-1 rounded text-xs font-medium bg-slate-100 text-slate-700 hover:bg-slate-200">All</button>
                <button onclick="filterRecurring('Active')" id="rfil-Active"
                        class="px-2.5 py-1 rounded text-xs font-medium text-slate-500 hover:bg-slate-100">Active</button>
                <button onclick="filterRecurring('Draft')" id="rfil-Draft"
                        class="px-2.5 py-1 rounded text-xs font-medium text-slate-500 hover:bg-slate-100">Draft</button>
            </div>
        </div>
        <?php if ($allRecurring): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm" id="recurring-table">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left cursor-pointer hover:text-slate-800" onclick="sortRecurring(0)">Client</th>
                    <th class="px-4 py-2.5 text-left cursor-pointer hover:text-slate-800" onclick="sortRecurring(1)">Reference</th>
                    <th class="px-4 py-2.5 text-left cursor-pointer hover:text-slate-800" onclick="sortRecurring(2)">Frequency</th>
                    <th class="px-4 py-2.5 text-right cursor-pointer hover:text-slate-800" onclick="sortRecurring(3)">Total Value</th>
                    <th class="px-4 py-2.5 text-right cursor-pointer hover:text-slate-800" onclick="sortRecurring(4)">Monthly Equiv.</th>
                    <th class="px-4 py-2.5 text-center cursor-pointer hover:text-slate-800" onclick="sortRecurring(5)">Status</th>
                    <th class="px-4 py-2.5 text-left cursor-pointer hover:text-slate-800" onclick="sortRecurring(6)">Next Invoice</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($allRecurring as $ri):
                    $isActive = $ri['recurring_status'] === 'Active';
                    $statusBadge = $isActive ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700';
                ?>
                    <tr class="hover:bg-slate-50 ri-row" data-status="<?= e($ri['recurring_status']) ?>">
                        <td class="px-4 py-2.5 font-medium" data-value="<?= e($ri['client_name'] ?? '') ?>" id="fa-ri-client-<?= $ri['id'] ?>">
                            <?php if ($ri['client_id']): ?>
                                <a href="/clients/<?= $ri['client_id'] ?>" class="text-accent-600 hover:underline"><?= e($ri['client_name'] ?? 'Unknown') ?></a>
                            <?php else: ?>
                                <select onchange="assignFaClient('recurring', <?= $ri['id'] ?>, this)"
                                        class="border border-slate-200 rounded px-2 py-0.5 text-xs text-slate-500 focus:outline-none focus:ring-1 focus:ring-accent-400">
                                    <option value="">— Assign client —</option>
                                    <?php foreach ($allClients as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                                    <?php endforeach ?>
                                </select>
                            <?php endif ?>
                        </td>
                        <?php
                            // FreeAgent's `reference` on a recurring template is a bank payment
                            // reference code (e.g. "3346"), not a unique display identifier.
                            // Use the numeric ID from the API URL instead, which is unique and
                            // matches the link target. Show the bank ref as a tooltip if set.
                            preg_match('|/(\d+)$|', $ri['freeagent_url'] ?? '', $_riIdM);
                            $riDisplayRef = isset($_riIdM[1]) ? 'RI-' . $_riIdM[1] : ($ri['reference'] ?: '—');
                            $riTitle      = ($ri['reference'] && $ri['reference'] !== $riDisplayRef)
                                ? ' title="Bank ref: ' . e($ri['reference']) . '"' : '';
                        ?>
                        <td class="px-4 py-2.5 font-mono text-xs" data-value="<?= e($ri['reference'] ?? '') ?>">
                            <span<?= $riTitle ?>><?= freeagentLink($ri['freeagent_url'] ?? null, $riDisplayRef) ?></span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500" data-value="<?= e($ri['frequency']) ?>"><?= e($ri['frequency']) ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums font-medium" data-value="<?= (float)$ri['total_value'] ?>"><?= money($ri['total_value']) ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-600" data-value="<?= round((float)$ri['monthly_value'], 4) ?>"><?= money($ri['monthly_value']) ?></td>
                        <td class="px-4 py-2.5 text-center" data-value="<?= e($ri['recurring_status']) ?>">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $statusBadge ?>">
                                <?= e($ri['recurring_status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500" data-value="<?= e($ri['next_recurs_on'] ?? '') ?>"><?= formatDate($ri['next_recurs_on']) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="px-5 py-6 text-sm text-slate-400">No recurring invoices synced yet. Run a sync to pull them from FreeAgent.</p>
        <?php endif ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

        <!-- Income by Category -->
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-700">Income by Category</h2>
            </div>
            <?php if ($byCategory): ?>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Category</th>
                        <th class="px-4 py-2 text-right">Invoices</th>
                        <th class="px-4 py-2 text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($byCategory as $row): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-mono text-xs"><?= e($row['category']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= $row['invoice_count'] ?></td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium"><?= money($row['total']) ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            <?php else: ?>
                <p class="px-5 py-6 text-sm text-slate-400">No invoice data yet.</p>
            <?php endif ?>
        </div>

        <!-- Sync History -->
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-700">Sync History</h2>
            </div>
            <?php if ($syncHistory): ?>
            <ul class="divide-y divide-slate-100">
                <?php foreach ($syncHistory as $log): ?>
                    <li class="px-5 py-2.5 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-2">
                            <span class="inline-block w-1.5 h-1.5 rounded-full <?= $log['status'] === 'completed' ? 'bg-green-400' : ($log['status'] === 'failed' ? 'bg-red-400' : 'bg-amber-400') ?>"></span>
                            <span class="text-slate-600 font-medium"><?= ucfirst($log['sync_type']) ?></span>
                            <?php if ($log['error_message']): ?>
                                <span class="text-red-500 truncate max-w-[140px]" title="<?= e($log['error_message']) ?>">
                                    <?= e(substr($log['error_message'], 0, 40)) ?>…
                                </span>
                            <?php else: ?>
                                <span class="text-slate-400"><?= number_format((int)$log['records_synced']) ?> records</span>
                            <?php endif ?>
                        </div>
                        <span class="text-slate-400 shrink-0"><?= formatDate($log['started_at']) ?></span>
                    </li>
                <?php endforeach ?>
            </ul>
            <?php else: ?>
                <p class="px-5 py-6 text-sm text-slate-400">No syncs yet.</p>
            <?php endif ?>
        </div>

    </div>

    <!-- Recent Invoices -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Recent Invoices</h2>
            <span class="text-xs text-slate-400">Last 20</span>
        </div>
        <?php if ($recentInvoices): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Client</th>
                    <th class="px-4 py-2.5 text-left">Reference</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                    <th class="px-4 py-2.5 text-center">Status</th>
                    <th class="px-4 py-2.5 text-left">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($recentInvoices as $inv): ?>
                    <?php
                    $statusColor = match($inv['status']) {
                        'paid'    => 'bg-green-100 text-green-700',
                        'overdue' => 'bg-red-100 text-red-700',
                        'sent'    => 'bg-blue-100 text-blue-700',
                        default   => 'bg-slate-100 text-slate-600',
                    };
                    $isHiveage = isset($inv['source']) && $inv['source'] === 'hiveage';
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5" id="fa-inv-client-<?= $inv['id'] ?>">
                            <?php if ($inv['client_id']): ?>
                                <a href="/clients/<?= $inv['client_id'] ?>" class="text-accent-600 hover:underline"><?= e($inv['client_name'] ?? '—') ?></a>
                            <?php else: ?>
                                <select onchange="assignFaClient('invoices', <?= $inv['id'] ?>, this)"
                                        class="border border-slate-200 rounded px-2 py-0.5 text-xs text-slate-500 focus:outline-none focus:ring-1 focus:ring-accent-400">
                                    <option value="">— Assign client —</option>
                                    <?php foreach ($allClients as $c): ?>
                                        <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                                    <?php endforeach ?>
                                </select>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs">
                            <?php if ($isHiveage): ?>
                                <?= e($inv['reference'] ?: '—') ?>
                                <span class="ml-1 inline-block px-1 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-500">Hiveage</span>
                            <?php else: ?>
                                <?= freeagentLink($inv['freeagent_url'] ?? null, $inv['reference'] ?: '—') ?>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums font-medium"><?= money($inv['total_value']) ?></td>
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $statusColor ?>">
                                <?= ucfirst($inv['status'] ?? 'unknown') ?>
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500"><?= formatDate($inv['dated_on']) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="px-5 py-6 text-sm text-slate-400">No invoices synced yet.</p>
        <?php endif ?>
    </div>

    <!-- Recent Expenses -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Recent Expenses</h2>
            <span class="text-xs text-slate-400">Bank transactions out · last 20</span>
        </div>
        <?php if ($recentExpenses): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Description</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                    <th class="px-4 py-2.5 text-left">FA Category</th>
                    <th class="px-4 py-2.5 text-left">CRM Category</th>
                    <th class="px-4 py-2.5 text-left">Date</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php
                $expCats = \CoyshCRM\Models\Expense::categories();
                foreach ($recentExpenses as $tx): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5"><?= freeagentLink($tx['freeagent_url'] ?? null, $tx['description'] ?: '—') ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-red-600 font-medium"><?= money(abs($tx['gross_value'])) ?></td>
                        <td class="px-4 py-2.5 text-slate-500 font-mono text-xs"><?= e($tx['freeagent_category'] ?: '—') ?></td>
                        <td class="px-4 py-2.5 text-slate-500"><?= e($expCats[$tx['crm_category']] ?? ($tx['crm_category'] ? $tx['crm_category'] : '—')) ?></td>
                        <td class="px-4 py-2.5 text-slate-500"><?= formatDate($tx['dated_on']) ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="px-5 py-6 text-sm text-slate-400">No expense transactions synced yet.</p>
        <?php endif ?>
    </div>

</div>

<script>
// ── Recurring table: filter by status ──────────────────────────────────────
function filterRecurring(status) {
    document.querySelectorAll('.ri-row').forEach(row => {
        row.style.display = (status === 'all' || row.dataset.status === status) ? '' : 'none';
    });
    ['all','Active','Draft'].forEach(s => {
        const btn = document.getElementById('rfil-' + s);
        if (!btn) return;
        btn.className = s === status
            ? 'px-2.5 py-1 rounded text-xs font-medium bg-slate-100 text-slate-700 hover:bg-slate-200'
            : 'px-2.5 py-1 rounded text-xs font-medium text-slate-500 hover:bg-slate-100';
    });
}

// ── Recurring table: sort by column ────────────────────────────────────────
let recurringSort = { col: -1, dir: 1 };
function sortRecurring(col) {
    if (recurringSort.col === col) { recurringSort.dir *= -1; } else { recurringSort.col = col; recurringSort.dir = 1; }
    const tbody = document.querySelector('#recurring-table tbody');
    const rows  = Array.from(tbody.querySelectorAll('tr.ri-row'));
    rows.sort((a, b) => {
        const av = a.cells[col]?.dataset.value ?? '';
        const bv = b.cells[col]?.dataset.value ?? '';
        const an = parseFloat(av), bn = parseFloat(bv);
        if (!isNaN(an) && !isNaN(bn)) return (an - bn) * recurringSort.dir;
        return av.localeCompare(bv) * recurringSort.dir;
    });
    rows.forEach(r => tbody.appendChild(r));
}

// ── Inline client assignment (invoices + recurring) ───────────────────────
function assignFaClient(type, id, select) {
    const clientId = select.value;
    if (!clientId) return;
    const cell = document.getElementById('fa-' + (type === 'invoices' ? 'inv' : 'ri') + '-client-' + id);
    const url  = type === 'invoices' ? '/freeagent/invoices/' + id + '/client'
                                     : '/freeagent/recurring/' + id + '/client';
    fetch(url, {
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

function runSync() {
    const btn     = document.getElementById('sync-btn');
    const label   = document.getElementById('sync-label');
    const spinner = document.getElementById('sync-spinner');

    btn.disabled = true;
    label.textContent = 'Syncing…';
    spinner.classList.remove('hidden');

    fetch('/freeagent/sync', { method: 'POST' })
        .then(r => r.json())
        .then(data => {
            if (data.error) {
                alert('Sync error: ' + data.error);
                btn.disabled = false;
                label.textContent = 'Sync Now';
                spinner.classList.add('hidden');
            } else {
                window.location.reload();
            }
        })
        .catch(err => {
            alert('Sync failed: ' + err.message);
            btn.disabled = false;
            label.textContent = 'Sync Now';
            spinner.classList.add('hidden');
        });
}
</script>
