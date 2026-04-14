<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Projects</h1>
        <div class="flex items-center gap-3">
            <a href="/projects/create" class="text-xs text-slate-400 hover:text-slate-700">Full form</a>
            <button onclick="document.getElementById('quick-create-modal').showModal()"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                + Add Project
            </button>
        </div>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label for="filter-client" class="block text-xs text-slate-500 mb-1">Client</label>
            <select id="filter-client" name="client_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="filter-status" class="block text-xs text-slate-500 mb-1">Status</label>
            <select id="filter-status" name="status" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">Filter</button>
        <?php if ($clientId || $status): ?>
            <a href="/projects" class="text-xs text-slate-400 hover:text-slate-700 self-center">Clear</a>
        <?php endif ?>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Project</th>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-left">Category</th>
                        <th class="px-4 py-2.5 text-right">Target</th>
                        <th class="px-4 py-2.5 text-right">Invoiced</th>
                        <th class="px-4 py-2.5 text-right">Remaining</th>
                        <th class="px-4 py-2.5 text-center" style="min-width:100px">Progress</th>
                        <th class="px-4 py-2.5 text-left">Dates</th>
                        <th class="px-4 py-2.5 text-center">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody id="projects-tbody" class="divide-y divide-slate-100">
                    <!-- Quick-add row -->
                    <tr id="quick-add-row" class="bg-accent-50/30 border-b border-accent-200">
                        <td class="px-4 py-2">
                            <input type="text" id="qa-name" placeholder="Project name..."
                                   class="w-full border border-slate-300 rounded px-2 py-1 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"
                                   onkeydown="if(event.key==='Enter'){event.preventDefault();quickAddProject()}">
                        </td>
                        <td class="px-4 py-2">
                            <select id="qa-client" class="w-full border border-slate-300 rounded px-2 py-1 text-sm">
                                <option value="">Client</option>
                                <?php foreach ($clients as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                        </td>
                        <td class="px-4 py-2">
                            <select id="qa-category" class="w-full border border-slate-300 rounded px-2 py-1 text-sm">
                                <option value="">Category</option>
                                <?php foreach ($categories as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= $label ?></option>
                                <?php endforeach ?>
                            </select>
                        </td>
                        <td colspan="5"></td>
                        <td class="px-4 py-2 text-center">
                            <select id="qa-status" class="border border-slate-300 rounded px-2 py-1 text-xs">
                                <option value="active">Active</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </td>
                        <td class="px-4 py-2 text-right">
                            <button onclick="quickAddProject()" class="px-3 py-1 bg-accent-600 text-white text-xs rounded hover:bg-accent-700">Add</button>
                        </td>
                    </tr>

                    <?php foreach ($projects as $p):
                        $pTarget   = (float)($p['income_target'] ?? 0);
                        $pInvoiced = (float)($p['income_invoiced'] ?? 0);
                        $pRemaining = $pTarget - $pInvoiced;
                        $pPct       = $pTarget > 0 ? min(round(($pInvoiced / $pTarget) * 100), 999) : 0;
                        $pBarPct    = min($pPct, 100);
                        $pBarColor  = $pPct > 100 ? 'bg-amber-500' : 'bg-green-500';
                    ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium"><?= e($p['name']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <a href="/clients/<?= $p['client_id'] ?>" class="hover:text-accent-600"><?= e($p['client_name']) ?></a>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($categories[$p['income_category']] ?? $p['income_category']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= $pTarget > 0 ? money($pTarget) : '<span class="text-slate-300">—</span>' ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= $pInvoiced > 0 ? money($pInvoiced) : '<span class="text-slate-300">—</span>' ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums <?= $pRemaining < 0 ? 'text-red-600' : '' ?>">
                                <?= $pTarget > 0 ? money($pRemaining) : '<span class="text-slate-300">—</span>' ?>
                            </td>
                            <td class="px-4 py-2.5">
                                <?php if ($pTarget > 0): ?>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-slate-200 rounded-full h-1.5">
                                        <div class="<?= $pBarColor ?> h-1.5 rounded-full" style="width: <?= $pBarPct ?>%"></div>
                                    </div>
                                    <span class="text-xs tabular-nums <?= $pPct > 100 ? 'text-amber-600 font-medium' : 'text-slate-500' ?>"><?= $pPct ?>%</span>
                                </div>
                                <?php else: ?>
                                <span class="text-slate-300 text-xs">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500 text-xs">
                                <?= formatDate($p['start_date']) ?>
                                <?php if ($p['end_date']): ?> → <?= formatDate($p['end_date']) ?><?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <button onclick="cycleStatus(<?= $p['id'] ?>, this)"
                                        data-status="<?= $p['status'] ?>"
                                        class="inline-block px-2 py-0.5 rounded-full text-xs font-medium cursor-pointer hover:ring-2 hover:ring-accent-200 transition-shadow <?= statusBadge($p['status']) ?>">
                                    <?= ucfirst($p['status']) ?>
                                </button>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="/projects/<?= $p['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/projects/<?= $p['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this project?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($projects)): ?>
                        <tr id="empty-row"><td colspan="10" class="px-4 py-8 text-center text-slate-400">No projects found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Quick-create modal -->
<dialog id="quick-create-modal" class="rounded-lg shadow-xl border border-slate-200 p-0 w-full max-w-lg backdrop:bg-slate-900/40">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-800">New Project</h2>
            <button onclick="document.getElementById('quick-create-modal').close()" class="text-slate-400 hover:text-slate-600">&times;</button>
        </div>

        <div class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Project Name <span class="text-red-500">*</span></label>
                <input type="text" id="qc-name" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Client <span class="text-red-500">*</span></label>
                    <select id="qc-client" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                        <option value="">— Select —</option>
                        <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Category <span class="text-red-500">*</span></label>
                    <select id="qc-category" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                        <option value="">— Select —</option>
                        <?php foreach ($categories as $val => $label): ?>
                            <option value="<?= $val ?>"><?= $label ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Income Target (£)</label>
                    <input type="number" id="qc-target" step="0.01" min="0" value="0"
                           class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                    <select id="qc-status" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
                <div id="qc-notes-editor"></div>
                <style>
                    #qc-notes-editor .ql-toolbar{border-color:#cbd5e1;border-radius:.25rem .25rem 0 0;background:#f8fafc}
                    #qc-notes-editor .ql-container{border-color:#cbd5e1;border-radius:0 0 .25rem .25rem;font-size:.875rem;min-height:80px}
                    #qc-notes-editor .ql-editor{min-height:80px}
                </style>
            </div>
        </div>

        <div id="qc-error" class="text-sm text-red-600 hidden"></div>

        <div class="flex items-center gap-3 pt-2">
            <button onclick="modalCreateProject()" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">Create Project</button>
            <button onclick="document.getElementById('quick-create-modal').close()" class="text-sm text-slate-500 hover:text-slate-800">Cancel</button>
        </div>
    </div>
</dialog>

<!-- Toast notification -->
<div id="toast" class="fixed bottom-4 right-4 bg-green-600 text-white px-4 py-2 rounded shadow-lg text-sm transition-opacity duration-300 opacity-0 pointer-events-none" style="z-index:50"></div>

<script>
var statusClasses = {
    active: 'bg-green-100 text-green-800',
    completed: 'bg-blue-100 text-blue-800',
    cancelled: 'bg-red-100 text-red-800'
};
var statusOrder = ['active', 'completed', 'cancelled'];
var categoryLabels = <?= json_encode($categories) ?>;

function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.remove('opacity-0');
    t.classList.add('opacity-100');
    setTimeout(function(){ t.classList.remove('opacity-100'); t.classList.add('opacity-0'); }, 2500);
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// ── Status toggle ───────────────────────────────────────────────────────
function cycleStatus(id, btn) {
    var current = btn.getAttribute('data-status');
    var idx = statusOrder.indexOf(current);
    var next = statusOrder[(idx + 1) % statusOrder.length];

    fetch('/projects/' + id + '/status', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: 'status=' + encodeURIComponent(next)
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.ok) {
            btn.setAttribute('data-status', data.status);
            btn.textContent = data.status.charAt(0).toUpperCase() + data.status.slice(1);
            btn.className = 'inline-block px-2 py-0.5 rounded-full text-xs font-medium cursor-pointer hover:ring-2 hover:ring-accent-200 transition-shadow ' + statusClasses[data.status];
            showToast('Status updated');
        }
    })
    .catch(function(){ alert('Failed to update status.'); });
}

