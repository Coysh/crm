<?php
$fyQuery = '?fy=' . e($fy);
$maxMonthVal = 0;
foreach ($months as $m) {
    $maxMonthVal = max($maxMonthVal, $m['this_year'], $m['last_year']);
}
$maxMonthVal = max($maxMonthVal, 1);

function insightsDiff(float $a, float $b, bool $lowerIsBetter = false): array
{
    if ($b == 0) return ['pct' => null, 'dir' => 'neutral', 'cls' => 'text-slate-400'];
    $pct = (($a - $b) / abs($b)) * 100;
    $improved = $lowerIsBetter ? $pct < 0 : $pct > 0;
    $cls = $improved ? 'text-green-600' : ($pct == 0 ? 'text-slate-400' : 'text-red-600');
    $arrow = $pct > 0 ? '↑' : ($pct < 0 ? '↓' : '→');
    return ['pct' => abs($pct), 'dir' => $arrow, 'cls' => $cls];
}
?>

<div class="space-y-8">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Insights</h1>
        <div class="flex gap-2 text-xs">
            <a href="/insights?fy=tax" class="px-2.5 py-1 rounded <?= $fy === 'tax' ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Tax Year</a>
            <a href="/insights?fy=calendar" class="px-2.5 py-1 rounded <?= $fy === 'calendar' ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Calendar Year</a>
        </div>
    </div>

    <!-- Anchor nav -->
    <div class="flex gap-4 text-sm border-b border-slate-200 pb-2">
        <a href="#renewals" class="text-accent-600 hover:underline">Upcoming Renewals</a>
        <a href="#yoy" class="text-accent-600 hover:underline">Year-on-Year</a>
        <a href="#yearly-pl" class="text-accent-600 hover:underline">Yearly Profit / Loss</a>
        <a href="#health" class="text-accent-600 hover:underline">Client Health</a>
    </div>

    <!-- ── Upcoming Renewals ──────────────────────────────────────────────── -->
    <section id="renewals">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-slate-700">Upcoming Renewals</h2>
            <div class="flex items-center gap-2 text-xs flex-wrap justify-end">
                <?php foreach ([30 => '30d', 60 => '60d', 90 => '90d', 180 => '6mo', 365 => '1yr'] as $days => $label): ?>
                    <a href="<?= $fyQuery ?>&timeframe=<?= $days ?>&type=<?= e($typeFilter) ?>#renewals"
                       class="px-2 py-1 rounded <?= $timeframe === $days ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach ?>
                <span class="ml-2 text-slate-400">|</span>
                <?php foreach (['all' => 'All', 'domain' => 'Domains', 'recurring_cost' => 'Recurring Costs', 'recurring_invoice' => 'Invoices'] as $val => $label): ?>
                    <a href="<?= $fyQuery ?>&timeframe=<?= $timeframe ?>&type=<?= $val ?>#renewals"
                       class="px-2 py-1 rounded <?= $typeFilter === $val ? 'bg-slate-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach ?>
            </div>
        </div>

        <?php if ($renewals): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Name</th>
                        <th class="px-4 py-2.5 text-left">Type</th>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-right">Amount</th>
                        <th class="px-4 py-2.5 text-left">Due</th>
                        <th class="px-4 py-2.5 text-left">When</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($renewals as $r):
                        $urgCls = match($r['urgency']) {
                            'red'   => 'text-red-600 font-medium',
                            'amber' => 'text-amber-600',
                            default => 'text-slate-500',
                        };
                        $typeBadge = match($r['type']) {
                            'domain'            => 'bg-blue-100 text-blue-700',
                            'recurring_cost'    => 'bg-purple-100 text-purple-700',
                            'recurring_invoice' => 'bg-green-100 text-green-700',
                            default             => 'bg-slate-100 text-slate-600',
                        };
                        $typeLabel = match($r['type']) {
                            'domain'            => 'Domain',
                            'recurring_cost'    => 'Recurring',
                            'recurring_invoice' => 'Invoice',
                            default             => $r['type'],
                        };
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 font-medium text-slate-800"><?= e($r['name']) ?></td>
                        <td class="px-4 py-2.5">
                            <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?= $typeBadge ?>"><?= $typeLabel ?></span>
                        </td>
                        <td class="px-4 py-2.5 text-slate-500">
                            <?php if ($r['client_id']): ?>
                                <a href="/clients/<?= $r['client_id'] ?>" class="hover:text-accent-600"><?= e($r['client_name']) ?></a>
                            <?php elseif ($r['shared_with']): ?>
                                <span class="text-xs text-slate-400"><?= e($r['shared_with']) ?></span>
                            <?php else: ?>
                                <span class="text-slate-300">—</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums"><?= money($r['amount']) ?></td>
                        <td class="px-4 py-2.5 text-slate-500"><?= formatDate($r['due_date']) ?></td>
                        <td class="px-4 py-2.5">
                            <?php if ($r['relative'] === 'Overdue'): ?>
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">Overdue</span>
                            <?php else: ?>
                                <span class="<?= $urgCls ?>"><?= e($r['relative']) ?></span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5 text-right">
                            <a href="<?= e($r['detail_url']) ?>" class="text-xs text-slate-400 hover:text-slate-700">View</a>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
            <div class="bg-white border border-slate-200 rounded-lg px-5 py-8 text-center text-sm text-slate-400">
                No renewals due within the selected timeframe.
            </div>
        <?php endif ?>
    </section>

    <!-- ── Year-on-Year ───────────────────────────────────────────────────── -->
    <section id="yoy">
        <h2 class="text-base font-semibold text-slate-700 mb-3">Year-on-Year Comparison</h2>

        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden mb-6">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Metric</th>
                        <th class="px-4 py-2.5 text-right"><?= e($labelThis) ?></th>
                        <th class="px-4 py-2.5 text-right"><?= e($labelLast) ?></th>
                        <th class="px-4 py-2.5 text-right">Change</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    $yoyRows = [
                        ['Revenue',        $revenueThis, $revenueLast, false, true],
                        ['Costs',          $costsThis,   $costsLast,   true,  true],
                        ['Profit',         $profitThis,  $profitLast,  false, true],
                        ['Active Clients', $clientsThis, $clientsLast, false, false],
                    ];
                    foreach ($yoyRows as [$label, $thisVal, $lastVal, $lowerBetter, $isMoney]):
                        $diff = insightsDiff((float)$thisVal, (float)$lastVal, $lowerBetter);
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 font-medium text-slate-700"><?= $label ?></td>
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

        <!-- Monthly bar chart (AJAX navigable) -->
        <div class="bg-white border border-slate-200 rounded-lg p-5" id="monthly-chart-card">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Monthly Revenue</h3>
                <div class="flex items-center gap-3 text-sm">
                    <button id="chart-prev-btn" onclick="chartNavigate(-1)"
                            class="px-2 py-1 border border-slate-300 rounded text-xs hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed">
                        ← Prev
                    </button>
                    <span id="chart-year-label" class="font-medium text-slate-700 min-w-[70px] text-center"><?= e($labelThis) ?></span>
                    <button id="chart-next-btn" onclick="chartNavigate(1)"
                            class="px-2 py-1 border border-slate-300 rounded text-xs hover:bg-slate-50 disabled:opacity-30 disabled:cursor-not-allowed">
                        Next →
                    </button>
                </div>
            </div>

            <div id="chart-loading" class="hidden h-40 flex items-center justify-center text-sm text-slate-400">Loading…</div>

            <div id="chart-bars" class="flex items-end gap-2 h-40 overflow-x-auto">
                <?php foreach ($months as $m):
                    $hThis = $maxMonthVal > 0 ? max(2, round(($m['this_year'] / $maxMonthVal) * 136)) : 2;
                    $hLast = $maxMonthVal > 0 ? max(2, round(($m['last_year'] / $maxMonthVal) * 136)) : 2;
                    if ($m['this_year'] == 0) $hThis = 2;
                    if ($m['last_year'] == 0) $hLast = 2;
                ?>
                <div class="flex flex-col items-center gap-0.5 shrink-0 min-w-[32px]"
                     title="<?= e($m['label']) ?>: <?= money($m['this_year']) ?> vs prev <?= money($m['last_year']) ?>">
                    <div class="flex items-end gap-0.5">
                        <div class="w-3 bg-accent-500 rounded-t" style="height: <?= $hThis ?>px"></div>
                        <div class="w-3 bg-slate-300 rounded-t" style="height: <?= $hLast ?>px"></div>
                    </div>
                    <span class="text-slate-400 text-center leading-none mt-1" style="font-size:9px"><?= e($m['label']) ?></span>
                </div>
                <?php endforeach ?>
            </div>

            <div class="flex gap-4 mt-3 text-xs text-slate-500">
                <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-accent-500"></span> <span id="chart-legend-this">This year</span></span>
                <span class="flex items-center gap-1.5"><span class="inline-block w-3 h-3 rounded-sm bg-slate-300"></span> <span id="chart-legend-prev">Previous year</span></span>
            </div>
        </div>

        <script>
        (function() {
            const fy = <?= json_encode($fy) ?>;
            let currentYearStart = <?= json_encode($currentFYStart) ?>;
            let hasPrev = true, hasNext = false;

            // Initialise nav button states from server-rendered data
            hasPrev = <?= ($chartHasPrev ?? false) ? 'true' : 'false' ?>;
            hasNext = false; // current year = no next
            updateNavButtons();

            function updateNavButtons() {
                document.getElementById('chart-prev-btn').disabled = !hasPrev;
                document.getElementById('chart-next-btn').disabled = !hasNext;
            }

            window.chartNavigate = function(direction) {
                const d = new Date(currentYearStart);
                d.setFullYear(d.getFullYear() + direction);
                const newStart = d.toISOString().slice(0, 10);
                loadChart(newStart);
            };

            function loadChart(yearStart) {
                document.getElementById('chart-loading').classList.remove('hidden');
                document.getElementById('chart-bars').classList.add('hidden');

                fetch('/insights/monthly-chart?fy=' + fy + '&year_start=' + yearStart)
                    .then(r => r.json())
                    .then(data => {
                        currentYearStart = data.year_start;
                        hasPrev = data.has_prev;
                        hasNext = data.has_next;
                        updateNavButtons();

                        const label = data.year_label + (data.is_current ? ' (YTD)' : '');
                        document.getElementById('chart-year-label').textContent = label;

                        const maxVal = Math.max(data.max_val, 1);
                        const bars = document.getElementById('chart-bars');
                        bars.innerHTML = '';

                        data.months.forEach(m => {
                            const hThis = m.this_year > 0 ? Math.max(2, Math.round((m.this_year / maxVal) * 136)) : 2;
                            const hLast = m.last_year > 0 ? Math.max(2, Math.round((m.last_year / maxVal) * 136)) : 2;
                            const gbpThis = '£' + m.this_year.toLocaleString('en-GB', {minimumFractionDigits:2, maximumFractionDigits:2});
                            const gbpLast = '£' + m.last_year.toLocaleString('en-GB', {minimumFractionDigits:2, maximumFractionDigits:2});

                            bars.innerHTML += `
                                <div class="flex flex-col items-center gap-0.5 shrink-0 min-w-[32px]"
                                     title="${m.label}: ${gbpThis} vs prev ${gbpLast}">
                                    <div class="flex items-end gap-0.5">
                                        <div class="w-3 bg-accent-500 rounded-t" style="height:${hThis}px"></div>
                                        <div class="w-3 bg-slate-300 rounded-t" style="height:${hLast}px"></div>
                                    </div>
                                    <span class="text-slate-400 text-center leading-none mt-1" style="font-size:9px">${m.label}</span>
                                </div>`;
                        });

                        document.getElementById('chart-loading').classList.add('hidden');
                        bars.classList.remove('hidden');
                    })
                    .catch(() => {
                        document.getElementById('chart-loading').textContent = 'Failed to load chart data.';
                    });
            }

            // When FY toggle changes, reset to current year
            document.querySelectorAll('a[href*="?fy="]').forEach(a => {
                a.addEventListener('click', () => {
                    // Let the page reload naturally; chart will re-init at current year
                });
            });
        })();
        </script>

        <!-- Per-client comparison -->
        <?php if ($perClient): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden mt-6">
            <div class="px-5 py-3 border-b border-slate-200">
                <h3 class="text-sm font-semibold text-slate-700">Per-Client Revenue</h3>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-right"><?= e($labelThis) ?></th>
                        <th class="px-4 py-2.5 text-right"><?= e($labelLast) ?></th>
                        <th class="px-4 py-2.5 text-center">Trend</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($perClient as $row):
                        if ((float)$row['this_year'] === 0.0 && (float)$row['last_year'] === 0.0) continue;
                        $trendBadge = match($row['trend']) {
                            'new'      => 'bg-green-100 text-green-700',
                            'lost'     => 'bg-red-100 text-red-700',
                            'grew'     => 'bg-green-50 text-green-600',
                            'declined' => 'bg-amber-50 text-amber-600',
                            default    => 'bg-slate-100 text-slate-500',
                        };
                        $trendLabel = match($row['trend']) {
                            'new'      => 'New',
                            'lost'     => 'Lost',
                            'grew'     => 'Grew',
                            'declined' => 'Declined',
                            default    => 'Stable',
                        };
                    ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 font-medium">
                            <a href="/clients/<?= $row['id'] ?>" class="text-accent-600 hover:underline"><?= e($row['name']) ?></a>
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums font-medium"><?= money($row['this_year']) ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500"><?= money($row['last_year']) ?></td>
                        <td class="px-4 py-2.5 text-center">
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $trendBadge ?>"><?= $trendLabel ?></span>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php endif ?>
    </section>

    <!-- ── Yearly Profit / Loss ──────────────────────────────────────────── -->
    <?php if (!empty($yearlyPL)): ?>
    <section id="yearly-pl">
        <h2 class="text-base font-semibold text-slate-700 mb-3">Yearly Profit / Loss</h2>

        <?php
        $maxAbsProfit = 1;
        foreach ($yearlyPL as $yr) {
            $maxAbsProfit = max($maxAbsProfit, abs($yr['profit']));
        }
        ?>

        <!-- Horizontal bar chart -->
        <div class="bg-white border border-slate-200 rounded-lg p-5 mb-4">
            <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-4">Profit by Year</h3>
            <div class="space-y-2">
                <?php foreach (array_reverse($yearlyPL) as $yr):
                    $barPct = $maxAbsProfit > 0 ? min(100, (abs($yr['profit']) / $maxAbsProfit) * 100) : 0;
                    $barColor = $yr['profit'] >= 0 ? 'bg-green-500' : 'bg-red-500';
                ?>
                <div class="flex items-center gap-3">
                    <span class="text-xs text-slate-500 w-16 shrink-0 text-right <?= $yr['is_current'] ? 'font-bold text-slate-700' : '' ?>">
                        <?= e($yr['label']) ?>
                    </span>
                    <div class="flex-1 flex items-center gap-2">
                        <div class="flex-1 bg-slate-100 rounded h-5 relative overflow-hidden">
                            <div class="h-full <?= $barColor ?> rounded"
                                 style="width: <?= number_format($barPct, 1) ?>%"></div>
                        </div>
                        <span class="text-xs tabular-nums w-20 text-right <?= $yr['profit'] >= 0 ? 'text-green-700' : 'text-red-700' ?>">
                            <?= money($yr['profit']) ?>
                        </span>
                    </div>
                </div>
                <?php endforeach ?>
            </div>
        </div>

        <!-- Table with expandable monthly detail -->
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                        <tr>
                            <th class="px-4 py-2.5 text-left">Year</th>
                            <th class="px-4 py-2.5 text-right">Revenue</th>
                            <th class="px-4 py-2.5 text-right">Costs</th>
                            <th class="px-4 py-2.5 text-right">Profit</th>
                            <th class="px-4 py-2.5 text-right">Margin</th>
                            <th class="px-4 py-2.5 text-center">Invoices</th>
                            <th class="px-4 py-2.5 text-center">Clients</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($yearlyPL as $i => $yr): ?>
                            <!-- Year row -->
                            <tr class="hover:bg-slate-50 cursor-pointer border-t border-slate-100"
                                onclick="toggleYearMonths('ym-<?= $i ?>')">
                                <td class="px-4 py-2.5 <?= $yr['is_current'] ? 'font-bold text-slate-800' : 'font-medium text-slate-700' ?>">
                                    <?= e($yr['label']) ?>
                                    <?php if ($yr['is_current']): ?>
                                        <span class="ml-1 text-xs font-normal text-accent-600">YTD</span>
                                    <?php endif ?>
                                    <span class="ml-1 text-xs text-slate-400" id="ym-arrow-<?= $i ?>">▶</span>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums"><?= money($yr['revenue']) ?></td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500"><?= money($yr['costs']) ?></td>
                                <td class="px-4 py-2.5 text-right tabular-nums font-semibold <?= $yr['profit'] >= 0 ? 'text-green-700' : 'text-red-600' ?>">
                                    <?= money($yr['profit']) ?>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">
                                    <?= $yr['revenue'] > 0 ? number_format($yr['margin'], 1) . '%' : '—' ?>
                                </td>
                                <td class="px-4 py-2.5 text-center tabular-nums text-slate-500"><?= $yr['invoice_count'] ?></td>
                                <td class="px-4 py-2.5 text-center tabular-nums text-slate-500"><?= $yr['client_count'] ?></td>
                            </tr>
                            <!-- Monthly detail rows (hidden by default) -->
                            <tr id="ym-<?= $i ?>" style="display:none">
                                <td colspan="7" class="p-0">
                                    <table class="w-full text-xs bg-slate-50">
                                        <thead class="text-slate-400 uppercase tracking-wide">
                                            <tr>
                                                <th class="pl-10 pr-4 py-1.5 text-left">Month</th>
                                                <th class="px-4 py-1.5 text-right">Revenue</th>
                                                <th class="px-4 py-1.5 text-right">Costs</th>
                                                <th class="px-4 py-1.5 text-right">Profit</th>
                                                <th colspan="3"></th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-slate-100">
                                            <?php foreach ($yr['months'] as $mi => $m):
                                                $mId = 'md-' . $i . '-' . $mi;
                                            ?>
                                                <tr class="hover:bg-slate-100 cursor-pointer"
                                                    onclick="toggleMonthDetail('<?= $mId ?>', '<?= e($m['year_month']) ?>')">
                                                    <td class="pl-10 pr-4 py-1.5 text-slate-600">
                                                        <span id="<?= $mId ?>-arrow" class="text-slate-300 mr-1 text-xs">▶</span>
                                                        <?= e($m['label']) ?>
                                                    </td>
                                                    <td class="px-4 py-1.5 text-right tabular-nums"><?= money($m['revenue']) ?></td>
                                                    <td class="px-4 py-1.5 text-right tabular-nums text-slate-400"><?= money($m['costs']) ?></td>
                                                    <td class="px-4 py-1.5 text-right tabular-nums <?= $m['profit'] >= 0 ? 'text-green-600' : 'text-red-500' ?>">
                                                        <?= money($m['profit']) ?>
                                                    </td>
                                                    <td colspan="3"></td>
                                                </tr>
                                                <!-- Month detail row (AJAX-loaded) -->
                                                <tr id="<?= $mId ?>" style="display:none">
                                                    <td colspan="7" class="p-0 bg-white border-b border-slate-200">
                                                        <div id="<?= $mId ?>-content" class="px-10 py-3 text-xs text-slate-500">
                                                            <span class="inline-block w-4 h-4 border-2 border-accent-400 border-t-transparent rounded-full animate-spin align-middle mr-1"></span>
                                                            Loading…
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach ?>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
    <script>
    function toggleYearMonths(id) {
        const el = document.getElementById(id);
        const idx = id.replace('ym-', '');
        const arrow = document.getElementById('ym-arrow-' + idx);
        if (!el) return;
        if (el.style.display === 'none') {
            el.style.display = '';
            if (arrow) arrow.textContent = '▼';
        } else {
            el.style.display = 'none';
            if (arrow) arrow.textContent = '▶';
        }
    }

    const _monthDetailLoaded = {};

    function toggleMonthDetail(id, yearMonth) {
        const row     = document.getElementById(id);
        const arrow   = document.getElementById(id + '-arrow');
        const content = document.getElementById(id + '-content');
        if (!row) return;

        if (row.style.display !== 'none') {
            row.style.display = 'none';
            if (arrow) arrow.textContent = '▶';
            return;
        }

        row.style.display = '';
        if (arrow) arrow.textContent = '▼';

        if (_monthDetailLoaded[id]) return; // already loaded

        fetch('/insights/month-detail?month=' + encodeURIComponent(yearMonth))
            .then(r => r.json())
            .then(data => {
                _monthDetailLoaded[id] = true;
                content.innerHTML = renderMonthDetail(data);
            })
            .catch(() => {
                content.innerHTML = '<p class="text-red-500">Failed to load detail.</p>';
            });
    }

    function sourceBadge(source) {
        if (source === 'hiveage') return '<span class="inline-block px-1 py-0 rounded text-xs font-medium bg-purple-100 text-purple-700 ml-1">Hiveage</span>';
        return '';
    }

    function statusBadgeHtml(status) {
        const cls = status === 'paid' ? 'bg-green-100 text-green-700'
                  : status === 'overdue' ? 'bg-red-100 text-red-700'
                  : 'bg-blue-100 text-blue-700';
        return `<span class="inline-block px-1 py-0 rounded text-xs font-medium ${cls}">${status}</span>`;
    }

    function renderMonthDetail(data) {
        let html = '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 py-2">';

        // ── Revenue (invoices) ───────────────────────────────────────────
        html += '<div>';
        html += '<p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Revenue — Invoices</p>';
        if (data.invoices && data.invoices.length) {
            html += '<table class="w-full text-xs"><tbody class="divide-y divide-slate-100">';
            data.invoices.forEach(inv => {
                const faId  = inv.freeagent_url ? inv.freeagent_url.split('/').pop() : null;
                const isHiveage = inv.source === 'hiveage';
                const refHtml = (!isHiveage && faId)
                    ? `<a href="https://coyshdigital.freeagent.com/invoices/${faId}" target="_blank" rel="noopener" class="text-accent-600 hover:underline font-mono">${inv.reference || '—'}</a>`
                    : `<span class="font-mono">${inv.reference || '—'}</span>`;
                html += `<tr class="hover:bg-slate-50">
                    <td class="py-1 pr-3">${refHtml}${sourceBadge(inv.source)}</td>
                    <td class="py-1 pr-3 text-slate-500">${inv.client_name ? `<a href="/clients/${inv.client_id}" class="hover:underline">${inv.client_name}</a>` : '—'}</td>
                    <td class="py-1 text-right tabular-nums font-medium">£${parseFloat(inv.total_value).toFixed(2)}</td>
                    <td class="py-1 pl-2">${statusBadgeHtml(inv.status)}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p class="text-slate-300 italic">No invoices</p>';
        }
        html += '</div>';

        // ── Costs ────────────────────────────────────────────────────────
        html += '<div class="space-y-3">';

        // One-off / direct expenses
        html += '<div>';
        html += '<p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Costs — Direct Expenses</p>';
        if (data.expenses && data.expenses.length) {
            html += '<table class="w-full text-xs"><tbody class="divide-y divide-slate-100">';
            data.expenses.forEach(ex => {
                html += `<tr class="hover:bg-slate-50">
                    <td class="py-1 pr-3"><a href="/expenses/${ex.id}/edit" class="hover:underline text-slate-700">${ex.description || ex.category_name || '—'}</a></td>
                    <td class="py-1 pr-3 text-slate-400">${ex.category_name || '—'}</td>
                    <td class="py-1 text-right tabular-nums text-red-600 font-medium">£${parseFloat(ex.amount).toFixed(2)}</td>
                </tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p class="text-slate-300 italic">None</p>';
        }
        html += '</div>';

        // Recurring costs
        html += '<div>';
        html += '<p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Costs — Recurring</p>';
        if (data.recurring && data.recurring.length) {
            html += '<table class="w-full text-xs"><tbody class="divide-y divide-slate-100">';
            data.recurring.forEach(rc => {
                html += `<tr class="hover:bg-slate-50">
                    <td class="py-1 pr-3"><a href="/expenses/recurring/${rc.id}/edit" class="hover:underline text-slate-700">${rc.name}</a></td>
                    <td class="py-1 pr-3 text-slate-400">${rc.category_name || '—'}</td>
                    <td class="py-1 text-right tabular-nums text-red-600 font-medium">£${parseFloat(rc.monthly_equivalent).toFixed(2)}<span class="text-slate-300">/mo</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
        } else {
            html += '<p class="text-slate-300 italic">None</p>';
        }
        html += '</div>';

        // Domain costs
        if (data.domains && data.domains.length) {
            html += '<div>';
            html += '<p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Costs — Domains</p>';
            html += '<table class="w-full text-xs"><tbody class="divide-y divide-slate-100">';
            data.domains.forEach(d => {
                html += `<tr class="hover:bg-slate-50">
                    <td class="py-1 pr-3 font-mono"><a href="/domains/${d.id}" class="hover:underline text-accent-600">${d.domain}</a></td>
                    <td class="py-1 pr-3 text-slate-400">${d.client_name || '—'}</td>
                    <td class="py-1 text-right tabular-nums text-red-600 font-medium">£${parseFloat(d.monthly_equivalent).toFixed(2)}<span class="text-slate-300">/mo</span></td>
                </tr>`;
            });
            html += '</tbody></table>';
            html += '</div>';
        }

        html += '</div>'; // costs column
        html += '</div>'; // grid
        return html;
    }
    </script>
    <?php endif ?>

    <!-- ── Client Health ─────────────────────────────────────────────────── -->
    <section id="health">
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-base font-semibold text-slate-700">Client Health</h2>
            <div class="flex gap-2 text-xs">
                <?php foreach (['all' => 'All', 'healthy' => 'Healthy', 'attention' => 'Attention', 'at_risk' => 'At Risk'] as $val => $label): ?>
                    <a href="<?= $fyQuery ?>&health=<?= $val ?>#health"
                       class="px-2.5 py-1 rounded <?= $healthStatusFilter === $val ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">
                        <?= $label ?>
                    </a>
                <?php endforeach ?>
            </div>
        </div>

        <?php
        $flagLabels = [
            'loss_making'       => 'Loss-making',
            'no_retainer'       => 'No retainer',
            'no_recent_invoice' => 'No recent invoice',
            'overdue_invoices'  => 'Overdue invoices',
            'incomplete_setup'  => 'Incomplete setup',
        ];
        $filtered = array_filter($healthRows, fn($r) => $healthStatusFilter === 'all' || $r['status'] === $healthStatusFilter);
        ?>

        <?php if ($filtered): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
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
                    <?php foreach ($filtered as $row):
                        $dotCls = match($row['status']) {
                            'healthy'   => 'bg-green-500',
                            'attention' => 'bg-amber-400',
                            default     => 'bg-red-500',
                        };
                        $statusLabel = match($row['status']) {
                            'healthy'   => 'Healthy',
                            'attention' => 'Attention',
                            default     => 'At Risk',
                        };
                        $statusBadgeCls = match($row['status']) {
                            'healthy'   => 'bg-green-100 text-green-700',
                            'attention' => 'bg-amber-100 text-amber-700',
                            default     => 'bg-red-100 text-red-700',
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
                        <td class="px-4 py-2.5 text-slate-500 text-xs">
                            <?php if ($row['flags']): ?>
                                <?php foreach ($row['flags'] as $flag): ?>
                                    <span class="inline-block mr-1.5 mb-0.5 px-1.5 py-0.5 rounded bg-slate-100 text-slate-600"><?= $flagLabels[$flag] ?? $flag ?></span>
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
        </div>
        <?php else: ?>
            <div class="bg-white border border-slate-200 rounded-lg px-5 py-8 text-center text-sm text-slate-400">
                No clients match this filter.
            </div>
        <?php endif ?>
    </section>

</div>
