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
            <h1 class="text-xl font-semibold text-slate-800"><?= e($client['name']) ?></h1>
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
        </div>
    </div>

    <!-- P&L Summary -->
    <div class="bg-white border border-slate-200 rounded-lg p-5 grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
        <?php
        $plItems = [
            ['MRR',          money($pl['mrr'])],
            ['Server Cost',  money($pl['serverCost'])],
            ['Domain Cost',  money($pl['domainCost'])],
            ['Direct Exp.',  money($pl['directExpenses'])],
            ['Monthly P&L',  money($pl['profit']),  $pColor],
            ['Margin',       number_format($pl['margin'], 1) . '%', $pColor],
        ];
        foreach ($plItems as $plItem):
            [$label, $value] = $plItem;
            $extraClass = $plItem[2] ?? 'text-slate-800';
        ?>
            <div>
                <p class="text-xs text-slate-400 font-medium uppercase tracking-wide"><?= $label ?></p>
                <p class="mt-0.5 text-lg font-semibold <?= $extraClass ?>"><?= $value ?></p>
            </div>
        <?php endforeach ?>
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
                            <td class="px-4 py-2 text-slate-500"><?= e($s['server_name'] ?: '—') ?></td>
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

    <!-- Service Packages -->
    <section>
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-semibold text-slate-700">Service Packages</h2>
            <a href="/clients/<?= $client['id'] ?>/packages/create" class="text-xs text-accent-600 hover:underline">+ Add Package</a>
        </div>
        <?php if ($client['packages']): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2 text-left">Name</th>
                        <th class="px-4 py-2 text-right">Fee</th>
                        <th class="px-4 py-2 text-right">Monthly</th>
                        <th class="px-4 py-2 text-left">Cycle</th>
                        <th class="px-4 py-2 text-left">Renewal</th>
                        <th class="px-4 py-2 text-center">Active</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($client['packages'] as $p): ?>
                        <?php $monthly = $p['billing_cycle'] === 'annual' ? $p['fee'] / 12 : $p['fee'] ?>
                        <tr class="hover:bg-slate-50 <?= !$p['is_active'] ? 'opacity-50' : '' ?>">
                            <td class="px-4 py-2 font-medium"><?= e($p['name']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= money($p['fee']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums text-slate-500"><?= money($monthly) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= ucfirst($p['billing_cycle']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= formatDate($p['renewal_date']) ?></td>
                            <td class="px-4 py-2 text-center"><?= $p['is_active'] ? '<span class="text-green-500">✓</span>' : '<span class="text-slate-300">—</span>' ?></td>
                            <td class="px-4 py-2 text-right">
                                <a href="/clients/<?= $client['id'] ?>/packages/<?= $p['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/clients/<?= $client['id'] ?>/packages/<?= $p['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this package?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php else: ?>
            <p class="text-sm text-slate-400">No packages yet. <a href="/clients/<?= $client['id'] ?>/packages/create" class="text-accent-600 hover:underline">Add one</a>.</p>
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
                        <th class="px-4 py-2 text-right">Income</th>
                        <th class="px-4 py-2 text-left">Dates</th>
                        <th class="px-4 py-2 text-center">Status</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php
                    $cats = \CoyshCRM\Models\Project::incomeCategories();
                    foreach ($client['projects'] as $p): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-medium"><?= e($p['name']) ?></td>
                            <td class="px-4 py-2 text-slate-500"><?= e($cats[$p['income_category']] ?? $p['income_category']) ?></td>
                            <td class="px-4 py-2 text-right tabular-nums"><?= money($p['income']) ?></td>
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
                    container.innerHTML = '<p class="text-sm text-slate-400">No FreeAgent invoices or transactions linked to this client.</p>';
                    return;
                }

                let html = '';

                // Summary
                html += '<div class="bg-white border border-slate-200 rounded-lg p-4">'
                      + '<p class="text-xs text-slate-400 uppercase tracking-wide font-medium">Total Invoiced (FreeAgent)</p>'
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
                          + '</tr></thead><tbody class="divide-y divide-slate-100">';
                    data.invoices.forEach(inv => {
                        const statusClr = inv.status === 'paid' ? 'bg-green-100 text-green-700'
                                        : inv.status === 'overdue' ? 'bg-red-100 text-red-700'
                                        : inv.status === 'sent' ? 'bg-blue-100 text-blue-700'
                                        : 'bg-slate-100 text-slate-600';
                        html += `<tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 font-mono text-xs">${inv.reference || '—'}</td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium">£${parseFloat(inv.total_value).toFixed(2)}</td>
                            <td class="px-4 py-2 text-center"><span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium ${statusClr}">${inv.status || 'unknown'}</span></td>
                            <td class="px-4 py-2 text-slate-500">${inv.dated_on || '—'}</td>
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
