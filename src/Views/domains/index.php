<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Domains</h1>
        <a href="/domains/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            + Add Domain
        </a>
    </div>

    <!-- Summary cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
            <p class="text-xs text-slate-500">Total Domains</p>
            <p class="text-xl font-semibold text-slate-800 tabular-nums"><?= $summary['totalCount'] ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
            <p class="text-xs text-slate-500">Overdue</p>
            <p class="text-xl font-semibold <?= $summary['overdue'] > 0 ? 'text-red-600' : 'text-slate-800' ?> tabular-nums"><?= $summary['overdue'] ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
            <p class="text-xs text-slate-500">Due in 30 days</p>
            <p class="text-xl font-semibold <?= $summary['due30'] > 0 ? 'text-amber-600' : 'text-slate-800' ?> tabular-nums"><?= $summary['due30'] ?></p>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg px-4 py-3">
            <p class="text-xs text-slate-500">Total Annual Cost</p>
            <p class="text-xl font-semibold text-slate-800 tabular-nums"><?= money($summary['totalCost']) ?></p>
        </div>
    </div>

    <!-- Status tabs -->
    <div class="flex items-center gap-1 border-b border-slate-200 pb-0">
        <?php
        $currentStatus = $filters['statusFilter'] ?? 'active';
        foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $key => $label):
            $isActive = $currentStatus === $key;
            $params = $_GET;
            $params['status'] = $key;
            $qs = http_build_query($params);
        ?>
            <a href="/domains?<?= $qs ?>"
               class="px-3 py-2 text-sm font-medium border-b-2 -mb-px <?= $isActive ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
                <?= $label ?>
            </a>
        <?php endforeach ?>
    </div>

    <!-- Filters -->
    <form method="GET" action="/domains" class="bg-white border border-slate-200 rounded-lg px-4 py-3 flex flex-wrap gap-3 items-end">
        <input type="hidden" name="status" value="<?= e($filters['statusFilter'] ?? 'active') ?>">
        <div>
            <label class="block text-xs text-slate-500 mb-1">Search domain</label>
            <input type="text" name="search" value="<?= e($filters['search']) ?>"
                   placeholder="example.co.uk"
                   class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 w-48">
        </div>
        <?php if ($registrars): ?>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Registrar</label>
            <select name="registrar" class="border border-slate-300 rounded px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All</option>
                <?php foreach ($registrars as $r): ?>
                    <option value="<?= e($r) ?>" <?= $filters['registrar'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <?php endif ?>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Renewal</label>
            <select name="renewal" class="border border-slate-300 rounded px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All</option>
                <option value="overdue" <?= $filters['renewal'] === 'overdue' ? 'selected' : '' ?>>Overdue</option>
                <option value="30d" <?= $filters['renewal'] === '30d' ? 'selected' : '' ?>>Due in 30 days</option>
                <option value="90d" <?= $filters['renewal'] === '90d' ? 'selected' : '' ?>>Due in 90 days</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Client</label>
            <select name="client_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)$filters['clientFilter'] === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-slate-500 mb-1">Cloudflare</label>
            <select name="cf_linked" class="border border-slate-300 rounded px-3 py-1.5 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All</option>
                <option value="1" <?= ($filters['cfLinked'] ?? '') === '1' ? 'selected' : '' ?>>Linked</option>
                <option value="0" <?= ($filters['cfLinked'] ?? '') === '0' ? 'selected' : '' ?>>Not linked</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="px-3 py-1.5 bg-accent-600 text-white text-sm rounded hover:bg-accent-700">Filter</button>
            <a href="/domains" class="px-3 py-1.5 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">Reset</a>
        </div>
    </form>

    <!-- Table -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-3 py-2.5 w-8">
                            <input type="checkbox" id="select-all-domains" class="rounded border-slate-300 text-accent-600 focus:ring-accent-500" title="Select all">
                        </th>
                        <th class="px-4 py-2.5 text-left">Domain</th>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-left">Registrar</th>
                        <th class="px-4 py-2.5 text-left">Cloudflare</th>
                        <th class="px-4 py-2.5 text-left">Renewal Date</th>
                        <th class="px-4 py-2.5 text-right">Annual Cost</th>
                        <th class="px-4 py-2.5 text-center">Billed</th>
                        <th class="px-4 py-2.5 text-center">Payment</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="domains-table-body">
                    <?php foreach ($domains as $domain): ?>
                        <?php
                        $renewalCls = 'text-slate-500';
                        if ($domain['renewal_date']) {
                            if ($domain['renewal_date'] < $today) {
                                $renewalCls = 'text-red-600 font-medium';
                            } elseif ($domain['renewal_date'] <= $day30) {
                                $renewalCls = 'text-amber-600 font-medium';
                            }
                        }
                        ?>
                        <tr class="hover:bg-slate-50" id="domain-row-<?= $domain['id'] ?>">
                            <td class="px-3 py-2.5">
                                <input type="checkbox" class="domain-checkbox rounded border-slate-300 text-accent-600 focus:ring-accent-500"
                                       value="<?= $domain['id'] ?>" onchange="updateDomainBulkBar()">
                            </td>
                            <td class="px-4 py-2.5 font-medium font-mono text-sm">
                                <a href="/domains/<?= $domain['id'] ?>" class="text-accent-600 hover:underline"><?= e($domain['domain']) ?></a>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <?php if ($domain['client_id']): ?>
                                    <a href="/clients/<?= $domain['client_id'] ?>" class="hover:text-accent-600"><?= e($domain['client_name']) ?></a>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= $domain['registrar'] ? e($domain['registrar']) : '<span class="text-slate-300">—</span>' ?></td>
                            <td class="px-4 py-2.5">
                                <?php if (!empty($domain['cf_zone_id'])): ?>
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">
                                        Active<?= $domain['cf_plan'] ? ' (' . e($domain['cf_plan']) . ')' : '' ?>
                                    </span>
                                <?php elseif ($domain['cloudflare_proxied']): ?>
                                    <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700">Proxied</span>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 <?= $renewalCls ?>">
                                <?= $domain['renewal_date'] ? formatDate($domain['renewal_date']) : '<span class="text-slate-300">—</span>' ?>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums">
                                <?= $domain['annual_cost'] !== null ? formatCurrency($domain['annual_cost'], $domain['currency'] ?? 'GBP') : '<span class="text-slate-300">—</span>' ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <?php if (!empty($domain['linked_recurring_cost_id']) && $domain['client_id']): ?>
                                    <span class="text-green-500 font-bold" title="Billed via recurring cost">✓</span>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <?php
                                if (empty($domain['linked_recurring_cost_id']) || !$domain['client_id']) {
                                    echo '<span class="text-slate-300 text-xs">N/A</span>';
                                } elseif ($domain['linked_rc_renewal_date'] && $domain['linked_rc_renewal_date'] >= $today) {
                                    echo '<span class="text-green-600 text-xs font-medium">Paid</span>';
                                } else {
                                    echo '<span class="text-red-600 text-xs font-medium">Overdue</span>';
                                }
                                ?>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="/domains/<?= $domain['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700">Edit</a>
                                    <form method="POST" action="/domains/<?= $domain['id'] ?>/archive" class="inline">
                                        <button type="submit" class="text-xs text-slate-400 hover:text-slate-700"
                                                onclick="return confirm('<?= ($domain['status'] ?? 'active') === 'active' ? 'Archive' : 'Restore' ?> <?= e(addslashes($domain['domain'])) ?>?')">
                                            <?= ($domain['status'] ?? 'active') === 'active' ? 'Archive' : 'Restore' ?>
                                        </button>
                                    </form>
                                    <form method="POST" action="/domains/<?= $domain['id'] ?>/delete" class="inline"
                                          onsubmit="return confirm('Delete <?= e(addslashes($domain['domain'])) ?>?\nThis will unlink it from any sites and Cloudflare zones.')">
                                        <button type="submit" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($domains)): ?>
                        <tr><td colspan="10" class="px-4 py-8 text-center text-slate-400">No domains found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Bulk action bar -->
    <div id="domain-bulk-bar"
         class="fixed bottom-0 left-0 right-0 bg-slate-800 text-white px-6 py-3 flex items-center gap-4 shadow-xl z-50"
         style="display:none">
        <span id="domain-selected-count" class="text-sm font-medium">0 selected</span>
        <form method="POST" action="/domains/bulk-archive" id="form-domain-bulk-archive" class="inline">
            <div id="domain-archive-ids-container"></div>
            <button type="submit" onclick="return prepareBulkArchive()"
                    class="px-3 py-1.5 bg-slate-600 text-white text-sm font-medium rounded hover:bg-slate-700">
                Archive selected
            </button>
        </form>
        <button onclick="openDomainBulkDeleteModal()"
                class="px-3 py-1.5 bg-red-600 text-white text-sm font-medium rounded hover:bg-red-700">
            Delete selected
        </button>
        <button onclick="clearDomainSelection()" class="px-3 py-1.5 border border-slate-500 rounded text-sm hover:bg-slate-700">
            Clear selection
        </button>
    </div>

    <!-- Bulk delete modal -->
    <dialog id="modal-domain-bulk-delete" class="rounded-lg shadow-xl p-6 w-full max-w-md backdrop:bg-black/50">
        <h2 class="text-base font-semibold text-slate-800 mb-2">Delete selected domains?</h2>
        <p id="domain-bulk-delete-desc" class="text-sm text-slate-600 mb-1"></p>
        <p class="text-sm text-slate-500 mb-4">Sites linked to these domains will be unlinked, not deleted. Type <strong>DELETE</strong> to confirm.</p>
        <form method="POST" action="/domains/bulk-delete" id="form-domain-bulk-delete">
            <input type="text" name="confirm_text" id="domain-bulk-confirm-input" placeholder="Type DELETE"
                   oninput="checkDomainBulkConfirm()"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm mb-4 focus:outline-none focus:ring-2 focus:ring-red-400">
            <div id="domain-ids-container"></div>
            <div class="flex gap-3">
                <button type="submit" id="domain-bulk-confirm-btn" disabled
                        class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    Delete
                </button>
                <button type="button" onclick="document.getElementById('modal-domain-bulk-delete').close()"
                        class="px-4 py-2 border border-slate-300 rounded text-sm hover:bg-slate-50 text-slate-600">
                    Cancel
                </button>
            </div>
        </form>
    </dialog>

