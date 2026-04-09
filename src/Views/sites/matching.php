<div class="space-y-5">

    <!-- Header -->
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Site Auto-Matching</h1>
        <a href="/sites/matching" class="px-3 py-1.5 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
            Re-run matching
        </a>
    </div>

    <!-- Stats bar -->
    <div class="flex flex-wrap items-center gap-5 text-sm text-slate-600">
        <span><strong class="text-slate-800"><?= $stats['total'] ?></strong> unassigned site<?= $stats['total'] !== 1 ? 's' : '' ?></span>
        <span class="text-green-700"><strong><?= $stats['withSuggestions'] ?></strong> with suggestions</span>
        <span class="text-slate-400"><strong><?= $stats['noSuggestions'] ?></strong> no suggestions</span>
    </div>

    <?php if (empty($matches)): ?>
        <div class="bg-white border border-slate-200 rounded-lg px-6 py-10 text-center text-slate-400">
            All sites are already assigned to clients.
        </div>
    <?php else: ?>

    <!-- Bulk action bar -->
    <div class="flex items-center gap-3 bg-white border border-slate-200 rounded-lg px-4 py-3">
        <button onclick="acceptAllHighConfidence()"
                class="px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            Accept all ≥70% confidence
        </button>
        <span class="text-xs text-slate-400" id="bulk-status"></span>
    </div>

    <!-- Matching table -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm" id="matching-table">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Site Domain</th>
                    <th class="px-4 py-2.5 text-left">Server</th>
                    <th class="px-4 py-2.5 text-left">Best Match</th>
                    <th class="px-4 py-2.5 text-left">Confidence</th>
                    <th class="px-4 py-2.5 text-left">Other Suggestions</th>
                    <th class="px-4 py-2.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($matches as $siteId => $match):
                    $site        = $match['site'];
                    $suggestions = $match['suggestions'];
                    $best        = $suggestions[0] ?? null;
                    $others      = array_slice($suggestions, 1);
                    $confidence  = $best ? $best['score'] : 0;
                    $confClass   = $confidence >= 70 ? 'bg-green-100 text-green-700'
                                 : ($confidence >= 40  ? 'bg-amber-100 text-amber-700'
                                 : 'bg-red-100 text-red-700');
                ?>
                <tr class="match-row hover:bg-slate-50"
                    id="match-row-<?= $siteId ?>"
                    data-site-id="<?= $siteId ?>"
                    data-confidence="<?= $confidence ?>"
                    data-best-client-id="<?= $best ? $best['client']['id'] : '' ?>"
                    data-best-client-name="<?= $best ? e($best['client']['name']) : '' ?>">

                    <!-- Domain -->
                    <td class="px-4 py-3 font-mono text-xs text-slate-700">
                        <?= e($site['domain_raw'] ?: '—') ?>
                    </td>

                    <!-- Server -->
                    <td class="px-4 py-3 text-slate-500">
                        <?= e($site['server_name'] ?: '—') ?>
                    </td>

                    <!-- Best match -->
                    <td class="px-4 py-3">
                        <?php if ($best): ?>
                            <span class="font-medium text-slate-800"><?= e($best['client']['name']) ?></span>
                        <?php else: ?>
                            <span class="text-slate-300 italic text-xs">No suggestions</span>
                        <?php endif ?>
                    </td>

                    <!-- Confidence -->
                    <td class="px-4 py-3">
                        <?php if ($best): ?>
                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold <?= $confClass ?>">
                                <?= $confidence ?>%
                            </span>
                        <?php else: ?>
                            <span class="text-slate-300">—</span>
                        <?php endif ?>
                    </td>

                    <!-- Other suggestions -->
                    <td class="px-4 py-3 text-xs text-slate-400 space-x-2">
                        <?php foreach ($others as $other):
                            $oc = $other['score'] >= 70 ? 'text-green-600' : ($other['score'] >= 40 ? 'text-amber-600' : 'text-slate-400');
                        ?>
                            <button onclick="acceptMatch(<?= $siteId ?>, <?= $other['client']['id'] ?>, <?= htmlspecialchars(json_encode($other['client']['name']), ENT_QUOTES) ?>)"
                                    class="<?= $oc ?> hover:text-slate-800 hover:underline">
                                <?= e($other['client']['name']) ?> <span class="opacity-70">(<?= $other['score'] ?>%)</span>
                            </button>
                        <?php endforeach ?>
                    </td>

                    <!-- Actions -->
                    <td class="px-4 py-3 text-right" id="match-actions-<?= $siteId ?>">
                        <div class="flex items-center justify-end gap-1.5 flex-wrap">
                            <?php if ($best): ?>
                                <button onclick="acceptMatch(<?= $siteId ?>, <?= $best['client']['id'] ?>, <?= htmlspecialchars(json_encode($best['client']['name']), ENT_QUOTES) ?>)"
                                        class="px-2.5 py-1 text-xs bg-accent-600 text-white rounded hover:bg-accent-700">
                                    Accept
                                </button>
                            <?php endif ?>
                            <button onclick="togglePickOther(<?= $siteId ?>)"
                                    class="px-2.5 py-1 text-xs border border-slate-300 rounded hover:bg-slate-50 text-slate-600">
                                Pick other…
                            </button>
                            <button onclick="skipRow(<?= $siteId ?>)"
                                    class="px-2.5 py-1 text-xs text-slate-400 hover:text-slate-600">
                                Skip
                            </button>
                        </div>
                        <!-- Pick other dropdown (hidden by default) -->
                        <div id="pick-other-<?= $siteId ?>" class="hidden mt-2 flex items-center gap-1 justify-end">
                            <select id="pick-select-<?= $siteId ?>"
                                    class="border border-slate-300 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-accent-500">
                                <option value="">— Choose client —</option>
                                <?php foreach ($allClients as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
                                <?php endforeach ?>
                            </select>
                            <button onclick="acceptFromSelect(<?= $siteId ?>)"
                                    class="px-2 py-1 text-xs bg-accent-600 text-white rounded hover:bg-accent-700">
                                Assign
                            </button>
                            <button onclick="togglePickOther(<?= $siteId ?>)"
                                    class="px-2 py-1 text-xs text-slate-400 hover:text-slate-600">✕</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach ?>
                <?php if (empty($matches)): ?>
                    <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No unassigned sites.</td></tr>
                <?php endif ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php endif ?>

</div>

<div id="match-toast" style="position:fixed;bottom:20px;right:20px;z-index:9999;padding:9px 14px;background:#1e293b;color:#fff;border-radius:6px;font-size:13px;transition:opacity 0.3s;pointer-events:none;opacity:0"></div>

<script>
function showMatchToast(msg) {
    const t = document.getElementById('match-toast');
    t.textContent = msg;
    t.style.opacity = '1';
    clearTimeout(t._t);
    t._t = setTimeout(() => { t.style.opacity = '0'; }, 2800);
}

function markRowAccepted(siteId, clientName) {
    const row = document.getElementById('match-row-' + siteId);
    if (!row) return;
    row.dataset.accepted = '1';
    // Fade to "Matched" state
    const actionsCell = document.getElementById('match-actions-' + siteId);
    if (actionsCell) {
        actionsCell.innerHTML = '<span class="text-green-600 text-xs font-medium">✓ Matched to ' + escH(clientName) + '</span>';
    }
    row.style.transition = 'opacity 0.5s ease';
    setTimeout(() => {
        row.style.opacity = '0.35';
        row.classList.add('bg-slate-50');
    }, 600);
}

function acceptMatch(siteId, clientId, clientName) {
    fetch('/sites/' + siteId + '/client', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'client_id=' + encodeURIComponent(clientId),
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            markRowAccepted(siteId, clientName);
            showMatchToast('Assigned: ' + clientName);
        }
    })
    .catch(() => alert('Assignment failed. Please try again.'));
}

