<?php
$profitColor = $profit >= 0 ? 'text-green-600' : 'text-red-600';
?>

<div class="space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">Dashboard</h1>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <?php
        $cards = [
            ['label' => 'Monthly MRR',    'value' => money($mrr),           'color' => 'text-slate-800'],
            ['label' => 'Monthly Costs',  'value' => money($totalCosts),     'color' => 'text-slate-800'],
            ['label' => 'Monthly P&L',    'value' => money($profit),         'color' => $profitColor],
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

    <!-- Client P&L Table -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Per-Client P&amp;L (Monthly)</h2>
            <span class="text-xs text-slate-400">Click column headers to sort</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="pl-table">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left cursor-pointer select-none hover:text-slate-800" data-col="0">Client</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="1">MRR</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="2">Server Cost</th>
                        <th class="px-4 py-2.5 text-right cursor-pointer select-none hover:text-slate-800" data-col="3">Direct Exp.</th>
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
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500" data-value="<?= round($row['serverCost'], 2) ?>"><?= money($row['serverCost']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500" data-value="<?= round($row['directExpenses'], 2) ?>"><?= money($row['directExpenses'] + $row['domainCost']) ?></td>
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
    <?php if ($upcomingRenewals): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h2 class="text-sm font-semibold text-slate-700">Renewals in the Next 30 Days</h2>
        </div>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($upcomingRenewals as $r): ?>
                <li class="px-5 py-3 flex items-center justify-between gap-4 text-sm">
                    <div>
                        <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium mr-2 <?= $r['type'] === 'domain' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700' ?>">
                            <?= ucfirst($r['type']) ?>
                        </span>
                        <a href="/clients/<?= $r['client_id'] ?>" class="font-medium text-slate-800 hover:text-accent-600"><?= e($r['name']) ?></a>
                        <span class="text-slate-400 text-xs ml-1">— <?= e($r['client_name']) ?></span>
                    </div>
                    <div class="text-right shrink-0">
                        <span class="text-slate-600 font-medium"><?= money($r['amount']) ?></span>
                        <span class="text-xs text-slate-400 ml-2"><?= formatDate($r['due_date']) ?></span>
                    </div>
                </li>
            <?php endforeach ?>
        </ul>
    </div>
    <?php endif ?>

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
