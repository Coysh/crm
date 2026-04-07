<div class="max-w-lg space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">Merge Client</h1>

    <div class="bg-amber-50 border border-amber-200 rounded-lg p-4 text-sm text-amber-800">
        <strong>Warning:</strong> This will move all domains, sites, packages, projects, expenses, and FreeAgent data
        from <strong><?= e($client['name']) ?></strong> into the selected client, then permanently delete
        <strong><?= e($client['name']) ?></strong>. This cannot be undone.
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
        <p class="text-sm text-slate-600">
            Select the client to merge <strong><?= e($client['name']) ?></strong> into.
            The selected client will be kept; <strong><?= e($client['name']) ?></strong> will be deleted.
        </p>

        <form method="POST" action="/clients/<?= $client['id'] ?>/merge"
              onsubmit="return confirm('Merge ' + document.getElementById('target-name').textContent + ' into the selected client? This cannot be undone.')">

            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-slate-600 mb-1">Keep this client:</label>
                    <select name="target_id" id="target-select" required
                            class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"
                            onchange="updateTargetName(this)">
                        <option value="">— Select a client —</option>
                        <?php foreach ($targets as $t): ?>
                            <option value="<?= $t['id'] ?>" data-name="<?= e($t['name']) ?>">
                                <?= e($t['name']) ?>
                                <?php if ($t['status'] === 'archived'): ?>(archived)<?php endif ?>
                            </option>
                        <?php endforeach ?>
                    </select>
                </div>

                <p class="text-xs text-slate-400">
                    Merging <strong><?= e($client['name']) ?></strong>
                    → <strong id="target-name" class="text-slate-600">…</strong>
                </p>
            </div>

            <div class="flex gap-3 mt-5">
                <button type="submit"
                        class="px-4 py-2 bg-red-600 text-white text-sm font-medium rounded hover:bg-red-700">
                    Merge &amp; Delete <?= e($client['name']) ?>
                </button>
                <a href="/clients/<?= $client['id'] ?>"
                   class="px-4 py-2 border border-slate-300 rounded text-sm hover:bg-slate-50">
                    Cancel
                </a>
            </div>

        </form>
    </div>

</div>

<script>
function updateTargetName(select) {
    const opt = select.options[select.selectedIndex];
    document.getElementById('target-name').textContent = opt.value ? opt.dataset.name : '…';
}
</script>