function acceptFromSelect(siteId) {
    const sel = document.getElementById('pick-select-' + siteId);
    if (!sel || !sel.value) return;
    const clientName = sel.options[sel.selectedIndex].text;
    acceptMatch(siteId, sel.value, clientName);
}

function togglePickOther(siteId) {
    const el = document.getElementById('pick-other-' + siteId);
    if (el) el.classList.toggle('hidden');
}

function skipRow(siteId) {
    const row = document.getElementById('match-row-' + siteId);
    if (row) {
        row.style.transition = 'opacity 0.2s';
        row.style.opacity = '0';
        setTimeout(() => { row.style.display = 'none'; }, 200);
    }
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function acceptAllHighConfidence() {
    const rows = [...document.querySelectorAll('.match-row[data-confidence]')]
        .filter(r => !r.dataset.accepted && parseInt(r.dataset.confidence) >= 70 && r.dataset.bestClientId);

    if (rows.length === 0) {
        alert('No high-confidence (≥70%) unaccepted matches found.');
        return;
    }
    if (!confirm('Accept ' + rows.length + ' high-confidence match' + (rows.length !== 1 ? 'es' : '') + '?')) return;

    const status = document.getElementById('bulk-status');
    if (status) status.textContent = 'Accepting ' + rows.length + ' matches…';

    let done = 0;
    rows.forEach(row => {
        const siteId     = row.dataset.siteId;
        const clientId   = row.dataset.bestClientId;
        const clientName = row.dataset.bestClientName;
        fetch('/sites/' + siteId + '/client', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'client_id=' + encodeURIComponent(clientId),
        })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                markRowAccepted(siteId, clientName);
                done++;
                if (status) status.textContent = done + ' of ' + rows.length + ' accepted';
                if (done === rows.length) {
                    showMatchToast(done + ' sites matched!');
                }
            }
        })
        .catch(() => {});
    });
}
</script>
