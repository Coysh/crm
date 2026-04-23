<div class="max-w-2xl space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-800 font-mono"><?= e($domain['domain']) ?></h1>
            <?php if ($client): ?>
                <p class="text-sm text-slate-500 mt-1">
                    Client: <a href="/clients/<?= $client['id'] ?>" class="text-accent-600 hover:underline"><?= e($client['name']) ?></a>
                </p>
            <?php endif ?>
        </div>
        <a href="/domains/<?= $domain['id'] ?>/edit" class="px-3 py-1.5 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
            Edit
        </a>
    </div>

    <!-- Domain Details -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">Domain Details</h2>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Registrar</dt>
                <dd class="mt-0.5 font-medium text-slate-800"><?= $domain['registrar'] ? e($domain['registrar']) : '<span class="text-slate-400">—</span>' ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Cloudflare Proxied</dt>
                <dd class="mt-0.5">
                    <?php if ($domain['cloudflare_proxied']): ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-orange-100 text-orange-700">Yes</span>
                    <?php else: ?>
                        <span class="text-slate-400">No</span>
                    <?php endif ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Renewal Date</dt>
                <dd class="mt-0.5 <?= ($domain['renewal_date'] && $domain['renewal_date'] < date('Y-m-d')) ? 'text-red-600 font-medium' : 'text-slate-800' ?>">
                    <?= $domain['renewal_date'] ? formatDate($domain['renewal_date']) : '<span class="text-slate-400">—</span>' ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Renewal Period</dt>
                <dd class="mt-0.5 text-slate-800"><?= (int)($domain['renewal_years'] ?? 1) ?> year<?= (int)($domain['renewal_years'] ?? 1) > 1 ? 's' : '' ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">My Cost <span class="text-slate-400 font-normal">(per renewal)</span></dt>
                <dd class="mt-0.5 font-medium text-slate-800">
                    <?= $domain['annual_cost'] !== null ? formatCurrency($domain['annual_cost'], $domain['currency'] ?? 'GBP') : '<span class="text-slate-400">—</span>' ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Client Charge <span class="text-slate-400 font-normal">(per renewal)</span></dt>
                <dd class="mt-0.5 font-medium text-slate-800">
                    <?= ($domain['client_charge'] ?? null) !== null ? formatCurrency($domain['client_charge'], $domain['client_charge_currency'] ?? 'GBP') : '<span class="text-slate-400">—</span>' ?>
                </dd>
            </div>
        </dl>
    </div>

    <!-- FreeAgent: Bills & Invoices -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-700">FreeAgent</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Bills are matched via the linked recurring cost; invoices via reference text containing
                    <span class="font-mono"><?= e($domain['domain']) ?></span>.
                </p>
            </div>
            <?php
            $stateLabels = [
                'paid'    => ['Paid',    'bg-green-100 text-green-700'],
                'overdue' => ['Overdue', 'bg-red-100 text-red-700'],
                'unpaid'  => ['Unpaid',  'bg-amber-100 text-amber-700'],
                'pending' => ['Pending', 'bg-amber-50 text-amber-600'],
                'unknown' => ['Unknown', 'bg-slate-100 text-slate-600'],
                'na'      => ['N/A',     'bg-slate-100 text-slate-500'],
            ];
            [$stateLabel, $stateCls] = $stateLabels[$paymentState ?? 'na'] ?? $stateLabels['na'];
            ?>
            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $stateCls ?> shrink-0">
                <?= $stateLabel ?>
            </span>
        </div>

        <?php if (!empty($recurringCost)): ?>
            <p class="text-xs text-slate-500">
                Linked recurring cost:
                <a href="/expenses/recurring/<?= (int)$recurringCost['id'] ?>/edit" class="text-accent-600 hover:underline">
                    <?= e($recurringCost['name']) ?>
                </a>
                — <?= formatCurrency((float)$recurringCost['amount'], $recurringCost['currency'] ?? 'GBP') ?>
                / <?= e($recurringCost['billing_cycle']) ?>
                <?php if (!empty($recurringCost['renewal_date'])): ?>
                    · next renewal <?= formatDate($recurringCost['renewal_date']) ?>
                <?php endif ?>
            </p>
        <?php elseif ($domain['client_id']): ?>
            <p class="text-xs text-slate-500">
                No recurring cost linked yet.
                <a href="/domains/<?= (int)$domain['id'] ?>/edit" class="text-accent-600 hover:underline">
                    Create one
                </a>
                to track billing for this domain.
            </p>
        <?php endif ?>

        <!-- Bills (supplier-side, what you pay the registrar) -->
        <div>
            <h3 class="text-xs font-semibold text-slate-600 uppercase tracking-wide mb-2">
                Bills <span class="text-slate-400 font-normal">(what you pay)</span>
            </h3>
            <?php if (empty($bills)): ?>
                <p class="text-xs text-slate-400 mb-2">No matching FreeAgent bills.</p>
            <?php else: ?>
                <div class="overflow-x-auto -mx-2 mb-2">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-slate-500">
                            <tr class="border-b border-slate-100">
                                <th class="px-2 py-1.5 text-left font-medium">Date</th>
                                <th class="px-2 py-1.5 text-left font-medium">Reference</th>
                                <th class="px-2 py-1.5 text-left font-medium">Supplier</th>
                                <th class="px-2 py-1.5 text-right font-medium">Amount</th>
                                <th class="px-2 py-1.5 text-left font-medium">Status</th>
                                <th class="px-2 py-1.5 text-left font-medium">Due</th>
                                <th class="px-2 py-1.5 text-left font-medium">Link</th>
                                <th class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($bills as $b): ?>
                                <?php
                                $bs = strtolower((string)$b['status']);
                                $bsCls = match (true) {
                                    $bs === 'paid'                 => 'text-green-600',
                                    $bs === 'overdue'              => 'text-red-600',
                                    in_array($bs, ['open', 'sent']) => 'text-amber-600',
                                    default                        => 'text-slate-500',
                                };
                                $isManual = ($b['link_type'] ?? null) === 'manual';
                                ?>
                                <tr>
                                    <td class="px-2 py-1.5 text-slate-500"><?= $b['dated_on'] ? formatDate($b['dated_on']) : '—' ?></td>
                                    <td class="px-2 py-1.5 font-mono text-xs"><?= e($b['reference'] ?: '—') ?></td>
                                    <td class="px-2 py-1.5 text-slate-600"><?= e($b['contact_name'] ?: '—') ?></td>
                                    <td class="px-2 py-1.5 text-right tabular-nums"><?= formatCurrency((float)$b['total_value'], $b['currency'] ?? 'GBP') ?></td>
                                    <td class="px-2 py-1.5 text-xs font-medium <?= $bsCls ?>"><?= e(ucfirst($b['status'] ?: '—')) ?></td>
                                    <td class="px-2 py-1.5 text-slate-500 text-xs"><?= $b['due_date'] ? formatDate($b['due_date']) : '—' ?></td>
                                    <td class="px-2 py-1.5 text-xs">
                                        <?php if ($isManual): ?>
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-accent-50 text-accent-700" title="Linked manually">Manual</span>
                                        <?php else: ?>
                                            <span class="text-slate-400" title="Auto-matched via recurring cost">Auto</span>
                                        <?php endif ?>
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        <?php if ($isManual): ?>
                                            <form method="POST" action="/domains/<?= (int)$domain['id'] ?>/bills/<?= (int)$b['id'] ?>/unlink" class="inline"
                                                  onsubmit="return confirm('Unlink this bill from the domain?')">
                                                <button type="submit" class="text-xs text-slate-400 hover:text-red-600">Unlink</button>
                                            </form>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>

            <?php if (!empty($candidateBills)): ?>
                <form method="POST" action="/domains/<?= (int)$domain['id'] ?>/bills/link" class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="block text-xs text-slate-500 mb-1">Link an existing bill</label>
                        <select name="freeagent_bill_id" required
                                class="w-full border border-slate-300 rounded px-2 py-1 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                            <option value="">Choose a bill…</option>
                            <?php foreach ($candidateBills as $cb): ?>
                                <option value="<?= (int)$cb['id'] ?>">
                                    <?= $cb['dated_on'] ? e(formatDate($cb['dated_on'])) : '—' ?>
                                    · <?= e($cb['reference'] ?: 'no ref') ?>
                                    · <?= e($cb['contact_name'] ?: 'no supplier') ?>
                                    · <?= e(formatCurrency((float)$cb['total_value'], $cb['currency'] ?? 'GBP')) ?>
                                    · <?= e(ucfirst($cb['status'] ?: '—')) ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <button type="submit" class="px-3 py-1 bg-accent-600 text-white text-xs font-medium rounded hover:bg-accent-700">
                        Link bill
                    </button>
                </form>
            <?php else: ?>
                <p class="text-xs text-slate-400">No bills available to link. <a href="/freeagent" class="text-accent-600 hover:underline">Run a FreeAgent sync.</a></p>
            <?php endif ?>
        </div>

        <!-- Invoices (client-side, what the client pays you) -->
        <div>
            <h3 class="text-xs font-semibold text-slate-600 uppercase tracking-wide mb-2">
                Invoices <span class="text-slate-400 font-normal">(what the client pays)</span>
            </h3>
            <?php if (empty($invoices)): ?>
                <p class="text-xs text-slate-400 mb-2">
                    <?php if (!$domain['client_id']): ?>
                        No client assigned to this domain.
                    <?php else: ?>
                        No FreeAgent invoices linked yet.
                    <?php endif ?>
                </p>
            <?php else: ?>
                <div class="overflow-x-auto -mx-2 mb-2">
                    <table class="w-full text-sm">
                        <thead class="text-xs text-slate-500">
                            <tr class="border-b border-slate-100">
                                <th class="px-2 py-1.5 text-left font-medium">Date</th>
                                <th class="px-2 py-1.5 text-left font-medium">Reference</th>
                                <th class="px-2 py-1.5 text-right font-medium">Amount</th>
                                <th class="px-2 py-1.5 text-left font-medium">Status</th>
                                <th class="px-2 py-1.5 text-left font-medium">Due</th>
                                <th class="px-2 py-1.5 text-left font-medium">Paid</th>
                                <th class="px-2 py-1.5 text-left font-medium">Link</th>
                                <th class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                $is = strtolower((string)($inv['status_override'] ?? $inv['status']));
                                $isCls = match (true) {
                                    $is === 'paid'                 => 'text-green-600',
                                    $is === 'overdue'              => 'text-red-600',
                                    in_array($is, ['sent', 'open']) => 'text-amber-600',
                                    default                        => 'text-slate-500',
                                };
                                $isManual = ($inv['link_type'] ?? null) === 'manual';
                                ?>
                                <tr>
                                    <td class="px-2 py-1.5 text-slate-500"><?= $inv['dated_on'] ? formatDate($inv['dated_on']) : '—' ?></td>
                                    <td class="px-2 py-1.5 font-mono text-xs"><?= e($inv['reference'] ?: '—') ?></td>
                                    <td class="px-2 py-1.5 text-right tabular-nums"><?= formatCurrency((float)$inv['total_value'], $inv['currency'] ?? 'GBP') ?></td>
                                    <td class="px-2 py-1.5 text-xs font-medium <?= $isCls ?>">
                                        <?= e(ucfirst((string)($inv['status_override'] ?? $inv['status'] ?: '—'))) ?>
                                        <?php if (!empty($inv['status_override'])): ?>
                                            <span class="text-slate-400 font-normal" title="Manual status override">*</span>
                                        <?php endif ?>
                                    </td>
                                    <td class="px-2 py-1.5 text-slate-500 text-xs"><?= $inv['due_date'] ? formatDate($inv['due_date']) : '—' ?></td>
                                    <td class="px-2 py-1.5 text-slate-500 text-xs"><?= $inv['paid_on'] ? formatDate($inv['paid_on']) : '—' ?></td>
                                    <td class="px-2 py-1.5 text-xs">
                                        <?php if ($isManual): ?>
                                            <span class="inline-block px-1.5 py-0.5 rounded bg-accent-50 text-accent-700" title="Linked manually">Manual</span>
                                        <?php else: ?>
                                            <span class="text-slate-400" title="Auto-matched by reference text">Auto</span>
                                        <?php endif ?>
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        <?php if ($isManual): ?>
                                            <form method="POST" action="/domains/<?= (int)$domain['id'] ?>/invoices/<?= (int)$inv['id'] ?>/unlink" class="inline"
                                                  onsubmit="return confirm('Unlink this invoice from the domain?')">
                                                <button type="submit" class="text-xs text-slate-400 hover:text-red-600">Unlink</button>
                                            </form>
                                        <?php endif ?>
                                    </td>
                                </tr>
                            <?php endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endif ?>

            <?php if (!empty($candidateInvoices)): ?>
                <form method="POST" action="/domains/<?= (int)$domain['id'] ?>/invoices/link" class="flex items-end gap-2">
                    <div class="flex-1">
                        <label class="block text-xs text-slate-500 mb-1">
                            Link an existing invoice
                            <?php if ($domain['client_id'] && $client): ?>
                                <span class="text-slate-400 font-normal">(scoped to <?= e($client['name']) ?>)</span>
                            <?php endif ?>
                        </label>
                        <select name="freeagent_invoice_id" required
                                class="w-full border border-slate-300 rounded px-2 py-1 text-xs bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                            <option value="">Choose an invoice…</option>
                            <?php foreach ($candidateInvoices as $ci): ?>
                                <?php $statusLabel = $ci['status_override'] ?? $ci['status']; ?>
                                <option value="<?= (int)$ci['id'] ?>">
                                    <?= $ci['dated_on'] ? e(formatDate($ci['dated_on'])) : '—' ?>
                                    · <?= e($ci['reference'] ?: 'no ref') ?>
                                    · <?= e(formatCurrency((float)$ci['total_value'], $ci['currency'] ?? 'GBP')) ?>
                                    · <?= e(ucfirst((string)($statusLabel ?: '—'))) ?>
                                    <?= !empty($ci['paid_on']) ? ' · paid ' . e(formatDate($ci['paid_on'])) : '' ?>
                                </option>
                            <?php endforeach ?>
                        </select>
                    </div>
                    <button type="submit" class="px-3 py-1 bg-accent-600 text-white text-xs font-medium rounded hover:bg-accent-700">
                        Link invoice
                    </button>
                </form>
            <?php elseif ($domain['client_id']): ?>
                <p class="text-xs text-slate-400">No invoices available to link for this client.</p>
            <?php else: ?>
                <p class="text-xs text-slate-400">Assign a client to this domain to link invoices.</p>
            <?php endif ?>
        </div>
    </div>

    <!-- Cloudflare Zone Panel -->
    <?php if ($cfZone): ?>
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Cloudflare Zone</h2>
            <a href="https://dash.cloudflare.com/?to=/:account/<?= e($cfZone['zone_id']) ?>" target="_blank" rel="noopener noreferrer"
               class="text-xs text-accent-600 hover:underline">
                Open in Cloudflare ↗
            </a>
        </div>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Zone ID</dt>
                <dd class="mt-0.5 font-mono text-xs text-slate-600"><?= e($cfZone['zone_id']) ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Status</dt>
                <dd class="mt-0.5">
                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $cfZone['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' ?>">
                        <?= e(ucfirst($cfZone['status'] ?? '—')) ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Plan</dt>
                <dd class="mt-0.5 text-slate-800"><?= $cfZone['plan'] ? e($cfZone['plan']) : '<span class="text-slate-400">—</span>' ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">SSL Status</dt>
                <dd class="mt-0.5 text-slate-800"><?= $cfZone['ssl_status'] ? e($cfZone['ssl_status']) : '<span class="text-slate-400">—</span>' ?></dd>
            </div>
            <?php if ($cfZone['name_servers']): ?>
            <div class="col-span-2">
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Nameservers</dt>
                <dd class="mt-0.5 text-slate-800 text-xs font-mono">
                    <?php
                    $ns = json_decode($cfZone['name_servers'], true);
                    echo is_array($ns) ? implode(', ', array_map('htmlspecialchars', $ns)) : e($cfZone['name_servers']);
                    ?>
                </dd>
            </div>
            <?php endif ?>
        </dl>
        <p class="text-xs text-slate-400">Last synced: <?= $cfZone['last_synced_at'] ? formatDate($cfZone['last_synced_at']) : '—' ?></p>
        <a href="/domains/<?= $domain['id'] ?>/dns" class="inline-block text-sm text-accent-600 hover:underline">View DNS Records →</a>
    </div>
    <?php endif ?>

    <p class="text-xs text-slate-400"><a href="/domains" class="hover:underline">← Back to Domains</a></p>
</div>