// ── Quick-add row ───────────────────────────────────────────────────────
function quickAddProject() {
    var name = document.getElementById('qa-name').value.trim();
    var client = document.getElementById('qa-client').value;
    var category = document.getElementById('qa-category').value;
    var status = document.getElementById('qa-status').value;

    if (!name || !client || !category) {
        showToast('Name, client, and category are required');
        return;
    }

    var body = 'name=' + encodeURIComponent(name)
             + '&client_id=' + encodeURIComponent(client)
             + '&income_category=' + encodeURIComponent(category)
             + '&status=' + encodeURIComponent(status)
             + '&income_target=0&income_invoiced=0&income=0&notes=';

    fetch('/projects/quick-create', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.error) { showToast(data.error); return; }
        insertProjectRow(data);
        document.getElementById('qa-name').value = '';
        document.getElementById('qa-client').value = '';
        document.getElementById('qa-category').value = '';
        document.getElementById('qa-status').value = 'active';
        showToast('Project created');
        var empty = document.getElementById('empty-row');
        if (empty) empty.remove();
    })
    .catch(function(){ alert('Failed to create project.'); });
}

function insertProjectRow(data) {
    var tbody = document.getElementById('projects-tbody');
    var quickRow = document.getElementById('quick-add-row');
    var tr = document.createElement('tr');
    tr.className = 'hover:bg-slate-50';
    tr.innerHTML =
        '<td class="px-4 py-2.5 font-medium">' + escHtml(data.name) + '</td>' +
        '<td class="px-4 py-2.5 text-slate-500"><a href="/clients/' + data.client_id + '" class="hover:text-accent-600">' + escHtml(data.client_name) + '</a></td>' +
        '<td class="px-4 py-2.5 text-slate-500">' + escHtml(categoryLabels[data.income_category] || data.income_category) + '</td>' +
        '<td class="px-4 py-2.5 text-right tabular-nums"><span class="text-slate-300">\u2014</span></td>' +
        '<td class="px-4 py-2.5 text-right tabular-nums"><span class="text-slate-300">\u2014</span></td>' +
        '<td class="px-4 py-2.5 text-right tabular-nums"><span class="text-slate-300">\u2014</span></td>' +
        '<td class="px-4 py-2.5"><span class="text-slate-300 text-xs">\u2014</span></td>' +
        '<td class="px-4 py-2.5 text-slate-500 text-xs"></td>' +
        '<td class="px-4 py-2.5 text-center"><button onclick="cycleStatus(' + data.id + ', this)" data-status="' + data.status + '" class="inline-block px-2 py-0.5 rounded-full text-xs font-medium cursor-pointer hover:ring-2 hover:ring-accent-200 transition-shadow ' + statusClasses[data.status] + '">' + data.status.charAt(0).toUpperCase() + data.status.slice(1) + '</button></td>' +
        '<td class="px-4 py-2.5 text-right"><a href="/projects/' + data.id + '/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a><form method="POST" action="/projects/' + data.id + '/delete" class="inline"><button type="submit" onclick="return confirm(\'Delete this project?\')" class="text-xs text-red-400 hover:text-red-600">Delete</button></form></td>';
    quickRow.insertAdjacentElement('afterend', tr);
}

