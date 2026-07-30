<?php
/**
 * Client cell for the invoice / recurring-invoice tables.
 *
 * An assigned row stays a plain link, with a pencil that swaps in the picker —
 * previously the dropdown only rendered when client_id was NULL, so a row
 * assigned to the wrong client (e.g. two FreeAgent contacts sharing an email)
 * could not be corrected from the UI at all.
 */
function faClientCell(string $type, int $id, ?int $clientId, ?string $clientName, array $allClients): string
{
    $prefix = $type === 'invoices' ? 'inv' : 'ri';
    $editId = "fa-{$prefix}-edit-{$id}";

    $select = '<select onchange="assignFaClient(' . json_encode($type) . ', ' . $id . ', this)"'
        . ' class="border border-slate-200 rounded px-2 py-0.5 text-xs text-slate-500 focus:outline-none focus:ring-1 focus:ring-accent-400">'
        . '<option value="">— Unassigned —</option>';
    foreach ($allClients as $c) {
        $sel = $clientId === (int)$c['id'] ? ' selected' : '';
        $select .= '<option value="' . (int)$c['id'] . '"' . $sel . '>' . e($c['name']) . '</option>';
    }
    $select .= '</select>';

    if (!$clientId) {
        return $select;
    }

    return '<div class="flex items-center gap-1">'
         . '<a href="/clients/' . $clientId . '" class="text-accent-600 hover:underline">' . e($clientName ?? 'Unknown') . '</a>'
         . '<button type="button" onclick="toggleFaClientEdit(' . json_encode($editId) . ')"'
         . ' class="text-slate-300 hover:text-slate-500 shrink-0" title="Change client">'
         . '<svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">'
         . '<path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>'
         . '</svg></button></div>'
         . '<div id="' . $editId . '" class="hidden mt-1">' . $select . '</div>';
}
?>
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
            ['Total Invoiced',     money($totalInvoiced),     'text-slate-800', 'All time · excl. drafts'],
            ['Invoiced This Year', money($thisYearInvoiced),  'text-slate-800', date('Y') . ' · excl. drafts'],
            ['Total Expenses',     money($totalExpenses),     'text-slate-800', 'Bank transactions'],
        ];
        foreach ($cards as [$label, $value, $color, $sub]): ?>
            <div class="bg-white border border-slate-200 rounded-lg p-4">
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide"><?= $label ?></p>
                <p class="mt-1 text-xl font-semibold <?= $color ?>"><?= $value ?></p>
                <p class="text-xs text-slate-400 mt-0.5"><?= $sub ?></p>
            </div>
        <?php endforeach ?>

        <!-- Unpaid, broken down into sent vs overdue -->
        <div class="bg-white border border-slate-200 rounded-lg p-4">
            <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Unpaid Invoices</p>
            <p class="mt-1 text-xl font-semibold <?= $unpaidInvoiced > 0 ? 'text-amber-600' : 'text-slate-800' ?>">
                <?= money($unpaidInvoiced) ?>
            </p>
            <?php if ($unpaidCount > 0): ?>
                <div class="mt-1.5 space-y-0.5 text-xs">
                    <a href="#outstanding" onclick="filterUnpaid('sent')" class="flex items-center justify-between gap-2 hover:bg-slate-50 rounded px-1 -mx-1 py-0.5">
                        <span class="flex items-center gap-1.5 text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-sm bg-blue-400"></span>Sent
                        </span>
                        <span class="tabular-nums text-slate-700 font-medium">
                            <?= money($sentInvoiced) ?> <span class="text-slate-400 font-normal">(<?= $sentCount ?>)</span>
                        </span>
                    </a>
                    <a href="#outstanding" onclick="filterUnpaid('overdue')" class="flex items-center justify-between gap-2 hover:bg-slate-50 rounded px-1 -mx-1 py-0.5">
                        <span class="flex items-center gap-1.5 text-slate-500">
                            <span class="inline-block w-2 h-2 rounded-sm bg-red-400"></span>Overdue
                        </span>
                        <span class="tabular-nums <?= $overdueInvoiced > 0 ? 'text-red-600' : 'text-slate-700' ?> font-medium">
                            <?= money($overdueInvoiced) ?> <span class="text-slate-400 font-normal">(<?= $overdueCount ?>)</span>
                        </span>
                    </a>
                </div>
            <?php else: ?>
                <p class="text-xs text-slate-400 mt-0.5">Nothing outstanding</p>
            <?php endif ?>
        </div>
    </div>

    <!-- Outstanding invoices -->
    <?php if ($unpaidInvoices): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden" id="outstanding">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between gap-4 flex-wrap">
            <div class="flex items-center gap-4">
                <h2 class="text-sm font-semibold text-slate-700">Outstanding Invoices</h2>
                <span class="text-xs text-slate-400" id="unpaid-summary"></span>
            </div>
            <div class="flex gap-1 text-xs">
                <?php foreach ([
                    'all'     => 'All (' . $unpaidCount . ')',
                    'sent'    => 'Sent (' . $sentCount . ')',
                    'overdue' => 'Overdue (' . $overdueCount . ')',
                ] as $val => $label): ?>
                    <button type="button" data-unpaid-filter="<?= $val ?>" onclick="filterUnpaid('<?= $val ?>')"
                            class="unpaid-filter-btn px-2 py-1 rounded bg-slate-100 text-slate-600 hover:bg-slate-200">
                        <?= $label ?>
                    </button>
                <?php endforeach ?>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Client</th>
                    <th class="px-4 py-2.5 text-left">Reference</th>
                    <th class="px-4 py-2.5 text-right">Amount</th>
                    <th class="px-4 py-2.5 text-center">Status</th>
                    <th class="px-4 py-2.5 text-left">Due</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100" id="unpaid-tbody">
                <?php
                $today = new DateTimeImmutable('today');
                foreach ($unpaidInvoices as $inv):
                    $eff = $inv['eff_status'];
                    $due = $inv['due_date'] ?: null;
                    $daysLate = null;
                    if ($due) {
                        try {
                            $daysLate = (int)$today->diff(new DateTimeImmutable($due))->format('%r%a');
                        } catch (\Throwable) {}
                    }
                ?>
                    <tr class="hover:bg-slate-50 unpaid-row" data-status="<?= e($eff) ?>" data-amount="<?= (float)$inv['total_value'] ?>">
                        <td class="px-4 py-2.5">
                            <?php if ($inv['client_id']): ?>
                                <a href="/clients/<?= $inv['client_id'] ?>" class="text-accent-600 hover:underline"><?= e($inv['client_name'] ?? '—') ?></a>
                            <?php else: ?>
                                <span class="text-amber-600 text-xs font-medium">Unassigned</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs">
                            <?= freeagentLink($inv['freeagent_url'] ?? null, $inv['reference'] ?: '—') ?>
                            <?php if (!empty($inv['status_override'])): ?>
                                <span class="ml-1 inline-block px-1 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-500"
                                      title="<?= e($inv['status_override_note'] ?? 'Manual status override') ?>">Override</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums font-medium"><?= money($inv['total_value']) ?></td>
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $eff === 'overdue' ? 'bg-red-100 text-red-700' : 'bg-blue-100 text-blue-700' ?>">
                                <?= ucfirst($eff) ?>
                            </span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500 text-xs">
                            <?= $due ? formatDate($due) : '—' ?>
                            <?php if ($daysLate !== null && $daysLate < 0): ?>
                                <span class="text-red-600 font-medium">· <?= abs($daysLate) ?>d late</span>
                            <?php elseif ($daysLate !== null): ?>
                                <span class="text-slate-400">· in <?= $daysLate ?>d</span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif ?>

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
                            <?= faClientCell('recurring', (int)$ri['id'], $ri['client_id'] ? (int)$ri['client_id'] : null, $ri['client_name'] ?? null, $allClients) ?>
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

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Invoice value by status -->
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-700">Invoice Value by Status</h2>
            </div>
            <div class="p-5">
                <div class="h-56"><canvas id="status-chart"></canvas></div>
            </div>
            <script>
            (function() {
                <?php
                $statusOrder  = ['paid' => '#22c55e', 'sent' => '#3b82f6', 'overdue' => '#ef4444', 'draft' => '#94a3b8'];
                $statusSlices = [];
                foreach ($statusOrder as $key => $colour) {
                    $val = $byStatus[$key]['total'] ?? 0;
                    if ($val > 0) {
                        $statusSlices[] = ['label' => ucfirst($key), 'value' => round((float)$val, 2), 'color' => $colour];
                    }
                }
                // Anything FreeAgent returns outside the four we know about.
                foreach ($byStatus as $key => $info) {
                    if (isset($statusOrder[$key]) || $info['total'] <= 0) continue;
                    $statusSlices[] = ['label' => ucfirst((string)$key), 'value' => round((float)$info['total'], 2)];
                }
                ?>
                const slices = <?= json_encode($statusSlices) ?>;

                document.addEventListener('DOMContentLoaded', function() {
                    const C = window.CrmCharts;
                    const canvas = document.getElementById('status-chart');
                    if (!canvas || !C) return;
                    if (!slices.length) { C.empty(canvas, 'No invoices synced yet.'); return; }
                    C.doughnut(canvas, slices, { legendPosition: 'bottom' });
                });
            })();
            </script>
        </div>

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
                    $effStatus = $inv['status_override'] ?? $inv['status'];
                    $statusColor = match($effStatus) {
                        'paid'    => 'bg-green-100 text-green-700',
                        'overdue' => 'bg-red-100 text-red-700',
                        'sent'    => 'bg-blue-100 text-blue-700',
                        default   => 'bg-slate-100 text-slate-600',
                    };
                    $isHiveage = isset($inv['source']) && $inv['source'] === 'hiveage';
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5" id="fa-inv-client-<?= $inv['id'] ?>">
                            <?= faClientCell('invoices', (int)$inv['id'], $inv['client_id'] ? (int)$inv['client_id'] : null, $inv['client_name'] ?? null, $allClients) ?>
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
                                <?= ucfirst($effStatus ?? 'unknown') ?>
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

