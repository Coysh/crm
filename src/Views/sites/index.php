<?php
// Build filter options from query params
$qSearch   = $_GET['search']     ?? '';
$qServer   = $_GET['server']     ?? '';
$qStack    = $_GET['stack']      ?? '';
$qUnassigned = !empty($_GET['unassigned']);

function siteRow(array $s, array $allClients, bool $ploiConnected): void { ?>
    <tr class="hover:bg-slate-50 site-row
        <?= !$s['client_id'] ? 'bg-amber-50 hover:bg-amber-100' : '' ?>"
        data-domain="<?= strtolower(e($s['domain_name'] ?? '')) ?>"
        data-server="<?= $s['server_id'] ?>"
        data-stack="<?= strtolower(e($s['website_stack'] ?? '')) ?>"
        data-unassigned="<?= $s['client_id'] ? '0' : '1' ?>">

        <!-- Domain -->
        <td class="px-4 py-2.5 font-mono text-xs">
            <a href="/sites/<?= $s['id'] ?>" class="text-accent-600 hover:underline">
                <?= e($s['domain_name'] ?: '—') ?>
            </a>
            <?php if ($s['git_repo']): ?>
                <a href="<?= e($s['git_repo']) ?>" target="_blank" rel="noopener" class="ml-1 text-slate-400 hover:text-slate-700" title="Git repo">↗</a>
            <?php endif ?>
        </td>

        <!-- Client (quick-link dropdown) -->
        <td class="px-4 py-2.5 text-sm" id="client-cell-<?= $s['id'] ?>">
            <div class="flex items-center gap-1">
                <?php if ($s['client_id']): ?>
                    <a href="/clients/<?= $s['client_id'] ?>" class="text-accent-600 hover:underline text-sm">
                        <?= e($s['client_name']) ?>
                    </a>
                <?php else: ?>
                    <span class="text-amber-600 text-sm font-medium">Unassigned</span>
                <?php endif ?>
                <button onclick="toggleClientEdit(<?= $s['id'] ?>)"
                        class="text-slate-300 hover:text-slate-500 shrink-0" title="Change client">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/>
                    </svg>
                </button>
            </div>
            <div id="client-edit-<?= $s['id'] ?>" class="hidden mt-1 flex items-center gap-1">
                <select class="border border-slate-300 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-accent-500">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($allClients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $s['client_id'] == $c['id'] ? 'selected' : '' ?>>
                            <?= e($c['name']) ?>
                        </option>
                    <?php endforeach ?>
                </select>
                <button onclick="saveClientLink(<?= $s['id'] ?>, this.previousElementSibling)"
                        class="px-2 py-1 bg-accent-600 text-white text-xs rounded hover:bg-accent-700">Save</button>
                <button onclick="toggleClientEdit(<?= $s['id'] ?>)"
                        class="px-2 py-1 border border-slate-300 rounded text-xs hover:bg-slate-50">✕</button>
            </div>
        </td>

        <!-- Server -->
        <td class="px-4 py-2.5 text-sm text-slate-600">
            <?php if ($s['server_id']): ?>
                <a href="/servers/<?= $s['server_id'] ?>/edit" class="hover:underline"><?= e($s['server_name']) ?></a>
            <?php else: ?>
                <span class="text-slate-400 italic text-xs">External</span>
            <?php endif ?>
        </td>

        <!-- Stack -->
        <td class="px-4 py-2.5 text-sm text-slate-600"><?= e($s['website_stack'] ?: '—') ?></td>

        <!-- CSS -->
        <td class="px-4 py-2.5 text-sm text-slate-500 hidden lg:table-cell"><?= e($s['css_framework'] ?: '—') ?></td>

        <!-- SMTP -->
        <td class="px-4 py-2.5 text-sm text-slate-500 hidden xl:table-cell"><?= e($s['smtp_service'] ?: '—') ?></td>

        <!-- Git Repo -->
        <td class="px-4 py-2.5 text-xs hidden xl:table-cell">
            <?php if ($s['git_repo']): ?>
                <?php $repoDisplay = preg_replace('#^https?://(www\.)?#', '', $s['git_repo']); ?>
                <a href="<?= e($s['git_repo']) ?>" target="_blank" rel="noopener"
                   class="text-slate-400 hover:text-accent-600 truncate block max-w-[140px]"
                   title="<?= e($s['git_repo']) ?>">
                    <?= e($repoDisplay) ?>
                </a>
            <?php else: ?>
                <span class="text-slate-200">—</span>
            <?php endif ?>
        </td>

        <!-- Deployment -->
        <td class="px-4 py-2.5 text-center">
            <?= $s['has_deployment_pipeline']
                ? '<span class="text-green-500 font-bold">✓</span>'
                : '<span class="text-slate-200">—</span>' ?>
        </td>

        <?php if ($ploiConnected): ?>
        <!-- Ploi Status -->
        <td class="px-4 py-2.5 text-sm">
            <?php if ($s['ploi_site_id']): ?>
                <span class="flex items-center gap-1">
                    <span class="w-1.5 h-1.5 rounded-full <?= $s['ploi_status'] === 'active' ? 'bg-green-400' : 'bg-slate-300' ?>"></span>
                    <span class="text-slate-600"><?= e($s['ploi_status'] ?? '?') ?></span>
                </span>
            <?php else: ?>
                <span class="text-slate-200">—</span>
            <?php endif ?>
        </td>
        <?php endif ?>

        <!-- Actions -->
        <td class="px-4 py-2.5 text-right text-xs whitespace-nowrap">
            <a href="/sites/<?= $s['id'] ?>/edit" class="text-slate-400 hover:text-slate-700 mr-2">Edit</a>
            <form method="POST" action="/sites/<?= $s['id'] ?>/delete" class="inline">
                <button type="submit"
                        onclick="return confirm('Delete this site?')"
                        class="text-red-300 hover:text-red-600">Delete</button>
            </form>
        </td>
    </tr>
<?php }

function tableHeader(bool $ploiConnected): void { ?>
    <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide sticky top-0">
        <tr>
            <th class="px-4 py-2.5 text-left">Domain</th>
            <th class="px-4 py-2.5 text-left">Client</th>
            <th class="px-4 py-2.5 text-left">Server</th>
            <th class="px-4 py-2.5 text-left">Stack</th>
            <th class="px-4 py-2.5 text-left hidden lg:table-cell">CSS</th>
            <th class="px-4 py-2.5 text-left hidden xl:table-cell">SMTP</th>
            <th class="px-4 py-2.5 text-left hidden xl:table-cell">Git Repo</th>
            <th class="px-4 py-2.5 text-center">CI/CD</th>
            <?php if ($ploiConnected): ?><th class="px-4 py-2.5 text-left">Ploi</th><?php endif ?>
            <th class="px-4 py-2.5"></th>
        </tr>
    </thead>
<?php }

?>

<div class="space-y-4">

    <!-- Header -->
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Sites
            <span class="text-slate-400 font-normal text-base ml-1" id="site-count">(<?= count($sites) ?>)</span>
        </h1>
        <div class="flex items-center gap-2">
            <a href="/sites/matching" class="px-3 py-1.5 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
                Auto-match unassigned
            </a>
            <a href="/sites/create" class="px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                + Add Site
            </a>
        </div>
    </div>

    <!-- Grouping tabs -->
    <div class="flex items-center gap-1 border-b border-slate-200 pb-0">
        <?php foreach (['all' => 'All', 'server' => 'By Server', 'client' => 'By Client'] as $key => $label): ?>
            <a href="?group=<?= $key ?>"
               class="px-3 py-2 text-sm font-medium border-b-2 -mb-px <?= $group === $key ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' ?>">
                <?= $label ?>
            </a>
        <?php endforeach ?>
    </div>

    <!-- Filter bar -->
    <div class="flex flex-wrap items-center gap-3 bg-white border border-slate-200 rounded-lg px-4 py-3">
        <input type="text" id="filter-search" placeholder="Search domain…" value="<?= e($qSearch) ?>"
               class="border border-slate-300 rounded px-3 py-1.5 text-sm w-48 focus:outline-none focus:ring-2 focus:ring-accent-500"
               oninput="applyFilters()">

        <select id="filter-server" onchange="applyFilters()"
                class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <option value="">All servers</option>
            <?php foreach ($servers as $sv): ?>
                <option value="<?= $sv['id'] ?>" <?= $qServer == $sv['id'] ? 'selected' : '' ?>>
                    <?= e($sv['name']) ?>
                </option>
            <?php endforeach ?>
        </select>

        <select id="filter-stack" onchange="applyFilters()"
                class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <option value="">All stacks</option>
            <?php foreach ($stacks as $st): ?>
                <option value="<?= strtolower(e($st)) ?>" <?= strtolower($qStack) === strtolower($st) ? 'selected' : '' ?>>
                    <?= e($st) ?>
                </option>
            <?php endforeach ?>
        </select>

        <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
            <input type="checkbox" id="filter-unassigned" onchange="applyFilters()" <?= $qUnassigned ? 'checked' : '' ?>
                   class="rounded border-slate-300 text-accent-600 focus:ring-accent-500">
            Unassigned only
        </label>

        <button onclick="clearFilters()" class="text-xs text-slate-400 hover:text-slate-600 ml-auto">Clear filters</button>
    </div>

    <!-- Table -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">

        <?php if ($group === 'all'): ?>
            <table class="w-full text-sm" id="site-table">
                <?php tableHeader($ploiConnected) ?>
                <tbody class="divide-y divide-slate-100" id="site-tbody">
                    <?php foreach ($sites as $s): siteRow($s, $allClients, $ploiConnected); endforeach ?>
                    <tr id="no-results" class="hidden">
                        <td colspan="20" class="px-4 py-8 text-center text-sm text-slate-400">No sites match the current filters.</td>
                    </tr>
                </tbody>
            </table>

        <?php else: ?>
            <?php foreach ($grouped as $groupKey => $groupSites): ?>
                <div class="group-section"
                     data-group="<?= e($groupKey) ?>">
                    <div class="px-4 py-2 bg-slate-100 border-b border-slate-200 flex items-center gap-2">
                        <span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">
                            <?= $groupKey === 'Unassigned' ? '<span class="text-amber-600">Unassigned</span>' : e($groupKey) ?>
                        </span>
                        <span class="text-xs text-slate-400"><?= count($groupSites) ?> site<?= count($groupSites) !== 1 ? 's' : '' ?></span>
                    </div>
                    <table class="w-full text-sm">
                        <?php tableHeader($ploiConnected) ?>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($groupSites as $s): siteRow($s, $allClients, $ploiConnected); endforeach ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach ?>
        <?php endif ?>

        </div>
    </div>

</div>

<script>
function applyFilters() {
    const search     = document.getElementById('filter-search').value.toLowerCase();
    const server     = document.getElementById('filter-server').value;
    const stack      = document.getElementById('filter-stack').value;
    const unassigned = document.getElementById('filter-unassigned').checked;

    const rows = document.querySelectorAll('.site-row');
    let visible = 0;

    rows.forEach(row => {
        const domainMatch  = !search     || row.dataset.domain.includes(search);
        const serverMatch  = !server     || row.dataset.server === server;
        const stackMatch   = !stack      || row.dataset.stack === stack;
        const unassMatch   = !unassigned || row.dataset.unassigned === '1';

        const show = domainMatch && serverMatch && stackMatch && unassMatch;
        row.style.display = show ? '' : 'none';
        if (show) visible++;

        // Also toggle group headers (grouped view)
        const section = row.closest('.group-section');
        if (section) {
            const groupRows = section.querySelectorAll('.site-row');
            const anyVisible = [...groupRows].some(r => r.style.display !== 'none');
            section.style.display = anyVisible ? '' : 'none';
        }
    });

    const counter = document.getElementById('site-count');
    if (counter) counter.textContent = '(' + visible + ')';

    const noResults = document.getElementById('no-results');
    if (noResults) noResults.classList.toggle('hidden', visible > 0);
}

function clearFilters() {
    document.getElementById('filter-search').value = '';
    document.getElementById('filter-server').value = '';
    document.getElementById('filter-stack').value  = '';
    document.getElementById('filter-unassigned').checked = false;
    applyFilters();
}

function toggleClientEdit(id) {
    const el = document.getElementById('client-edit-' + id);
    el.classList.toggle('hidden');
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showToast(msg) {
    let t = document.getElementById('crm-toast');
    if (!t) {
        t = document.createElement('div');
        t.id = 'crm-toast';
        t.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;padding:9px 14px;background:#1e293b;color:#fff;border-radius:6px;font-size:13px;transition:opacity 0.3s;pointer-events:none';
        document.body.appendChild(t);
    }
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._t);
    t._t = setTimeout(() => { t.style.opacity = '0'; }, 2800);
}

function saveClientLink(siteId, select) {
    const clientId = select.value;
    const cell     = document.getElementById('client-cell-' + siteId);
    const editDiv  = document.getElementById('client-edit-' + siteId);
    const row      = cell.closest('tr');
    // Capture the edit button HTML before we mutate anything
    const displayDiv = cell.querySelector('.flex.items-center');
    const editBtn    = displayDiv ? displayDiv.querySelector('button') : null;
    const editBtnHtml = editBtn ? editBtn.outerHTML : '';

    fetch('/sites/' + siteId + '/client', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'client_id=' + encodeURIComponent(clientId),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.ok) return;

        // Rebuild the display (client link + edit button)
        let clientHtml;
        if (clientId && data.client_name) {
            clientHtml = '<a href="/clients/' + escHtml(clientId) + '" class="text-accent-600 hover:underline text-sm">' + escHtml(data.client_name) + '</a>';
        } else {
            clientHtml = '<span class="text-amber-600 text-sm font-medium">Unassigned</span>';
        }
        if (displayDiv) displayDiv.innerHTML = clientHtml + editBtnHtml;

        // Collapse the edit form
        if (editDiv) editDiv.classList.add('hidden');

        // Update row state for filters
        row.dataset.unassigned = clientId ? '0' : '1';
        if (clientId) {
            row.classList.remove('bg-amber-50', 'hover:bg-amber-100');
        } else {
            row.classList.add('bg-amber-50', 'hover:bg-amber-100');
        }

        showToast(clientId ? 'Assigned to ' + (data.client_name || 'client') : 'Client removed');

        // Fade row out of "unassigned only" filter view after a short delay
        const unassignedFilter = document.getElementById('filter-unassigned');
        if (clientId && unassignedFilter && unassignedFilter.checked) {
            setTimeout(() => {
                row.style.transition = 'opacity 0.4s ease';
                row.style.opacity = '0.25';
                setTimeout(() => {
                    row.style.display = 'none';
                    applyFilters();
                }, 400);
            }, 1600);
        }
    })
    .catch(() => alert('Save failed. Please try again.'));
}

// Apply initial filter state from URL params on load
document.addEventListener('DOMContentLoaded', () => {
    const search = <?= json_encode($qSearch) ?>;
    const server = <?= json_encode($qServer) ?>;
    const stack  = <?= json_encode(strtolower($qStack)) ?>;
    if (search || server || stack || <?= $qUnassigned ? 'true' : 'false' ?>) applyFilters();
});
</script>