</div>

<script>
(function () {
    const bulkBar = document.getElementById('domain-bulk-bar');

    function getSelectedIds() {
        return [...document.querySelectorAll('.domain-checkbox:checked')].map(el => el.value);
    }

    window.updateDomainBulkBar = function () {
        const ids = getSelectedIds();
        const count = ids.length;
        document.getElementById('domain-selected-count').textContent =
            count + ' domain' + (count !== 1 ? 's' : '') + ' selected';
        bulkBar.style.display = count > 0 ? 'flex' : 'none';

        const selectAll = document.getElementById('select-all-domains');
        const allBoxes  = document.querySelectorAll('.domain-checkbox');
        selectAll.indeterminate = count > 0 && count < allBoxes.length;
        selectAll.checked = count > 0 && count === allBoxes.length;
    };

    window.clearDomainSelection = function () {
        document.querySelectorAll('.domain-checkbox').forEach(el => el.checked = false);
        document.getElementById('select-all-domains').checked = false;
        updateDomainBulkBar();
    };

    document.getElementById('select-all-domains').addEventListener('change', function () {
        document.querySelectorAll('.domain-checkbox').forEach(el => el.checked = this.checked);
        updateDomainBulkBar();
    });

    window.openDomainBulkDeleteModal = function () {
        const ids = getSelectedIds();
        if (!ids.length) return;
        document.getElementById('domain-bulk-delete-desc').textContent =
            'Permanently deleting ' + ids.length + ' domain' + (ids.length !== 1 ? 's' : '') + '.';
        const container = document.getElementById('domain-ids-container');
        container.innerHTML = '';
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'domain_ids[]'; inp.value = id;
            container.appendChild(inp);
        });
        document.getElementById('domain-bulk-confirm-input').value = '';
        document.getElementById('domain-bulk-confirm-btn').disabled = true;
        document.getElementById('modal-domain-bulk-delete').showModal();
    };

    window.checkDomainBulkConfirm = function () {
        const val = document.getElementById('domain-bulk-confirm-input').value.trim();
        document.getElementById('domain-bulk-confirm-btn').disabled = val !== 'DELETE';
    };

    window.prepareBulkArchive = function () {
        const ids = getSelectedIds();
        if (!ids.length) return false;
        if (!confirm('Archive ' + ids.length + ' domain' + (ids.length !== 1 ? 's' : '') + '?')) return false;
        const container = document.getElementById('domain-archive-ids-container');
        container.innerHTML = '';
        ids.forEach(id => {
            const inp = document.createElement('input');
            inp.type = 'hidden'; inp.name = 'domain_ids[]'; inp.value = id;
            container.appendChild(inp);
        });
        return true;
    };
})();
</script>