// ── Outstanding invoices filter (sent / overdue) ──────────────────────────
function filterUnpaid(status) {
    const rows = document.querySelectorAll('.unpaid-row');
    if (!rows.length) return;

    let count = 0, total = 0;
    rows.forEach(r => {
        const show = status === 'all' || r.dataset.status === status;
        r.classList.toggle('hidden', !show);
        if (show) { count++; total += parseFloat(r.dataset.amount) || 0; }
    });

    document.querySelectorAll('.unpaid-filter-btn').forEach(b => {
        const active = b.dataset.unpaidFilter === status;
        b.classList.toggle('bg-accent-600', active);
        b.classList.toggle('text-white', active);
        b.classList.toggle('bg-slate-100', !active);
        b.classList.toggle('text-slate-600', !active);
    });

    const summary = document.getElementById('unpaid-summary');
    if (summary) {
        summary.textContent = count + ' invoice' + (count !== 1 ? 's' : '') + ' · £'
            + total.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
}
document.addEventListener('DOMContentLoaded', () => filterUnpaid('all'));

// ── Inline client assignment (invoices + recurring) ───────────────────────
function toggleFaClientEdit(id) {
    document.getElementById(id)?.classList.toggle('hidden');
}

function assignFaClient(type, id, select) {
    const clientId = select.value;
    const cell = document.getElementById('fa-' + (type === 'invoices' ? 'inv' : 'ri') + '-client-' + id);
    const url  = type === 'invoices' ? '/freeagent/invoices/' + id + '/client'
                                     : '/freeagent/recurring/' + id + '/client';
    const previous = select.dataset.previous ?? '';

    fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'client_id=' + encodeURIComponent(clientId),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) throw new Error(data.error || 'failed');
        select.dataset.previous = clientId;
        if (clientId) {
            const esc = document.createElement('div');
            esc.textContent = data.client_name || 'Unknown';
            cell.innerHTML = '<a href="/clients/' + encodeURIComponent(clientId) + '" '
                + 'class="text-accent-600 hover:underline">' + esc.innerHTML + '</a>';
        } else {
            cell.innerHTML = '<span class="text-amber-600 text-xs font-medium">Unassigned</span>';
        }
    })
    .catch(() => { select.value = previous; alert('Could not reassign — please try again.'); });
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