// ── Quick-create modal ──────────────────────────────────────────────────
var qcQuill = null;
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Quill !== 'undefined') {
        qcQuill = new Quill('#qc-notes-editor', {
            theme: 'snow',
            placeholder: 'Add notes...',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['link'],
                    ['clean']
                ]
            }
        });
    }
});

function modalCreateProject() {
    var name = document.getElementById('qc-name').value.trim();
    var client = document.getElementById('qc-client').value;
    var category = document.getElementById('qc-category').value;
    var target = document.getElementById('qc-target').value || '0';
    var status = document.getElementById('qc-status').value;
    var notes = '';
    if (qcQuill) {
        var html = qcQuill.root.innerHTML;
        notes = (html === '<p><br></p>') ? '' : html;
    }
    var errEl = document.getElementById('qc-error');

    if (!name || !client || !category) {
        errEl.textContent = 'Name, client, and category are required.';
        errEl.classList.remove('hidden');
        return;
    }
    errEl.classList.add('hidden');

    var body = 'name=' + encodeURIComponent(name)
             + '&client_id=' + encodeURIComponent(client)
             + '&income_category=' + encodeURIComponent(category)
             + '&income_target=' + encodeURIComponent(target)
             + '&income_invoiced=0&income=0'
             + '&status=' + encodeURIComponent(status)
             + '&notes=' + encodeURIComponent(notes);

    fetch('/projects/quick-create', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: body
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.error) { errEl.textContent = data.error; errEl.classList.remove('hidden'); return; }
        insertProjectRow(data);
        document.getElementById('quick-create-modal').close();
        // Reset modal fields
        document.getElementById('qc-name').value = '';
        document.getElementById('qc-client').value = '';
        document.getElementById('qc-category').value = '';
        document.getElementById('qc-target').value = '0';
        document.getElementById('qc-status').value = 'active';
        if (qcQuill) qcQuill.setText('');
        showToast('Project created');
        var empty = document.getElementById('empty-row');
        if (empty) empty.remove();
    })
    .catch(function(){ alert('Failed to create project.'); });
}
</script>
