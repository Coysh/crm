<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-slate-800">Upcoming Renewals</h1>
        <form method="GET" action="/renewals" class="flex flex-wrap items-center gap-2 text-sm">
            <select name="timeframe" class="border border-slate-300 rounded px-2 py-1.5 text-sm bg-white" onchange="this.form.submit()">
                <?php foreach ([30, 60, 90, 180, 365] as $t): ?>
                    <option value="<?= $t ?>" <?= $timeframe === $t ? 'selected' : '' ?>>Next <?= $t ?> days</option>
                <?php endforeach ?>
            </select>
            <select name="type" class="border border-slate-300 rounded px-2 py-1.5 text-sm bg-white" onchange="this.form.submit()">
                <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All types</option>
                <option value="domain" <?= $type === 'domain' ? 'selected' : '' ?>>Domains</option>
                <option value="recurring_cost" <?= $type === 'recurring_cost' ? 'selected' : '' ?>>Recurring costs</option>
                <option value="recurring_invoice" <?= $type === 'recurring_invoice' ? 'selected' : '' ?>>Recurring invoices</option>
                <option value="agreement" <?= $type === 'agreement' ? 'selected' : '' ?>>Agreements</option>
            </select>
            <select name="client" class="border border-slate-300 rounded px-2 py-1.5 text-sm bg-white" onchange="this.form.submit()">
                <option value="">All clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= $clientId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </form>
    </div>

    <?php if (empty($renewals)): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center">
            <p class="text-sm text-slate-400">Nothing due in this window.</p>
        </div>
    <?php else: ?>
        <?php foreach ($grouped as $monthKey => $rows): ?>
            <div class="bg-white border <?= $monthKey === 'overdue' ? 'border-red-200' : 'border-slate-200' ?> rounded-lg overflow-hidden">
                <div class="px-5 py-2.5 border-b <?= $monthKey === 'overdue' ? 'border-red-200 bg-red-50' : 'border-slate-200 bg-slate-50' ?>">
                    <h2 class="text-xs font-semibold uppercase tracking-wide <?= $monthKey === 'overdue' ? 'text-red-700' : 'text-slate-500' ?>">
                        <?= $monthKey === 'overdue' ? 'Overdue' : date('F Y', strtotime($monthKey . '-01')) ?>
                        <span class="font-normal normal-case">· <?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?></span>
                    </h2>
                </div>
                <ul class="divide-y divide-slate-100">
                    <?php foreach ($rows as $r):
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
                            'recurring_cost'    => 'Cost',
                            'recurring_invoice' => 'Invoice',
                            'agreement'         => 'Agreement',
                            default             => $r['type'],
                        };
                    ?>
                    <li class="px-5 py-3 flex flex-wrap items-center justify-between gap-3 text-sm">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium shrink-0 <?= $typeBadge ?>"><?= $typeLabel ?></span>
                            <a href="<?= e($r['detail_url']) ?>" class="font-medium text-slate-800 hover:text-accent-600 truncate"><?= e($r['name']) ?></a>
                            <?php if ($r['client_name']): ?>
                                <span class="text-slate-400 text-xs shrink-0">— <?= e($r['client_name']) ?></span>
                            <?php elseif ($r['shared_with']): ?>
                                <span class="text-slate-400 text-xs shrink-0">— shared: <?= e($r['shared_with']) ?></span>
                            <?php endif ?>
                        </div>
                        <div class="text-right shrink-0 flex items-center gap-3">
                            <span class="text-slate-600 font-medium"><?= $r['amount'] !== null ? money($r['amount']) : '—' ?></span>
                            <span class="text-xs text-slate-400 w-20 text-left"><?= formatDate($r['due_date']) ?></span>
                            <span class="text-xs w-16 text-right <?= $urgCls ?>"><?= e($r['relative']) ?></span>
                        </div>
                    </li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endforeach ?>
    <?php endif ?>
</div>
