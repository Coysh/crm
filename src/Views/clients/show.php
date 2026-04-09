<?php
$pl     = $client['pl'];
$pColor = $pl['profit'] >= 0 ? 'text-green-600' : 'text-red-600';

// Check if FreeAgent is connected and this client is mapped
global $db;
$faConnected = false;
$faContact   = null;
if (isset($db)) {
    try {
        $faRow = $db->query("SELECT access_token FROM freeagent_config WHERE id = 1")->fetch();
        $faConnected = !empty($faRow['access_token']);
        if ($faConnected) {
            $stmt = $db->prepare("SELECT id FROM freeagent_contacts WHERE client_id = ? LIMIT 1");
            $stmt->execute([$client['id']]);
            $faContact = $stmt->fetch() ?: null;
        }
    } catch (\Throwable) {}
}
?>

<div class="space-y-6">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
        <div>
            <?php
            $clientType = $client['client_type'] ?? 'managed';
            $typeBadge  = match($clientType) {
                'support_only'     => 'bg-purple-100 text-purple-700',
                'consultancy_only' => 'bg-teal-100 text-teal-700',
                default            => 'bg-blue-100 text-blue-700',
            };
            $typeLabel  = match($clientType) {
                'support_only'     => 'Support Only',
                'consultancy_only' => 'Consultancy Only',
                default            => 'Managed',
            };
            ?>
            <div class="flex items-center gap-2 flex-wrap">
                <h1 class="text-xl font-semibold text-slate-800"><?= e($client['name']) ?></h1>
                <span class="inline-block px-2 py-0.5 rounded text-xs font-medium <?= $typeBadge ?>"><?= $typeLabel ?></span>
            </div>
            <?php if ($client['contact_name']): ?>
                <p class="text-sm text-slate-500 mt-0.5">
                    <?= e($client['contact_name']) ?>
                    <?php if ($client['contact_email']): ?>
                        · <a href="mailto:<?= e($client['contact_email']) ?>" class="hover:underline"><?= e($client['contact_email']) ?></a>
                    <?php endif ?>
                </p>
            <?php endif ?>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <span class="inline-block px-2.5 py-1 rounded-full text-xs font-medium <?= statusBadge($client['status']) ?>">
                <?= ucfirst($client['status']) ?>
            </span>
            <a href="/clients/<?= $client['id'] ?>/edit" class="px-3 py-1.5 text-sm border border-slate-300 rounded hover:bg-slate-50">Edit</a>
            <a href="/clients/<?= $client['id'] ?>/merge" class="px-3 py-1.5 text-sm border border-slate-300 rounded hover:bg-slate-50 text-slate-600">Merge…</a>
            <form method="POST" action="/clients/<?= $client['id'] ?>/archive" class="inline">
                <button type="submit"
                        onclick="return confirm('<?= $client['status'] === 'active' ? 'Archive this client?' : 'Restore this client?' ?>')"
                        class="px-3 py-1.5 text-sm border border-slate-300 rounded hover:bg-slate-50 text-slate-600">
                    <?= $client['status'] === 'active' ? 'Archive' : 'Restore' ?>
                </button>
            </form>
            <form method="POST" action="/clients/<?= $client['id'] ?>/delete" class="inline">
                <button type="submit"
                        onclick="return confirm('Permanently delete <?= addslashes(e($client['name'])) ?> and all their sites, domains, packages, projects and expenses? This cannot be undone.')"
                        class="px-3 py-1.5 text-sm border border-red-200 rounded hover:bg-red-50 text-red-600">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Client Health Card -->
    <?php
    $healthStatusBadge = match($health['status']) {
        'healthy'   => 'bg-green-100 text-green-700',
        'attention' => 'bg-amber-100 text-amber-700',
        default     => 'bg-red-100 text-red-700',
    };
    $healthStatusLabel = match($health['status']) {
        'healthy'   => 'Healthy',
        'attention' => 'Attention',
        default     => 'At Risk',
    };
    $healthDot = match($health['status']) {
        'healthy'   => 'bg-green-500',
        'attention' => 'bg-amber-400',
        default     => 'bg-red-500',
    };
    $checks = [
        ['loss_making',       !in_array('loss_making', $health['flags']),       'Profitable',          'Loss-making'],
        ['no_recent_invoice', !in_array('no_recent_invoice', $health['flags']), 'Invoice in last 12 months', 'No invoices in 12+ months'],
        ['overdue_invoices',  !in_array('overdue_invoices', $health['flags']),  'No overdue invoices', 'Overdue invoice(s)'],
    ];
    // Type-conditional checks
    if ($clientType !== 'consultancy_only') {
        $checks[] = ['no_retainer', !in_array('no_retainer', $health['flags']), 'Active retainer', 'No active retainer'];
    }
    if ($clientType === 'managed') {
        $checks[] = ['incomplete_setup', !in_array('incomplete_setup', $health['flags']), 'Site + domain linked', 'Incomplete setup'];
    }
    if (in_array($clientType, ['support_only', 'consultancy_only'])) {
        $checks[] = ['no_agreement', !in_array('no_agreement', $health['flags']), 'Agreement notes recorded', 'No agreement notes'];
    }
    ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center gap-3">
            <h2 class="text-sm font-semibold text-slate-700">Client Health</h2>
            <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium <?= $healthStatusBadge ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $healthDot ?>"></span>
                <?= $healthStatusLabel ?>
            </span>
        </div>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($checks as [$key, $pass, $okMsg, $failMsg]): ?>
            <li class="px-5 py-2.5 flex items-center gap-3 text-sm">
                <span class="shrink-0 <?= $pass ? 'text-green-500' : 'text-red-500' ?>"><?= $pass ? '✓' : '✗' ?></span>
                <span class="<?= $pass ? 'text-slate-600' : 'text-red-700' ?>"><?= $pass ? $okMsg : $failMsg ?></span>
            </li>
            <?php endforeach ?>
        </ul>
    </div>

    <?php if ($clientType !== 'managed'): ?>
    <!-- Agreement Notes (support/consultancy clients) -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Agreement Notes</h2>
            <a href="/clients/<?= $client['id'] ?>/edit" class="text-xs text-accent-600 hover:underline">Edit</a>
        </div>
        <div class="px-5 py-4">
            <?php if (!empty($client['agreement_notes'])): ?>
                <p class="text-sm text-slate-700 whitespace-pre-wrap"><?= e($client['agreement_notes']) ?></p>
            <?php else: ?>
                <p class="text-sm text-slate-400 italic">No agreement notes recorded. <a href="/clients/<?= $client['id'] ?>/edit" class="text-accent-600 hover:underline">Add notes →</a></p>
            <?php endif ?>
        </div>
    </div>
    <?php endif ?>

    <!-- P&L Summary Card -->
    <?php
    $alltime   = $client['pl_alltime'];
    $mProfit   = $pl['profit'];
    $atProfit  = $alltime['profit'];
    $borderCol = $mProfit >= 0 ? 'border-green-500' : 'border-red-500';
    $mProfitColor  = $mProfit >= 0 ? 'text-green-600' : 'text-red-600';
    $atProfitColor = $atProfit >= 0 ? 'text-green-600' : 'text-red-600';
    ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden border-l-4 <?= $borderCol ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 divide-y md:divide-y-0 md:divide-x divide-slate-100">
            <!-- Monthly Overview -->
            <div class="p-5">
                <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">Monthly Overview</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr>
                            <td class="py-1.5 text-slate-600">Monthly Recurring Revenue</td>
                            <td class="py-1.5 text-right tabular-nums font-medium"><?= money($pl['mrr']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-slate-600">Monthly Costs</td>
                            <td class="py-1.5 text-right tabular-nums font-medium"><?= money($pl['totalCosts']) ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200">
                            <td class="pt-2 text-sm font-bold text-slate-800">Monthly Profit / Loss</td>
                            <td class="pt-2 text-right tabular-nums text-lg font-bold <?= $mProfitColor ?>"><?= money($mProfit) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <!-- All-Time Overview -->
            <div class="p-5">
                <h3 class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-3">All-Time Overview</h3>
                <table class="w-full text-sm">
                    <tbody>
                        <tr>
                            <td class="py-1.5 text-slate-600">Total Invoiced</td>
                            <td class="py-1.5 text-right tabular-nums font-medium"><?= money($alltime['totalInvoiced']) ?></td>
                        </tr>
                        <tr>
                            <td class="py-1.5 text-slate-600">Total Expenses</td>
                            <td class="py-1.5 text-right tabular-nums font-medium"><?= money($alltime['totalExpenses']) ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-slate-200">
                            <td class="pt-2 text-sm font-bold text-slate-800">All-Time Profit / Loss</td>
                            <td class="pt-2 text-right tabular-nums text-lg font-bold <?= $atProfitColor ?>"><?= money($atProfit) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- P&L Breakdown -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Monthly Profit / Loss</h2>
            <span class="text-sm font-semibold <?= $pColor ?>"><?= money($pl['profit']) ?> / mo &nbsp;·&nbsp; <?= number_format($pl['margin'], 1) ?>% margin</span>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 divide-y sm:divide-y-0 sm:divide-x divide-slate-100">

            <!-- Revenue -->
            <div class="p-4">
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Revenue</p>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-50">
                        <tr>
                            <td class="py-1 text-slate-600">Monthly Recurring Revenue</td>
                            <td class="py-1 text-right tabular-nums font-medium"><?= money($pl['mrr']) ?></td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200">
                            <td class="pt-2 text-xs font-semibold text-slate-500 uppercase tracking-wide">Total Revenue</td>
                            <td class="pt-2 text-right tabular-nums font-semibold"><?= money($pl['mrr']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Costs -->
            <div class="p-4">
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-2">Costs</p>
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-slate-50">
                        <?php foreach ($client['pl_recurring'] ?? [] as $rc):
                            if ($rc['assignment_type'] === 'server') {
                                $rcLabel = 'shared ' . (int)$rc['shared_count'] . ' clients';
                            } elseif ($rc['assignment_type'] === 'site') {
                                $rcLabel = 'shared ' . (int)$rc['total_sites'] . ' sites';
                            } else {
                                $rcLabel = 'shared ' . (int)$rc['shared_count'] . ' clients';
                            }
                        ?>
                        <tr>
                            <td class="py-1 text-slate-600">
                                <?= e($rc['name']) ?>
                                <?php $rcCur = $rc['currency'] ?? 'GBP'; ?>
                                <?php if ($rcCur !== 'GBP'): ?>
                                    <span class="text-xs text-slate-400 ml-1"><?= formatCurrency($rc['monthly_share'], $rcCur) ?>/mo</span>
                                <?php endif ?>
                                <span class="text-xs text-slate-400 ml-1">(<?= $rcLabel ?>)</span>
                            </td>
                            <td class="py-1 text-right tabular-nums"><?= money($rc['monthly_share_gbp'] ?? $rc['monthly_share']) ?></td>
                        </tr>
                        <?php endforeach ?>
                        <?php if (!empty($client['pl']['recurringCosts']) && $client['pl']['recurringCosts'] > 0 && empty($client['pl_recurring'])): ?>
                        <tr>
                            <td class="py-1 text-slate-600">Recurring Costs</td>
                            <td class="py-1 text-right tabular-nums"><?= money($client['pl']['recurringCosts']) ?></td>
                        </tr>
                        <?php endif ?>
                        <?php foreach ($client['domains'] as $d): if (!$d['annual_cost']) continue;
                            $dCur       = $d['currency'] ?? 'GBP';
                            $dGbpAnnual = isset($fx) ? $fx->convertToGBP((float)$d['annual_cost'], $dCur) : (float)$d['annual_cost'];
                        ?>
                        <tr>
                            <td class="py-1 text-slate-600">
                                <?= e($d['domain']) ?> <span class="text-xs text-slate-400">(domain)</span>
                                <?php if ($dCur !== 'GBP'): ?>
                                    <span class="text-xs text-slate-400 ml-1"><?= formatCurrency($d['annual_cost'], $dCur) ?>/yr</span>
                                <?php endif ?>
                            </td>
                            <td class="py-1 text-right tabular-nums"><?= money($dGbpAnnual / 12) ?></td>
                        </tr>
                        <?php endforeach ?>
                        <?php if ($pl['directExpenses'] > 0): ?>
                        <tr>
                            <td class="py-1 text-slate-600">Direct Expenses
                                <span class="text-xs text-slate-400">(<?= count($client['expenses']) ?> item<?= count($client['expenses']) !== 1 ? 's' : '' ?>)</span>
                            </td>
                            <td class="py-1 text-right tabular-nums"><?= money($pl['directExpenses']) ?></td>
                        </tr>
                        <?php endif ?>
                    </tbody>
                    <tfoot>
                        <tr class="border-t border-slate-200">
                            <td class="pt-2 text-xs font-semibold text-slate-500 uppercase tracking-wide">Total Costs</td>
                            <td class="pt-2 text-right tabular-nums font-semibold"><?= money($pl['totalCosts']) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

        </div>
    </div>

    <?php if ($client['notes']): ?>
        <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-800">
            <?= nl2br(e($client['notes'])) ?>
        </div>
    <?php endif ?>

    <!-- Domains -->
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">Domains</h2>
            <a href="/clients/<?= $client['id'] ?>/domains/create" class="text-xs text-accent-600 hover:underline">+ Add Domain</a>
        </div>
        <?php if ($client['domains']): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Domain</th>
                        <th class="px-4 py-2 text-left">Registrar</th>
                        <th class="px-4 py-2 text-center">CF Proxied</th>
                        <th class="px-4 py-2 text-left">Renewal</th>
                        <th class="px-4 py-2 text-right">Annual Cost</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($client['domains'] as $d): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-mono text-xs"><?= e($d['domain']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($d['registrar'] ?: '—') ?></td>
                            <td class="px-4 py-2 text-center"><?= $d['cloudflare_proxied'] ? '<span class="text-orange-500">✓</span>' : '<span class="text-slate-300">—</span>' ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= formatDate($d['renewal_date']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= $d['annual_cost'] ? money($d['annual_cost']) : '—' ?></td>
                            <td class="px-4 py-2 text-right">
                                <a href="/clients/<?= $client['id'] ?>/domains/<?= $d['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/clients/<?= $client['id'] ?>/domains/<?= $d['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this domain?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">No domains yet. <a href="/clients/<?= $client['id'] ?>/domains/create" class="text-accent-600 hover:underline">Add one</a>.</p>
        <?php endif ?>
    </section>

    <!-- Sites -->
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">Sites</h2>
            <a href="/clients/<?= $client['id'] ?>/sites/create" class="text-xs text-accent-600 hover:underline">+ Add Site</a>
        </div>
        <?php if ($client['sites']): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Domain</th>
                        <th class="px-4 py-2 text-left">Stack</th>
                        <th class="px-4 py-2 text-left">CSS</th>
                        <th class="px-4 py-2 text-left">SMTP</th>
                        <th class="px-4 py-2 text-left">Server</th>
                        <th class="px-4 py-2 text-center">CI/CD</th>
                        <th class="px-4 py-2 text-left">Ploi</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($client['sites'] as $s): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-mono text-xs">
                                <?php if ($s['git_repo']): ?>
                                    <a href="<?= e($s['git_repo']) ?>" target="_blank" rel="noopener" class="text-accent-600 hover:underline"><?= e($s['domain_name'] ?: '—') ?></a>
                                <?php else: ?>
                                    <?= e($s['domain_name'] ?: '—') ?>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2"><?= e($s['website_stack'] ?: '—') ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($s['css_framework'] ?: '—') ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($s['smtp_service'] ?: '—') ?></td>
                            <td class="px-4 py-2 text-slate-500">
                                <?php if ($s['server_name']): ?>
                                    <?= e($s['server_name']) ?>
                                <?php else: ?>
                                    <span class="text-slate-400 italic text-xs">External</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2 text-center"><?= $s['has_deployment_pipeline'] ? '<span class="text-green-500">✓</span>' : '<span class="text-slate-300">—</span>' ?></td>
                            <td class="px-4 py-2 text-xs">
                                <?php if (!empty($s['ploi_domain'])): ?>
                                    <details>
                                        <summary class="cursor-pointer text-accent-600">Ploi Site Details</summary>
                                        <div class="mt-1 text-slate-600 space-y-0.5">
                                            <div>Domain: <?= e($s['ploi_domain']) ?></div>
                                            <div>Type: <?= e($s['ploi_project_type'] ?: '—') ?> · PHP <?= e($s['ploi_php_version'] ?: '—') ?></div>
                                            <div>Repo: <?= e($s['ploi_repository'] ?: '—') ?> <?= $s['ploi_branch'] ? ('@ ' . e($s['ploi_branch'])) : '' ?></div>
                                            <div>SSL: <?= !empty($s['ploi_has_ssl']) ? 'Yes' : 'No' ?> · Web dir: <?= e($s['ploi_web_directory'] ?: '—') ?></div>
                                            <?php if (!empty($s['ploi_test_domain'])): ?><div>Test: <?= e($s['ploi_test_domain']) ?></div><?php endif ?>
                                            <div>Status: <?= e($s['ploi_status'] ?: '—') ?><?= !empty($s['ploi_is_stale']) ? ' (stale)' : '' ?></div>
                                        </div>
                                    </details>
                                <?php else: ?>—<?php endif ?>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <a href="/clients/<?= $client['id'] ?>/sites/<?= $s['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/clients/<?= $client['id'] ?>/sites/<?= $s['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Remove this site?')" class="text-xs text-red-400 hover:text-red-600">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">No sites yet. <a href="/clients/<?= $client['id'] ?>/sites/create" class="text-accent-600 hover:underline">Add one</a>.</p>
        <?php endif ?>
    </section>

    <!-- Recurring Income -->
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">Recurring Income</h2>
            <span class="text-xs text-slate-400">From FreeAgent · read-only</span>
        </div>
        <?php if ($client['recurring_invoices']): ?>
        <?php
        $confirmedMonthly = 0.0;
        $pipelineMonthly  = 0.0;
        foreach ($client['recurring_invoices'] as $ri) {
            $monthly = \CoyshCRM\Models\FreeAgentRecurringInvoice::toMonthly((float)$ri['total_value'], $ri['frequency']);
            if ($ri['recurring_status'] === 'Active') $confirmedMonthly += $monthly;
            else $pipelineMonthly += $monthly;
        }
        ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Reference</th>
                        <th class="px-4 py-2 text-left">Frequency</th>
                        <th class="px-4 py-2 text-right">Net</th>
                        <th class="px-4 py-2 text-right">Total (inc. VAT)</th>
                        <th class="px-4 py-2 text-right">Monthly Equiv.</th>
                        <th class="px-4 py-2 text-center">Status</th>
                        <th class="px-4 py-2 text-left">Next Invoice</th>
                        <th class="px-4 py-2 text-left">End Date</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($client['recurring_invoices'] as $ri):
                        $monthly = \CoyshCRM\Models\FreeAgentRecurringInvoice::toMonthly((float)$ri['total_value'], $ri['frequency']);
                        $isActive = $ri['recurring_status'] === 'Active';
                        $statusBadge = $isActive ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700';
                    ?>
                        <tr class="hover:bg-slate-50 <?= !$isActive ? 'opacity-70' : '' ?>">
                            <td class="px-4 py-2 font-medium"><?= freeagentLink($ri['freeagent_url'] ?? null, $ri['reference'] ?: '—') ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($ri['frequency']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-500"><?= $ri['net_value'] !== null ? money($ri['net_value']) : '—' ?></td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium"><?= $ri['total_value'] !== null ? money($ri['total_value']) : '—' ?></td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-600"><?= money($monthly) ?></td>
                            <td class="px-4 py-2 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $statusBadge ?>">
                                    <?= e($ri['recurring_status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2 text-slate-500"><?= formatDate($ri['next_recurs_on']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= $ri['recurring_end_date'] ? formatDate($ri['recurring_end_date']) : '<span class="text-slate-300">Ongoing</span>' ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
                <tfoot class="border-t-2 border-slate-200 bg-slate-50">
                    <tr>
                        <td colspan="4" class="px-4 py-2 text-xs text-slate-500 font-medium">Monthly totals</td>
                        <td class="px-4 py-2 text-right tabular-nums font-semibold text-slate-800">
                            <?= money($confirmedMonthly) ?>
                            <?php if ($pipelineMonthly > 0): ?>
                                <span class="block text-xs font-normal text-amber-600">+ <?= money($pipelineMonthly) ?> pipeline</span>
                            <?php endif ?>
                        </td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">No recurring invoices found in FreeAgent for this client.</p>
        <?php endif ?>
    </section>

    <!-- Projects -->
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">Projects</h2>
            <a href="/projects/create?client_id=<?= $client['id'] ?>" class="text-xs text-accent-600 hover:underline">+ Add Project</a>
        </div>
        <?php if ($client['projects']): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-left">Category</th>
                        <th class="px-4 py-2 text-right">Target</th>
                        <th class="px-4 py-2 text-center" style="min-width:120px">Progress</th>
                        <th class="px-4 py-2 text-left">Dates</th>
                        <th class="px-4 py-2 text-center">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    $cats = \CoyshCRM\Models\Project::incomeCategories();
                    foreach ($client['projects'] as $p):
                        $cpTarget   = (float)($p['income_target'] ?? 0);
                        $cpInvoiced = (float)($p['income_invoiced'] ?? 0);
                        $cpPct      = $cpTarget > 0 ? min(round(($cpInvoiced / $cpTarget) * 100), 999) : 0;
                        $cpBarPct   = min($cpPct, 100);
                        $cpBarColor = $cpPct > 100 ? 'bg-amber-500' : 'bg-green-500';
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium"><?= e($p['name']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($cats[$p['income_category']] ?? $p['income_category']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= $cpTarget > 0 ? money($cpTarget) : money($p['income']) ?></td>
                            <td class="px-4 py-2">
                                <?php if ($cpTarget > 0): ?>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-slate-200 rounded-full h-1.5">
                                        <div class="<?= $cpBarColor ?> h-1.5 rounded-full" style="width: <?= $cpBarPct ?>%"></div>
                                    </div>
                                    <span class="text-xs tabular-nums whitespace-nowrap <?= $cpPct > 100 ? 'text-amber-600 font-medium' : 'text-slate-500' ?>">
                                        <?= money($cpInvoiced) ?> / <?= money($cpTarget) ?> (<?= $cpPct ?>%)
                                    </span>
                                </div>
                                <?php else: ?>
                                <span class="text-slate-300 text-xs">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2 text-slate-500 text-xs">
                                <?= formatDate($p['start_date']) ?>
                                <?php if ($p['end_date']): ?> → <?= formatDate($p['end_date']) ?><?php endif ?>
                            </td>
                            <td class="px-4 py-2 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= statusBadge($p['status']) ?>"><?= ucfirst($p['status']) ?></span>
                            </td>
                            <td class="px-4 py-2 text-right">
                                <a href="/projects/<?= $p['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">No projects yet.</p>
        <?php endif ?>
    </section>

    <!-- Expenses -->
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">Expenses</h2>
            <a href="/expenses/create" class="text-xs text-accent-600 hover:underline">+ Add Expense</a>
        </div>
        <?php if ($client['expenses']): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-left">Category</th>
                        <th class="px-4 py-2 text-right">Amount</th>
                        <th class="px-4 py-2 text-left">Cycle</th>
                        <th class="px-4 py-2 text-left">Date</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    $expCats = \CoyshCRM\Models\Expense::categories();
                    foreach ($client['expenses'] as $exp): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium"><?= e($exp['name']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($expCats[$exp['category']] ?? $exp['category']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= money($exp['amount']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= ucfirst($exp['billing_cycle'] ?: '—') ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= formatDate($exp['date']) ?></td>
                            <td class="px-4 py-2 text-right">
                                <a href="/expenses/<?= $exp['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">No expenses linked to this client.</p>
        <?php endif ?>
    </section>


    <!-- Attachments -->
    <section>
        <div class="flex items-center justify-between mb-2"><h2 class="text-sm font-semibold text-slate-700">PDF Attachments</h2></div>
        <form method="POST" enctype="multipart/form-data" action="/clients/<?= $client['id'] ?>/attachments" class="bg-white border border-slate-200 rounded-lg p-4 flex gap-2 items-center">
            <select name="type" class="border rounded px-2 py-1 text-sm"><option value="proposal">Proposal</option><option value="contract">Contract</option></select>
            <input type="file" name="attachment" accept="application/pdf" class="text-sm" required>
            <button class="px-3 py-1.5 bg-accent-600 text-white text-sm rounded">Upload PDF</button>
        </form>
        <?php if (!empty($client['attachments'])): ?>
            <ul class="mt-2 text-sm bg-white border border-slate-200 rounded-lg divide-y">
                <?php foreach ($client['attachments'] as $a): ?>
                    <li class="p-3 flex justify-between"><span><?= ucfirst(e($a['type'])) ?> · <?= e($a['original_name']) ?></span><span><a class="text-accent-600" href="/clients/<?= $client['id'] ?>/attachments/<?= $a['id'] ?>/download">View</a> <form method="POST" action="/clients/<?= $client['id'] ?>/attachments/<?= $a['id'] ?>/delete" class="inline"><button class="text-red-500 ml-2">Delete</button></form></span></li>
                <?php endforeach ?>
            </ul>
        <?php endif ?>
    </section>

    <!-- FreeAgent Data -->
    <?php if ($faConnected): ?>
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">FreeAgent</h2>
            <?php if (!$faContact): ?>
                <a href="/settings/freeagent/contacts" class="text-xs text-amber-600 hover:underline">
                    No contact mapped — set up in Contact Mapping
                </a>
            <?php endif ?>
        </div>

        <?php if ($faContact): ?>
        <div id="fa-data" class="space-y-4">
            <p class="text-sm text-slate-400">Loading FreeAgent data…</p>
        </div>
        <script>
        fetch('/freeagent/client/<?= $client['id'] ?>')
            .then(r => r.json())
            .then(data => {
                const container = document.getElementById('fa-data');
                if (!data.invoices.length && !data.transactions.length) {
                    container.innerHTML = '<p class="text-sm text-slate-400">No invoices or transactions linked to this client.</p>';
                    return;
                }

                let html = '';

                // Summary
                html += '<div class="bg-white border border-slate-200 rounded-lg p-4">'
                      + '<p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Total Invoiced</p>'
                      + '<p class="text-lg font-semibold text-slate-800 mt-0.5">£' + data.totalInvoiced.toFixed(2) + '</p>'
                      + '</div>';

                // Invoices
                if (data.invoices.length) {
                    html += '<div class="bg-white border border-slate-200 rounded-lg overflow-hidden">'
                          + '<div class="px-4 py-2.5 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wide">Invoices</div>'
                          + '<div class="overflow-x-auto"><table class="w-full text-sm">'
                          + '<thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide"><tr>'
                          + '<th class="px-4 py-2 text-left">Reference</th>'
                          + '<th class="px-4 py-2 text-right">Amount</th>'
                          + '<th class="px-4 py-2 text-center">Status</th>'
                          + '<th class="px-4 py-2 text-left">Date</th>'
                          + '<th class="px-4 py-2 text-left">Source</th>'
                          + '</tr></thead><tbody class="divide-y divide-slate-100">';
                    data.invoices.forEach(inv => {
                        const statusClr = inv.status === 'paid' ? 'bg-green-100 text-green-700'
                                        : inv.status === 'overdue' ? 'bg-red-100 text-red-700'
                                        : inv.status === 'sent' ? 'bg-blue-100 text-blue-700'
                                        : 'bg-slate-100 text-slate-600';
                        const isHiveage = inv.source === 'hiveage';
                        const faNumericId = inv.freeagent_url ? inv.freeagent_url.split('/').pop() : null;
                        const refCell = (!isHiveage && faNumericId)
                            ? `<a href="https://coyshdigital.freeagent.com/invoices/${faNumericId}" target="_blank" rel="noopener" class="font-mono text-xs text-accent-600 hover:underline">${inv.reference || '—'}</a>`
                            : `<span class="font-mono text-xs">${inv.reference || '—'}</span>`;
                        const sourceBadge = isHiveage
                            ? '<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">Hiveage</span>'
                            : '<span class="text-xs text-slate-400">FreeAgent</span>';
                        html += `<tr class="hover:bg-slate-50">
                            <td class="px-4 py-2">${refCell}</td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium">£${parseFloat(inv.total_value).toFixed(2)}</td>
                            <td class="px-4 py-2 text-center"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium ${statusClr}">${inv.status || 'unknown'}</span></td>
                            <td class="px-4 py-2 text-slate-500">${inv.dated_on || '—'}</td>
                            <td class="px-4 py-2">${sourceBadge}</td>
                        </tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }

                // Transactions
                if (data.transactions.length) {
                    html += '<div class="bg-white border border-slate-200 rounded-lg overflow-hidden">'
                          + '<div class="px-4 py-2.5 border-b border-slate-200 text-xs font-semibold text-slate-600 uppercase tracking-wide">Bank Transactions (expenses)</div>'
                          + '<div class="overflow-x-auto"><table class="w-full text-sm">'
                          + '<thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide"><tr>'
                          + '<th class="px-4 py-2 text-left">Description</th>'
                          + '<th class="px-4 py-2 text-right">Amount</th>'
                          + '<th class="px-4 py-2 text-left">Category</th>'
                          + '<th class="px-4 py-2 text-left">Date</th>'
                          + '</tr></thead><tbody class="divide-y divide-slate-100">';
                    data.transactions.forEach(tx => {
                        html += `<tr class="hover:bg-slate-50">
                            <td class="px-4 py-2">${tx.description || '—'}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-red-600 font-medium">£${Math.abs(parseFloat(tx.gross_value)).toFixed(2)}</td>
                            <td class="px-4 py-2 text-slate-500 font-mono text-xs">${tx.freeagent_category || '—'}</td>
                            <td class="px-4 py-2 text-slate-500">${tx.dated_on || '—'}</td>
                        </tr>`;
                    });
                    html += '</tbody></table></div></div>';
                }

                container.innerHTML = html;
            })
            .catch(() => {
                document.getElementById('fa-data').innerHTML =
                    '<p class="text-sm text-red-500">Failed to load FreeAgent data.</p>';
            });
        </script>
        <?php else: ?>
            <p class="text-sm text-slate-400">Map a FreeAgent contact to this client to see invoice and transaction data here.</p>
        <?php endif ?>
    </section>
    <?php endif ?>

</div>
