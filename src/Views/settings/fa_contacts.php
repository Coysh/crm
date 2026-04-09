<div class="space-y-5">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Contact Mapping</h1>
        <div class="flex gap-2">
            <form method="POST" action="/settings/freeagent/contacts/rematch">
                <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">
                    Re-run Auto-match
                </button>
            </form>
            <form method="POST" action="/settings/freeagent/contacts/create-unmatched"
                  onsubmit="return confirm('Create a new CRM client for every unmatched FreeAgent contact?')">
                <button type="submit" class="px-3 py-1.5 border border-amber-300 bg-amber-50 text-amber-700 rounded text-sm hover:bg-amber-100">
                    Create clients for unmatched
                </button>
            </form>
        </div>
    </div>

    <!-- Stats -->
    <div class="flex gap-4 text-sm text-slate-600">
        <span><strong class="text-slate-800"><?= $stats['total'] ?></strong> total</span>
        <span class="text-green-600"><strong><?= $stats['auto'] ?></strong> auto-matched</span>
        <span class="text-blue-600"><strong><?= $stats['manual'] ?></strong> manual</span>
        <span class="text-amber-600"><strong><?= $stats['unmatched'] ?></strong> unmatched</span>
    </div>

    <?php if (!$contacts): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center text-sm text-slate-400">
            No FreeAgent contacts synced yet.
            <a href="/freeagent" class="text-accent-600 hover:underline ml-1">Run a sync first.</a>
        </div>
    <?php else: ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">FreeAgent Name</th>
                    <th class="px-4 py-2.5 text-left">Email</th>
                    <th class="px-4 py-2.5 text-left">CRM Client</th>
                    <th class="px-4 py-2.5 text-center">Match Type</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($contacts as $contact): ?>
                    <?php $unmatched = !$contact['client_id']; ?>
                    <tr class="<?= $unmatched ? 'bg-amber-50' : 'hover:bg-slate-50' ?>">
                        <td class="px-4 py-2.5 font-medium"><?= freeagentLink($contact['freeagent_url'] ?? null, $contact['name'], 'font-medium') ?></td>
                        <td class="px-4 py-2.5 text-slate-500 text-xs"><?= e($contact['email'] ?: '—') ?></td>
                        <td class="px-4 py-2.5" id="client-label-<?= $contact['id'] ?>">
                            <?= $contact['client_name'] ? e($contact['client_name']) : '<span class="text-slate-300">Unmatched</span>' ?>
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <?php if (!$contact['client_id']): ?>
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700">Unmatched</span>
                            <?php elseif ($contact['auto_matched']): ?>
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Auto</span>
                            <?php else: ?>
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Manual</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex items-center gap-2">
                                <select class="border border-slate-300 rounded px-2 py-1 text-xs focus:outline-none focus:ring-2 focus:ring-accent-500"
                                        id="select-<?= $contact['id'] ?>"
                                        data-contact-id="<?= $contact['id'] ?>"
                                        onchange="saveContactMap(this)">
                                    <option value="">— Unmatched —</option>
                                    <?php foreach ($clients as $c): ?>
                                        <option value="<?= $c['id'] ?>" <?= $contact['client_id'] == $c['id'] ? 'selected' : '' ?>>
                                            <?= e($c['name']) ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                                <?php if (!$contact['client_id']): ?>
                                    <button onclick="createClient(<?= $contact['id'] ?>, this)"
                                            class="px-2 py-1 text-xs border border-slate-300 rounded hover:bg-slate-50 text-slate-600 shrink-0">
                                        + Create Client
                                    </button>
                                <?php endif ?>
                                <span class="save-status-<?= $contact['id'] ?> text-xs text-green-600 hidden">Saved</span>
                            </div>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif ?>

</div>

<script>
function createClient(contactId, btn) {
    btn.disabled = true;
    btn.textContent = '…';

    fetch('/settings/freeagent/contacts/' + contactId + '/create-client', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error);
            btn.disabled = false;
            btn.textContent = '+ Create Client';
            return;
        }
        // Update the row: add new client to dropdown, select it, hide create button
        const select = document.getElementById('select-' + contactId);
        const opt = document.createElement('option');
        opt.value = data.client_id;
        opt.textContent = data.client_name;
        opt.selected = true;
        select.appendChild(opt);

        // Update match badge and client label
        const row = btn.closest('tr');
        row.classList.remove('bg-amber-50');
        row.querySelector('td:nth-child(3)').innerHTML =
            '<a href="/clients/' + data.client_id + '" class="text-accent-600 hover:underline">' +
            data.client_name.replace(/</g, '&lt;') + '</a>';
        row.querySelector('td:nth-child(4)').innerHTML =
            '<span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-700">Manual</span>';
        btn.remove();

        const status = row.querySelector('[class*="save-status"]');
        if (status) { status.textContent = 'Created'; status.classList.remove('hidden'); }
    })
    .catch(() => {
        alert('Failed. Please try again.');
        btn.disabled = false;
        btn.textContent = '+ Create Client';
    });
}

function saveContactMap(select) {
    const id = select.dataset.contactId;
    const clientId = select.value;
    const statusEl = document.querySelector('.save-status-' + id);

    fetch('/settings/freeagent/contacts/' + id + '/map', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: 'client_id=' + encodeURIComponent(clientId),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok && statusEl) {
            statusEl.classList.remove('hidden');
            setTimeout(() => statusEl.classList.add('hidden'), 2000);
        }
    })
    .catch(() => alert('Save failed. Please try again.'));
}
</script>
