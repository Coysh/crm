<?php $isEdit = !empty($project['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Project' : 'Add Project' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/projects/' . $project['id'] : '/projects' ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <?= csrfField() ?>

        <div>
            <label for="client_id" class="block text-sm font-medium text-slate-700 mb-1">Client <span class="text-red-500">*</span></label>
            <select id="client_id" name="client_id" class="w-full border <?= isset($errors['client_id']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">— Select Client —</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($project['client_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
            <?php if (isset($errors['client_id'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['client_id']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Project Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($project['name'] ?? '') ?>"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="income_category" class="block text-sm font-medium text-slate-700 mb-1">Income Category <span class="text-red-500">*</span></label>
            <select id="income_category" name="income_category" class="w-full border <?= isset($errors['income_category']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">— Select —</option>
                <?php foreach ($categories as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($project['income_category'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
            <?php if (isset($errors['income_category'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['income_category']) ?></p>
            <?php endif ?>
        </div>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label for="income_target" class="block text-sm font-medium text-slate-700 mb-1">Income Target (£)</label>
                <input type="number" id="income_target" name="income_target" step="0.01" min="0"
                       value="<?= number_format((float)($project['income_target'] ?? 0), 2, '.', '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <p class="text-xs text-slate-400 mt-1">Total agreed project value</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Amount Invoiced (£)</label>
                <div id="invoiced-display"
                     class="w-full border border-slate-200 bg-slate-50 rounded px-3 py-2 text-sm text-slate-700 tabular-nums">
                    <?= money((float)($project['income_invoiced'] ?? 0)) ?>
                </div>
                <p class="text-xs text-slate-400 mt-1">From linked invoices (net)</p>
            </div>
            <div>
                <label for="income" class="block text-sm font-medium text-slate-700 mb-1">Legacy Income (£)</label>
                <input type="number" id="income" name="income" step="0.01" min="0"
                       value="<?= number_format((float)($project['income'] ?? 0), 2, '.', '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <!-- ── FreeAgent invoices ──────────────────────────────────────────── -->
        <div>
            <div class="flex items-center justify-between mb-1">
                <label class="block text-sm font-medium text-slate-700">FreeAgent Invoices</label>
                <span class="text-xs text-slate-400" id="invoice-picker-summary"></span>
            </div>
            <div id="invoice-picker"
                 class="border border-slate-300 rounded max-h-64 overflow-y-auto divide-y divide-slate-100 text-sm">
                <p class="px-3 py-6 text-center text-slate-400 text-xs">Select a client to see their invoices.</p>
            </div>
            <div class="flex items-center gap-3 mt-1">
                <p class="text-xs text-slate-400 flex-1">Ticked invoices set the Amount Invoiced figure above.</p>
                <button type="button" onclick="invoicePickerAll(true)" class="text-xs text-accent-600 hover:underline">Select all</button>
                <button type="button" onclick="invoicePickerAll(false)" class="text-xs text-slate-400 hover:text-slate-700">Clear</button>
            </div>
        </div>

        <div class="bg-slate-50 rounded-lg p-4 space-y-2" id="progress-card"
             style="<?= (float)($project['income_target'] ?? 0) > 0 ? '' : 'display:none' ?>">
            <div class="flex justify-between text-sm">
                <span class="text-slate-600">Progress</span>
                <span class="font-medium" id="progress-pct">—</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-2.5">
                <div class="h-2.5 rounded-full transition-all bg-green-500" id="progress-bar" style="width:0%"></div>
            </div>
            <div class="grid grid-cols-3 gap-4 text-xs text-slate-500 mt-1">
                <div>Target: <span class="font-medium text-slate-700" id="progress-target">—</span></div>
                <div>Invoiced: <span class="font-medium text-slate-700" id="progress-invoiced">—</span></div>
                <div>Remaining: <span class="font-medium text-slate-700" id="progress-remaining">—</span></div>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="start_date" class="block text-sm font-medium text-slate-700 mb-1">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?= e($project['start_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-slate-700 mb-1">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?= e($project['end_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select id="status" name="status" class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php foreach ($statuses as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($project['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <input type="hidden" id="notes" name="notes" value="<?= e($project['notes'] ?? '') ?>">
            <div id="notes-editor"></div>
            <style>
                #notes-editor .ql-toolbar{border-color:#cbd5e1;border-radius:.25rem .25rem 0 0;background:#f8fafc}
                #notes-editor .ql-container{border-color:#cbd5e1;border-radius:0 0 .25rem .25rem;font-size:.875rem;min-height:100px}
                #notes-editor .ql-editor{min-height:100px}
                #notes-editor .ql-editor:focus{box-shadow:0 0 0 2px rgba(99,102,241,.3)}
            </style>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Create Project' ?>
            </button>
            <a href="/projects" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>

<script>
// ── FreeAgent invoice picker ─────────────────────────────────────────────────
window.__invoiceOptions = <?= json_encode(array_map(fn(array $r) => [
    'id'                 => (int)$r['id'],
    'reference'          => $r['reference'] ?: '—',
    'dated_label'        => formatDate($r['dated_on']),
    'net_value'          => (float)$r['net_value'],
    'status'             => $r['eff_status'],
    'linked_here'        => (bool)$r['linked_here'],
    'other_project_id'   => $r['other_project_id'] ? (int)$r['other_project_id'] : null,
    'other_project_name' => $r['other_project_name'],
], $invoiceOptions ?? []), JSON_UNESCAPED_SLASHES) ?>;
window.__linkedInvoiceIds = <?= json_encode(array_map('intval', $linkedInvoiceIds ?? [])) ?>;
window.__projectId = <?= json_encode($isEdit ? (int)$project['id'] : null) ?>;

(function() {
    const box = document.getElementById('invoice-picker');

    function gbp(n) {
        return '£' + (n || 0).toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function statusBadge(s) {
        const cls = s === 'paid'    ? 'bg-green-100 text-green-700'
                  : s === 'overdue' ? 'bg-red-100 text-red-700'
                  : s === 'sent'    ? 'bg-blue-100 text-blue-700'
                  :                   'bg-slate-100 text-slate-600';
        return '<span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium ' + cls + '">'
             + esc(s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Unknown') + '</span>';
    }

    window.renderInvoicePicker = function(invoices, checkedIds) {
        const checked = new Set((checkedIds || []).map(Number));

        if (!invoices.length) {
            box.innerHTML = '<p class="px-3 py-6 text-center text-slate-400 text-xs">'
                + 'No FreeAgent invoices found for this client.</p>';
            updateInvoiceTotals();
            return;
        }

        box.innerHTML = invoices.map(inv => {
            const isChecked = checked.has(Number(inv.id)) || inv.linked_here;
            const elsewhere = inv.other_project_id
                ? '<span class="ml-1 text-xs text-amber-600" title="Also linked to another project">· also on '
                  + esc(inv.other_project_name) + '</span>'
                : '';
            return ''
              + '<label class="flex items-center gap-3 px-3 py-2 hover:bg-slate-50 cursor-pointer">'
              +   '<input type="checkbox" name="invoice_ids[]" value="' + inv.id + '"'
              +          (isChecked ? ' checked' : '')
              +          ' class="invoice-cb rounded border-slate-300 text-accent-600 focus:ring-accent-500">'
              +   '<span class="font-mono text-xs text-slate-700 w-24 shrink-0 truncate">' + esc(inv.reference) + '</span>'
              +   '<span class="text-xs text-slate-400 w-20 shrink-0">' + esc(inv.dated_label) + '</span>'
              +   '<span class="shrink-0">' + statusBadge(inv.status) + '</span>'
              +   '<span class="flex-1 text-right tabular-nums text-slate-700 font-medium"'
              +         ' data-net="' + inv.net_value + '">' + gbp(inv.net_value) + '</span>'
              +   elsewhere
              + '</label>';
        }).join('');

        box.querySelectorAll('.invoice-cb').forEach(cb =>
            cb.addEventListener('change', updateInvoiceTotals));
        updateInvoiceTotals();
    };

    window.updateInvoiceTotals = function() {
        let total = 0, count = 0;
        box.querySelectorAll('.invoice-cb').forEach(cb => {
            if (!cb.checked) return;
            count++;
            total += parseFloat(cb.closest('label').querySelector('[data-net]').dataset.net) || 0;
        });

        document.getElementById('invoiced-display').textContent = gbp(total);
        document.getElementById('invoice-picker-summary').textContent =
            count ? count + ' linked · ' + gbp(total) : 'None linked';

        // Progress card
        const target = parseFloat(document.getElementById('income_target').value) || 0;
        const card   = document.getElementById('progress-card');
        if (target > 0) {
            card.style.display = '';
            const pct  = Math.min(Math.round((total / target) * 100), 999);
            const bar  = document.getElementById('progress-bar');
            bar.style.width = Math.min(pct, 100) + '%';
            bar.className = 'h-2.5 rounded-full transition-all ' + (pct > 100 ? 'bg-amber-500' : 'bg-green-500');
            const pctEl = document.getElementById('progress-pct');
            pctEl.textContent = pct + '%';
            pctEl.className = 'font-medium ' + (pct > 100 ? 'text-amber-600' : 'text-slate-800');
            document.getElementById('progress-target').textContent    = gbp(target);
            document.getElementById('progress-invoiced').textContent  = gbp(total);
            const rem = target - total;
            const remEl = document.getElementById('progress-remaining');
            remEl.textContent = gbp(rem);
            remEl.className = 'font-medium ' + (rem < 0 ? 'text-red-600' : 'text-slate-700');
        } else {
            card.style.display = 'none';
        }
    };

    window.invoicePickerAll = function(checked) {
        box.querySelectorAll('.invoice-cb').forEach(cb => { cb.checked = checked; });
        updateInvoiceTotals();
    };

    function loadForClient(clientId) {
        if (!clientId) {
            box.innerHTML = '<p class="px-3 py-6 text-center text-slate-400 text-xs">Select a client to see their invoices.</p>';
            updateInvoiceTotals();
            return;
        }
        box.innerHTML = '<p class="px-3 py-6 text-center text-slate-400 text-xs">Loading invoices…</p>';

        const qs = new URLSearchParams({ client_id: clientId });
        if (window.__projectId) qs.set('project_id', window.__projectId);

        fetch('/projects/invoice-options?' + qs.toString())
            .then(r => r.json())
            .then(data => renderInvoicePicker(data.invoices || [], []))
            .catch(() => {
                box.innerHTML = '<p class="px-3 py-6 text-center text-red-500 text-xs">Failed to load invoices.</p>';
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const sel = document.getElementById('client_id');

        if (window.__invoiceOptions.length) {
            renderInvoicePicker(window.__invoiceOptions, window.__linkedInvoiceIds);
        } else if (sel.value) {
            loadForClient(sel.value);
        } else {
            updateInvoiceTotals();
        }

        sel.addEventListener('change', () => loadForClient(sel.value));
        document.getElementById('income_target').addEventListener('input', updateInvoiceTotals);
    });
})();

document.addEventListener('DOMContentLoaded', function() {
    var quill = new Quill('#notes-editor', {
        theme: 'snow',
        placeholder: 'Add notes...',
        modules: {
            toolbar: [
                ['bold', 'italic', 'underline', 'strike'],
                [{ 'header': [2, 3, false] }],
                [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                ['link'],
                ['clean']
            ]
        }
    });

    var notesInput = document.getElementById('notes');
    var existing = notesInput.value;
    if (existing) {
        quill.root.innerHTML = existing;
    }

    document.querySelector('form').addEventListener('submit', function() {
        var html = quill.root.innerHTML;
        notesInput.value = (html === '<p><br></p>') ? '' : html;
    });
});
</script>
