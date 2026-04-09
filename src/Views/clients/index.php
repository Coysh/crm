<?php
$sortBase = '?status=' . e($filter);
foreach ($filters as $k => $v) {
    if (in_array($k, ['sort','dir'])) continue;
    if ($v !== '' && $v !== 'all') $sortBase .= '&' . e($k) . '=' . e($v);
}
function sortLink(string $col, string $label, array $filters, string $sortBase): string {
    $cur = $filters['sort'] ?? 'name';
    $dir = $filters['dir'] ?? 'ASC';
    $newDir = ($cur === $col && $dir === 'ASC') ? 'DESC' : 'ASC';
    $arrow = $cur === $col ? ($dir === 'ASC' ? ' ↑' : ' ↓') : '';
    return '<a href="' . $sortBase . '&sort=' . $col . '&dir=' . $newDir . '" class="hover:text-slate-800">' . $label . $arrow . '</a>';
}
?>
<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Clients</h1>
        <div class="flex items-center gap-2">
            <?php if ($filter === 'archived' && !empty($clients)): ?>
                <button onclick="document.getElementById('modal-delete-all').showModal()"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-red-600 text-white text-sm font-medium rounded hover:bg-red-700">
                    Delete All Archived
                </button>
            <?php endif ?>
            <a href="/clients/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                + Add Client
            </a>
        </div>
    </div>

    <!-- Status filter tabs -->
    <div class="flex gap-1 border-b border-slate-200">
        <?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $val => $label): ?>
            <?php $isActive = $filter === $val ?>
            <a href="?status=<?= $val ?>"
               class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?= $isActive ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
                <?= $label ?>
            </a>
        <?php endforeach ?>
    </div>

    <!-- Filter bar -->
    <form method="GET" class="flex flex-wrap items-end gap-2" id="filter-form">
        <input type="hidden" name="status" value="<?= e($filter) ?>">

        <div>
            <label class="block text-xs text-slate-500 mb-1">Search</label>
            <input type="text" name="search" value="<?= e($filters['search']) ?>"
                   placeholder="Name, contact, email…"
                   class="border border-slate-300 rounded px-3 py-1.5 text-sm w-44 focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">Health</label>
            <select name="health" class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="all"       <?= $filters['health'] === 'all'       ? 'selected' : '' ?>>All</option>
                <option value="healthy"   <?= $filters['health'] === 'healthy'   ? 'selected' : '' ?>>Healthy</option>
                <option value="attention" <?= $filters['health'] === 'attention' ? 'selected' : '' ?>>Attention</option>
                <option value="at_risk"   <?= $filters['health'] === 'at_risk'   ? 'selected' : '' ?>>At Risk</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">Recurring Income</label>
            <select name="has_recurring" class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="all" <?= $filters['has_recurring'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="yes" <?= $filters['has_recurring'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                <option value="no"  <?= $filters['has_recurring'] === 'no'  ? 'selected' : '' ?>>No</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">Has Sites</label>
            <select name="has_sites" class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="all" <?= $filters['has_sites'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="yes" <?= $filters['has_sites'] === 'yes' ? 'selected' : '' ?>>Yes</option>
                <option value="no"  <?= $filters['has_sites'] === 'no'  ? 'selected' : '' ?>>No</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">Revenue</label>
            <select name="mrr_range" class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="all"     <?= $filters['mrr_range'] === 'all'     ? 'selected' : '' ?>>All</option>
                <option value="zero"    <?= $filters['mrr_range'] === 'zero'    ? 'selected' : '' ?>>£0 (none)</option>
                <option value="1_100"   <?= $filters['mrr_range'] === '1_100'   ? 'selected' : '' ?>>£1–100/mo</option>
                <option value="100_500" <?= $filters['mrr_range'] === '100_500' ? 'selected' : '' ?>>£100–500/mo</option>
                <option value="500plus" <?= $filters['mrr_range'] === '500plus' ? 'selected' : '' ?>>£500+/mo</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">Cloudflare</label>
            <select name="cloudflare" class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="all" <?= $filters['cloudflare'] === 'all' ? 'selected' : '' ?>>All</option>
                <option value="yes" <?= $filters['cloudflare'] === 'yes' ? 'selected' : '' ?>>Has Cloudflare</option>
                <option value="no"  <?= $filters['cloudflare'] === 'no'  ? 'selected' : '' ?>>No Cloudflare</option>
            </select>
        </div>

        <div>
            <label class="block text-xs text-slate-500 mb-1">Type</label>
            <select name="client_type" class="border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="all"               <?= ($filters['client_type'] ?? 'all') === 'all'               ? 'selected' : '' ?>>All</option>
                <option value="managed"           <?= ($filters['client_type'] ?? 'all') === 'managed'           ? 'selected' : '' ?>>Managed</option>
                <option value="support_only"      <?= ($filters['client_type'] ?? 'all') === 'support_only'      ? 'selected' : '' ?>>Support Only</option>
                <option value="consultancy_only"  <?= ($filters['client_type'] ?? 'all') === 'consultancy_only'  ? 'selected' : '' ?>>Consultancy Only</option>
            </select>
        </div>

        <button type="submit" class="px-3 py-1.5 bg-slate-100 border border-slate-300 rounded text-sm hover:bg-slate-200 self-end">Filter</button>

        <?php if ($activeFilterCount > 0): ?>
            <a href="?status=<?= e($filter) ?>" class="self-end text-xs text-slate-400 hover:text-slate-700">
                Clear
                <span class="inline-block ml-1 px-1.5 py-0.5 bg-accent-100 text-accent-700 rounded text-xs font-medium"><?= $activeFilterCount ?></span>
            </a>
        <?php endif ?>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-3 py-2.5 w-8">
                            <input type="checkbox" id="select-all" class="rounded border-slate-300 text-accent-600 focus:ring-accent-500" title="Select all">
                        </th>
                        <th class="px-4 py-2.5 text-left"><?= sortLink('name', 'Client', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-left">Contact</th>
                        <th class="px-4 py-2.5 text-left"><?= sortLink('type', 'Type', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-center"><?= sortLink('sites', 'Sites', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-right"><?= sortLink('mrr', 'Monthly Recurring Rev.', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-right"><?= sortLink('total_invoiced', 'Invoiced', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-right"><?= sortLink('outstanding', 'Outstanding', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-center"><?= sortLink('health', 'Health', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5 text-center"><?= sortLink('status', 'Status', $filters, $sortBase) ?></th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100" id="clients-table-body">
                    <?php foreach ($clients as $client):
                        $cid    = (int)$client['id'];
                        $health = $clientHealth[$cid] ?? ['status' => 'healthy'];
                        $healthDot = match($health['status']) {
                            'healthy'   => 'bg-green-500',
                            'attention' => 'bg-amber-400',
                            default     => 'bg-red-500',
                        };
                        $healthTitle = match($health['status']) {
                            'healthy'   => 'Healthy',
                            'attention' => 'Attention',
                            default     => 'At Risk',
                        };
                    ?>
                        <tr class="hover:bg-slate-50" id="client-row-<?= $cid ?>"
                            data-client-id="<?= $cid ?>"
                            data-client-name="<?= e($client['name']) ?>"
                            data-status="<?= e($client['status']) ?>">
                            <td class="px-3 py-2.5">
                                <input type="checkbox" class="row-checkbox rounded border-slate-300 text-accent-600 focus:ring-accent-500"
                                       value="<?= $cid ?>" onchange="updateBulkBar()">
                            </td>
                            <td class="px-4 py-2.5 font-medium">
                                <a href="/clients/<?= $client['id'] ?>" class="text-accent-600 hover:underline"><?= e($client['name']) ?></a>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <?php if ($client['contact_name']): ?>
                                    <?= e($client['contact_name']) ?>
                                    <?php if ($client['contact_email']): ?>
                                        <span class="text-xs ml-1">&lt;<?= e($client['contact_email']) ?>&gt;</span>
                                    <?php endif ?>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5">
                                <?php
                                $ct = $client['client_type'] ?? 'managed';
                                $ctBadge = match($ct) {
                                    'support_only'     => 'bg-purple-100 text-purple-700',
                                    'consultancy_only' => 'bg-teal-100 text-teal-700',
                                    default            => 'bg-blue-100 text-blue-700',
                                };
                                $ctLabel = match($ct) {
                                    'support_only'     => 'Support',
                                    'consultancy_only' => 'Consultancy',
                                    default            => 'Managed',
                                };
                                ?>
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?= $ctBadge ?>"><?= $ctLabel ?></span>
                            </td>
                            <td class="px-4 py-2.5 text-center tabular-nums"><?= (int)$client['site_count'] ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-medium"><?= money($client['mrr']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500">
                                <?= (float)$client['total_invoiced'] > 0 ? money($client['total_invoiced']) : '<span class="text-slate-300">—</span>' ?>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums <?= (float)$client['outstanding'] > 0 ? 'text-red-600 font-medium' : 'text-slate-300' ?>">
                                <?= (float)$client['outstanding'] > 0 ? money($client['outstanding']) : '—' ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-block w-2.5 h-2.5 rounded-full <?= $healthDot ?>" title="<?= $healthTitle ?>"></span>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= statusBadge($client['status']) ?>">
                                    <?= ucfirst($client['status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="/clients/<?= $client['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700">Edit</a>
                                    <?php if ($client['status'] === 'active'): ?>
                                        <button class="text-xs text-slate-400 hover:text-amber-600"
                                                onclick="archiveClient(<?= $cid ?>, <?= htmlspecialchars(json_encode($client['name']), ENT_QUOTES) ?>)">
                                            Archive
                                        </button>
                                    <?php endif ?>
                                    <?php if ($client['status'] === 'archived'): ?>
                                        <button class="text-xs text-red-500 hover:text-red-700"
                                                onclick="openDeleteModal(<?= $cid ?>, <?= htmlspecialchars(json_encode($client['name']), ENT_QUOTES) ?>)">
                                            Delete
                                        </button>
                                    <?php endif ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($clients)): ?>
                        <tr><td colspan="11" class="px-4 py-8 text-center text-slate-400">No clients found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Bulk action bar (sticky bottom) -->
<div id="bulk-bar" class="hidden fixed bottom-0 left-0 right-0 bg-white border-t border-slate-200 shadow-lg z-40 px-6 py-3">
    <div class="max-w-7xl mx-auto flex items-center gap-4">
        <span id="bulk-count" class="text-sm font-medium text-slate-700">0 selected</span>
        <div class="flex gap-2">
            <?php if ($filter !== 'archived'): ?>
                <button onclick="bulkAction('archive')"
                        class="px-3 py-1.5 bg-amber-100 text-amber-800 text-sm rounded hover:bg-amber-200 border border-amber-300">
                    Archive Selected
                </button>
            <?php endif ?>
            <?php if ($filter === 'archived'): ?>
                <button onclick="bulkAction('restore')"
                        class="px-3 py-1.5 bg-green-100 text-green-800 text-sm rounded hover:bg-green-200 border border-green-300">
                    Restore Selected
                </button>
                <button onclick="openBulkDeleteModal()"
                        class="px-3 py-1.5 bg-red-100 text-red-800 text-sm rounded hover:bg-red-200 border border-red-300">
                    Delete Selected
                </button>
            <?php endif ?>
            <button onclick="clearSelection()" class="px-3 py-1.5 text-slate-500 text-sm hover:text-slate-800">Clear</button>
        </div>
    </div>
</div>

<!-- Delete Single Client Modal -->
<dialog id="modal-delete-client" class="rounded-lg shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
    <div class="p-6 space-y-4">
        <h3 class="text-base font-semibold text-slate-800">Permanently Delete Client</h3>
        <p class="text-sm text-slate-600" id="modal-delete-desc">
            This will also delete all their sites, domains, expenses, and linked data. This cannot be undone.
        </p>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Type the client name to confirm</label>
            <input type="text" id="modal-delete-name-input"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                   oninput="checkDeleteName()">
        </div>
        <form id="modal-delete-form" method="POST" action="">
            <input type="hidden" name="confirm_name" id="modal-delete-hidden">
            <div class="flex gap-3 pt-2">
                <button type="submit" id="modal-delete-confirm-btn" disabled
                        class="px-4 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    Delete Permanently
                </button>
                <button type="button" onclick="document.getElementById('modal-delete-client').close()"
                        class="px-4 py-2 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</dialog>

<!-- Delete All Archived Modal -->
<dialog id="modal-delete-all" class="rounded-lg shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
    <div class="p-6 space-y-4">
        <h3 class="text-base font-semibold text-slate-800">Delete All Archived Clients</h3>
        <p class="text-sm text-slate-600">
            This will permanently delete all archived clients and all their sites, domains, expenses, and linked data. <strong>This cannot be undone.</strong>
        </p>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Type <code class="bg-slate-100 px-1 rounded">DELETE ALL</code> to confirm</label>
            <input type="text" id="modal-delete-all-input" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                   oninput="checkDeleteAll()">
        </div>
        <form method="POST" action="/clients/delete-all-archived">
            <input type="hidden" name="confirm_text" id="modal-delete-all-hidden">
            <div class="flex gap-3 pt-2">
                <button type="submit" id="modal-delete-all-btn" disabled
                        class="px-4 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    Delete All Archived
                </button>
                <button type="button" onclick="document.getElementById('modal-delete-all').close()"
                        class="px-4 py-2 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</dialog>

<!-- Bulk Delete Selected Modal -->
<dialog id="modal-bulk-delete" class="rounded-lg shadow-xl p-0 w-full max-w-md backdrop:bg-black/40">
    <div class="p-6 space-y-4">
        <h3 class="text-base font-semibold text-slate-800">Permanently Delete Selected Clients</h3>
        <p class="text-sm text-slate-600" id="bulk-delete-desc">
            This will permanently delete the selected archived clients and ALL their sites, domains, expenses, and linked data. This cannot be undone.
        </p>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Type <code class="bg-slate-100 px-1 rounded">DELETE</code> to confirm</label>
            <input type="text" id="modal-bulk-delete-input"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-500"
                   oninput="checkBulkDelete()">
        </div>
        <form id="modal-bulk-delete-form" method="POST" action="/clients/bulk-delete">
            <div id="bulk-delete-hidden-ids"></div>
            <input type="hidden" name="confirm_text" id="modal-bulk-delete-hidden">
            <div class="flex gap-3 pt-2">
                <button type="submit" id="modal-bulk-delete-btn" disabled
                        class="px-4 py-2 bg-red-600 text-white text-sm rounded hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">
                    Delete Permanently
                </button>
                <button type="button" onclick="document.getElementById('modal-bulk-delete').close()"
                        class="px-4 py-2 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
                    Cancel
                </button>
            </div>
        </form>
    </div>
</dialog>

<script>
// ── Individual actions ──────────────────────────────────────────────────────

function archiveClient(id, name) {
    if (!confirm('Archive "' + name + '"? They will be moved to the archived list.')) return;
    fetch('/clients/' + id + '/archive', {
        method: 'POST',
        headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('client-row-' + id);
            if (row) { row.remove(); updateBulkBar(); }
        } else {
            alert('Archive failed.');
        }
    })
    .catch(() => alert('Request failed.'));
}

let currentDeleteId = '', currentDeleteName = '';

function openDeleteModal(id, name) {
    currentDeleteId = id;
    currentDeleteName = name;
    document.getElementById('modal-delete-desc').textContent =
        'Permanently delete "' + name + '"? This will also delete all their sites, domains, expenses, and linked data. This cannot be undone.';
    document.getElementById('modal-delete-name-input').value = '';
    document.getElementById('modal-delete-hidden').value = '';
    document.getElementById('modal-delete-confirm-btn').disabled = true;
    document.getElementById('modal-delete-form').action = '/clients/' + id + '/delete';
    document.getElementById('modal-delete-client').showModal();
}

function checkDeleteName() {
    const typed = document.getElementById('modal-delete-name-input').value;
    const match = typed === currentDeleteName;
    document.getElementById('modal-delete-confirm-btn').disabled = !match;
    document.getElementById('modal-delete-hidden').value = match ? typed : '';
}

function checkDeleteAll() {
    const typed = document.getElementById('modal-delete-all-input').value;
    const match = typed === 'DELETE ALL';
    document.getElementById('modal-delete-all-btn').disabled = !match;
    document.getElementById('modal-delete-all-hidden').value = match ? typed : '';
}

// ── Checkbox / bulk selection ───────────────────────────────────────────────

function getCheckedIds() {
    return [...document.querySelectorAll('.row-checkbox:checked')].map(cb => parseInt(cb.value));
}

function updateBulkBar() {
    const ids = getCheckedIds();
    const bar = document.getElementById('bulk-bar');
    document.getElementById('bulk-count').textContent = ids.length + ' client' + (ids.length !== 1 ? 's' : '') + ' selected';
    bar.classList.toggle('hidden', ids.length === 0);

    // Sync select-all checkbox state
    const all = document.querySelectorAll('.row-checkbox');
    const selectAll = document.getElementById('select-all');
    if (ids.length === 0) { selectAll.indeterminate = false; selectAll.checked = false; }
    else if (ids.length === all.length) { selectAll.indeterminate = false; selectAll.checked = true; }
    else { selectAll.indeterminate = true; }
}

document.getElementById('select-all').addEventListener('change', function() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
    updateBulkBar();
});

function clearSelection() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = false);
    document.getElementById('select-all').checked = false;
    document.getElementById('select-all').indeterminate = false;
    document.getElementById('bulk-bar').classList.add('hidden');
}

// ── Bulk actions ────────────────────────────────────────────────────────────

function bulkAction(action) {
    const ids = getCheckedIds();
    if (ids.length === 0) return;

    const label = action === 'archive' ? 'Archive' : 'Restore';
    if (!confirm(label + ' ' + ids.length + ' client' + (ids.length !== 1 ? 's' : '') + '?')) return;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = '/clients/bulk-' + action;
    ids.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'client_ids[]';
        inp.value = id;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
}

function openBulkDeleteModal() {
    const ids = getCheckedIds();
    if (ids.length === 0) return;

    document.getElementById('bulk-delete-desc').textContent =
        'Permanently delete ' + ids.length + ' archived client' + (ids.length !== 1 ? 's' : '') +
        ' and ALL their sites, domains, expenses, and linked data? This cannot be undone.';

    // Populate hidden ID inputs
    const container = document.getElementById('bulk-delete-hidden-ids');
    container.innerHTML = '';
    ids.forEach(id => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'client_ids[]';
        inp.value = id;
        container.appendChild(inp);
    });

    document.getElementById('modal-bulk-delete-input').value = '';
    document.getElementById('modal-bulk-delete-hidden').value = '';
    document.getElementById('modal-bulk-delete-btn').disabled = true;
    document.getElementById('modal-bulk-delete').showModal();
}

function checkBulkDelete() {
    const typed = document.getElementById('modal-bulk-delete-input').value;
    const match = typed === 'DELETE';
    document.getElementById('modal-bulk-delete-btn').disabled = !match;
    document.getElementById('modal-bulk-delete-hidden').value = match ? typed : '';
}
</script>
