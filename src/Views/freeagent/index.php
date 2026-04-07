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
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5">
                            <?php if ($inv['client_id']): ?>
                                <a href="/clients/<?= $inv['client_id'] ?>" class="text-accent-600 hover:underline"><?= e($inv['client_name'] ?? '—') ?></a>
                            <?php else: ?>
                                <span class="text-slate-400">Unmatched</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs"><?= e($inv['reference'] ?: '—') ?></td>
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
                        <td class="px-4 py-2.5"><?= e($tx['description'] ?: '—') ?></td>
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
