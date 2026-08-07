<?php
$profitColor = $profit >= 0 ? 'text-green-600' : 'text-red-600';

function dashDiff(float $a, float $b, bool $lowerIsBetter = false): array
{
    if ($b == 0) return ['pct' => null, 'cls' => 'text-slate-400', 'dir' => '→'];
    $pct      = (($a - $b) / abs($b)) * 100;
    $improved = $lowerIsBetter ? $pct < 0 : $pct > 0;
    $cls      = $improved ? 'text-green-600' : ($pct == 0 ? 'text-slate-400' : 'text-red-600');
    $arrow    = $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '→');
    return ['pct' => abs($pct), 'cls' => $cls, 'dir' => $arrow];
}
?>

<div class="space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Dashboard</h1>
        <div class="flex gap-2 text-xs">
            <a href="/?fy=tax" class="px-2.5 py-1 rounded <?= ($fy ?? 'tax') === 'tax' ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Tax Year</a>
            <a href="/?fy=calendar" class="px-2.5 py-1 rounded <?= ($fy ?? 'tax') === 'calendar' ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Calendar Year</a>
        </div>
    </div>

    <?php if (!empty($sitesDown)): ?>
    <!-- Sites currently down -->
    <div class="bg-red-50 border border-red-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-red-200 flex items-center gap-2">
            <span class="w-2 h-2 rounded-full bg-red-500"></span>
            <h2 class="text-sm font-semibold text-red-800">
                <?= count($sitesDown) === 1 ? '1 site down' : count($sitesDown) . ' sites down' ?>
            </h2>
        </div>
        <ul class="divide-y divide-red-100">
            <?php foreach ($sitesDown as $down): ?>
                <li class="px-5 py-2.5 flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm">
                    <a href="/sites/<?= (int)$down['site_id'] ?>" class="font-mono text-xs text-red-700 hover:underline">
                        <?= e($down['domain'] ?: $down['monitor_name']) ?>
                    </a>
                    <?php if ($down['client_id']): ?>
                        <a href="/clients/<?= (int)$down['client_id'] ?>" class="text-slate-600 hover:underline"><?= e($down['client_name']) ?></a>
                    <?php else: ?>
                        <span class="text-slate-400 italic text-xs">Unassigned</span>
                    <?php endif ?>
                    <span class="text-xs text-slate-500"><?= e($down['monitor_name']) ?></span>
                    <span class="text-xs text-red-600 ml-auto">down for <?= formatDurationSince($down['status_changed_at']) ?></span>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
    <?php endif ?>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <!-- Monthly Recurring Revenue card — confirmed + pipeline -->
        <div class="bg-white border border-slate-200 rounded-lg p-4">
            <p class="text-xs text-slate-500 font-medium uppercase tracking-wide">Confirmed Monthly Recurring Rev.</p>
            <p class="mt-1 text-2xl font-semibold text-slate-800"><?= money($mrr) ?></p>
            <?php if ($pipelineMrr > 0): ?>
                <p class="mt-1 text-xs text-amber-600">+ <?= money($pipelineMrr) ?> pipeline</p>
            <?php endif ?>
        </div>
        <?php
        $cards = [
            ['label' => 'Monthly Costs',  'value' => money($totalCosts),     'color' => 'text-slate-800'],
            ['label' => 'Monthly Profit / Loss',    'value' => money($profit),         'color' => $profitColor],
            ['label' => 'Active Clients', 'value' => $activeClientCount,     'color' => 'text-slate-800'],
            ['label' => 'Servers',        'value' => $serverCount,           'color' => 'text-slate-800'],
        ];
        foreach ($cards as $card): ?>
            <div class="bg-white border border-slate-200 rounded-lg p-4">
                <p class="text-xs text-slate-500 font-medium uppercase tracking-wide"><?= e($card['label']) ?></p>
                <p class="mt-1 text-2xl font-semibold <?= $card['color'] ?>"><?= $card['value'] ?></p>
            </div>
        <?php endforeach ?>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="bg-white border border-slate-200 rounded-lg p-5">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Client Health</h2>
            <div class="h-52"><canvas id="health-chart"></canvas></div>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg p-5 lg:col-span-2">
            <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">
                Monthly Recurring Revenue by Client
            </h2>
            <div class="h-52"><canvas id="revenue-chart"></canvas></div>
        </div>
    </div>

    <script>
    (function() {
        const health = <?= json_encode([
            ['label' => 'Healthy',   'value' => (int)($healthCounts['healthy'] ?? 0)],
            ['label' => 'Attention', 'value' => (int)($healthCounts['attention'] ?? 0)],
            ['label' => 'At risk',   'value' => (int)($healthCounts['at_risk'] ?? 0)],
        ]) ?>;

        <?php
        // Top clients by MRR; everything else collapses into "Other" so the
        // bar chart stays readable however many clients there are.
        $byMrr = array_values(array_filter($clientPL, fn(array $r) => (float)($r['mrr'] ?? 0) > 0));
        usort($byMrr, fn(array $a, array $b) => (float)$b['mrr'] <=> (float)$a['mrr']);
        $topN  = array_slice($byMrr, 0, 10);
        $rest  = array_slice($byMrr, 10);
        $restTotal = array_sum(array_map(fn(array $r) => (float)$r['mrr'], $rest));
        $revRows = array_map(fn(array $r) => [
            'label'  => $r['name'],
            'value'  => round((float)$r['mrr'], 2),
            'profit' => round((float)($r['profit'] ?? 0), 2),
        ], $topN);
        if ($restTotal > 0) {
            $revRows[] = [
                'label'       => count($rest) . ' others',
                'value'       => round($restTotal, 2),
                'profit'      => null,
                'is_aggregate' => true,
            ];
        }
        ?>
        const revenue = <?= json_encode($revRows) ?>;

        document.addEventListener('DOMContentLoaded', function() {
            const C = window.CrmCharts;
            if (!C) return;

            // ── Client health doughnut ──────────────────────────────────
            const hc = document.getElementById('health-chart');
            if (hc) {
                if (!health.some(h => h.value > 0)) {
                    C.empty(hc, 'No active clients yet.');
                } else {
                    C.doughnut(hc, [
                        { label: health[0].label, value: health[0].value, color: C.colors.green },
                        { label: health[1].label, value: health[1].value, color: C.colors.amber },
                        { label: health[2].label, value: health[2].value, color: C.colors.red },
                    ], { money: false, legendPosition: 'bottom' });
                }
            }

            // ── MRR by client ───────────────────────────────────────────
            const rc = document.getElementById('revenue-chart');
            if (rc) {
                if (!revenue.length) {
                    C.empty(rc, 'No recurring revenue yet.');
                } else {
                    new Chart(rc, {
                        type: 'bar',
                        data: {
                            labels: revenue.map(r => r.label),
                            datasets: [{
                                label: 'Monthly recurring',
                                data: revenue.map(r => r.value),
                                backgroundColor: revenue.map(r =>
                                    r.is_aggregate                        ? C.colors.slate
                                  : r.profit !== null && r.profit < 0     ? C.colors.red
                                  :                                         C.colors.accent),
                                borderRadius: 2,
                            }],
                        },
                        options: {
                            indexAxis: 'y',
                            scales: {
                                x: C.moneyScale(),
                                y: C.categoryScale({ ticks: { padding: 4, autoSkip: false } }),
                            },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    callbacks: {
                                        label: ctx => ' ' + C.gbp(ctx.parsed.x) + ' / month',
                                        afterLabel: function(ctx) {
                                            const r = revenue[ctx.dataIndex];
                                            return r.profit === null ? '' : 'Profit: ' + C.gbp(r.profit);
                                        },
                                    },
                                },
                            },
                        },
                    });
                }
            }
        });
    })();
    </script>

    <!-- Client P&L Table -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Per-Client Profit / Loss (Monthly)</h2>
            <span class="text-xs text-slate-400">Click column headers to sort</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="pl-table">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left cursor-pointer select-none hover:text-slate-800" data-col="0">Client</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="1">Monthly Recurring Rev.</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="2">Recurring</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="3">Direct Expenses</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="4">Profit</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="5">Margin</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($clientPL as $row): ?>
                        <?php
                        $rowProfit = (float)$row['profit'];
                        $margin    = (float)$row['margin'];
                        $pColor    = $rowProfit >= 0 ? 'text-green-600' : 'text-red-600';
                        $mColor    = $margin >= 0    ? 'text-green-600' : 'text-red-600';
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium" data-value="<?= e($row['name']) ?>">
                                <a href="/clients/<?= $row['id'] ?>" class="text-accent-600 hover:underline"><?= e($row['name']) ?></a>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums" data-value="<?= round($row['mrr'], 2) ?>"><?= money($row['mrr']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500" data-value="<?= round($row['recurringCosts'] ?? 0, 2) ?>"><?= money($row['recurringCosts'] ?? 0) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500" data-value="<?= round($row['directExpenses'] + $row['domainCost'], 2) ?>"><?= money($row['directExpenses'] + $row['domainCost']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-medium <?= $pColor ?>" data-value="<?= round($rowProfit, 2) ?>"><?= money($rowProfit) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums <?= $mColor ?>" data-value="<?= round($margin, 1) ?>"><?= number_format($margin, 1) ?>%</td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($clientPL)): ?>
                        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No active clients.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Upcoming Renewals -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Upcoming Renewals</h2>
            <a href="/renewals" class="text-xs text-accent-600 hover:underline">View all<?= $totalRenewals > 5 ? " $totalRenewals" : '' ?> →</a>
        </div>
        <?php if ($upcomingRenewals): ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($upcomingRenewals as $r):
                $urgCls = match($r['urgency']) {
                    'red'   => 'text-red-600 font-medium',
                    'amber' => 'text-amber-600',
                    default => 'text-slate-500',
                };
                $typeBadge = match($r['type']) {
                    'domain'            => 'bg-blue-100 text-blue-700',
                    'recurring_cost'    => 'bg-purple-100 text-purple-700',
                    'recurring_invoice' => 'bg-green-100 text-green-700',
                    'agreement'         => 'bg-amber-100 text-amber-700',
                    default             => 'bg-slate-100 text-slate-600',
                };
                $typeLabel = match($r['type']) {
                    'domain'            => 'Domain',
                    'recurring_cost'    => 'Recurring',
                    'recurring_invoice' => 'Invoice',
                    'agreement'         => 'Agreement',
                    default             => $r['type'],
                };
            ?>
                <li class="px-5 py-3 flex items-center justify-between gap-4 text-sm">
                    <div class="flex items-center gap-2 min-w-0">
                        <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium shrink-0 <?= $typeBadge ?>"><?= $typeLabel ?></span>
                        <?php if ($r['client_id']): ?>
                            <a href="/clients/<?= $r['client_id'] ?>" class="font-medium text-slate-800 hover:text-accent-600 truncate"><?= e($r['name']) ?></a>
                            <span class="text-slate-400 text-xs shrink-0">— <?= e($r['client_name']) ?></span>
                        <?php else: ?>
                            <span class="font-medium text-slate-800 truncate"><?= e($r['name']) ?></span>
                        <?php endif ?>
                    </div>
                    <div class="text-right shrink-0 flex items-center gap-3">
                        <span class="text-slate-600 font-medium"><?= money($r['amount']) ?></span>
                        <span class="text-xs text-slate-400"><?= formatDate($r['due_date']) ?></span>
                        <?php if ($r['relative'] === 'Overdue'): ?>
                            <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">Overdue</span>
                        <?php else: ?>
                            <span class="text-xs <?= $urgCls ?>"><?= e($r['relative']) ?></span>
                        <?php endif ?>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
        <?php else: ?>
            <p class="px-5 py-6 text-sm text-slate-400">No upcoming renewals in the next 90 days.</p>
        <?php endif ?>
    </div>

    <!-- Year-on-Year Comparison -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Year-on-Year</h2>
            <a href="/insights#yoy" class="text-xs text-accent-600 hover:underline">Full report →</a>
        </div>
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Metric</th>
                    <th class="px-4 py-2.5 text-right"><?= e($yoyLabelThis) ?></th>
                    <th class="px-4 py-2.5 text-right"><?= e($yoyLabelLast) ?></th>
                    <th class="px-4 py-2.5 text-right">Change</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php
                $yoyRows = [
                    ['Revenue',        $yoyRevenueThis, $yoyRevenueLast, false, true],
                    ['Costs',          $yoyCostsThis,   $yoyCostsLast,   true,  true],
                    ['Profit',         $yoyProfitThis,  $yoyProfitLast,  false, true],
                    ['Active Clients', $yoyClientsThis, $yoyClientsLast, false, false],
                ];
                foreach ($yoyRows as [$label, $thisVal, $lastVal, $lowerBetter, $isMoney]):
                    $diff = dashDiff((float)$thisVal, (float)$lastVal, $lowerBetter);
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2.5 text-slate-700"><?= $label ?></td>
                    <td class="px-4 py-2.5 text-right tabular-nums font-semibold text-slate-800">
                        <?= $isMoney ? money($thisVal) : number_format((int)$thisVal) ?>
                    </td>
                    <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">
                        <?= $isMoney ? money($lastVal) : number_format((int)$lastVal) ?>
                    </td>
                    <td class="px-4 py-2.5 text-right tabular-nums <?= $diff['cls'] ?>">
                        <?php if ($diff['pct'] !== null): ?>
                            <?= $diff['dir'] ?> <?= number_format($diff['pct'], 1) ?>%
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif ?>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

    <!-- Client Health -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Client Health</h2>
            <div class="flex items-center gap-4 text-xs">
                <a href="/insights?section=health&amp;health=healthy" class="text-green-600 hover:underline">
                    <?= $healthCounts['healthy'] ?> healthy
                </a>
                <a href="/insights?section=health&amp;health=attention" class="text-amber-600 hover:underline">
                    <?= $healthCounts['attention'] ?> attention
                </a>
                <a href="/insights?section=health&amp;health=at_risk" class="text-red-600 hover:underline">
                    <?= $healthCounts['at_risk'] ?> at risk
                </a>
            </div>
        </div>
        <?php if ($healthRows): ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Client</th>
                    <th class="px-4 py-2.5 text-center">Health</th>
                    <th class="px-4 py-2.5 text-left">Flags</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php
                foreach ($healthRows as $row):
                    $dotCls = match($row['status']) {
                        'healthy'   => 'bg-green-500',
                        'attention' => 'bg-amber-400',
                        default     => 'bg-red-500',
                    };
                    $statusBadgeCls = match($row['status']) {
                        'healthy'   => 'bg-green-100 text-green-700',
                        'attention' => 'bg-amber-100 text-amber-700',
                        default     => 'bg-red-100 text-red-700',
                    };
                    $statusLabel = match($row['status']) {
                        'healthy'   => 'Healthy',
                        'attention' => 'Attention',
                        default     => 'At Risk',
                    };
                ?>
                <tr class="hover:bg-slate-50">
                    <td class="px-4 py-2.5 font-medium">
                        <a href="/clients/<?= $row['id'] ?>" class="text-accent-600 hover:underline"><?= e($row['name']) ?></a>
                    </td>
                    <td class="px-4 py-2.5 text-center">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium <?= $statusBadgeCls ?>">
                            <span class="w-1.5 h-1.5 rounded-full <?= $dotCls ?>"></span>
                            <?= $statusLabel ?>
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-slate-500">
                        <?php if ($row['flags']): ?>
                            <?php foreach ($row['flags'] as $flag): ?>
                                <span class="inline-block mr-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-600"><?= e(healthFlagLabel($flag)) ?></span>
                            <?php endforeach ?>
                        <?php else: ?>
                            <span class="text-green-600">All checks passing</span>
                        <?php endif ?>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="px-5 py-6 text-sm text-slate-400">No active clients.</p>
        <?php endif ?>
    </div>

</div>

<script>
(function () {
    const table = document.getElementById('pl-table');
    if (!table) return;
    let sortCol = -1, sortDir = 1;

    table.querySelectorAll('thead th[data-col]').forEach(th => {
        th.addEventListener('click', () => {
            const col = parseInt(th.dataset.col);
            if (sortCol === col) { sortDir *= -1; } else { sortCol = col; sortDir = 1; }

            const tbody = table.querySelector('tbody');
            const rows  = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a, b) => {
                const av = a.cells[col]?.dataset.value ?? '';
                const bv = b.cells[col]?.dataset.value ?? '';
                const an = parseFloat(av), bn = parseFloat(bv);
                if (!isNaN(an) && !isNaN(bn)) return (an - bn) * sortDir;
                return av.localeCompare(bv) * sortDir;
            });
            rows.forEach(r => tbody.appendChild(r));

            table.querySelectorAll('thead th[data-col]').forEach(t => {
                t.textContent = t.textContent.replace(' ↑', '').replace(' ↓', '');
            });
            th.textContent += sortDir === 1 ? ' ↑' : ' ↓';
        });
    });
})();
</script>
