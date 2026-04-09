<div class="space-y-5">

    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-800">DNS Records</h1>
            <p class="text-sm text-slate-500 mt-0.5 font-mono"><?= e($domain['domain']) ?></p>
        </div>
        <a href="/domains/<?= $domain['id'] ?>" class="text-sm text-slate-500 hover:text-slate-800">← Back to Domain</a>
    </div>

    <!-- Warning -->
    <div class="bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 text-sm text-amber-800">
        <strong>Warning:</strong> Changes are applied to Cloudflare immediately and may affect live websites. No DNS records can be deleted from this interface.
    </div>

    <?php if (!$zone): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-6 text-center text-sm text-slate-400">
            No Cloudflare zone is linked to this domain. <a href="/settings/cloudflare" class="text-accent-600 hover:underline">Link a zone →</a>
        </div>
    <?php else: ?>

    <!-- DNS Records Table -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Records</h2>
            <span class="text-xs text-slate-400"><?= count($dnsRecords) ?> record(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Type</th>
                        <th class="px-4 py-2.5 text-left">Name</th>
                        <th class="px-4 py-2.5 text-left">Content</th>
                        <th class="px-4 py-2.5 text-center">TTL</th>
                        <th class="px-4 py-2.5 text-center">Proxied</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($dnsRecords as $rec): ?>
                        <tr class="hover:bg-slate-50" id="rec-row-<?= e($rec['record_id']) ?>">
                            <td class="px-4 py-2.5">
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600 font-mono"><?= e($rec['type']) ?></span>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-700 max-w-[200px] truncate"><?= e($rec['name']) ?></td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-500 max-w-[250px] truncate" title="<?= e($rec['content']) ?>"><?= e($rec['content']) ?></td>
                            <td class="px-4 py-2.5 text-center text-xs tabular-nums text-slate-500">
                                <?= $rec['ttl'] == 1 ? 'Auto' : (int)$rec['ttl'] ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <?= $rec['proxied'] ? '<span class="inline-block w-2 h-2 rounded-full bg-orange-400" title="Proxied"></span>' : '<span class="inline-block w-2 h-2 rounded-full bg-slate-200" title="DNS only"></span>' ?>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <button onclick="openEditForm('<?= e(addslashes($rec['record_id'])) ?>')"
                                        class="text-xs text-slate-400 hover:text-accent-600">Edit</button>
                            </td>
                        </tr>
                        <!-- Edit row (hidden) -->
                        <tr id="rec-edit-<?= e($rec['record_id']) ?>" style="display:none" class="bg-slate-50">
                            <td colspan="6" class="px-4 py-3">
                                <form method="POST" action="/domains/<?= $domain['id'] ?>/dns/<?= urlencode($rec['record_id']) ?>" class="flex flex-wrap gap-2 items-end text-sm">
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-0.5">Type</label>
                                        <select name="type" class="border border-slate-300 rounded px-2 py-1 text-xs bg-white">
                                            <?php foreach (['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'] as $t): ?>
                                                <option value="<?= $t ?>" <?= $rec['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                                            <?php endforeach ?>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-0.5">Name</label>
                                        <input type="text" name="name" value="<?= e($rec['name']) ?>" class="border border-slate-300 rounded px-2 py-1 text-xs font-mono w-40">
                                    </div>
                                    <div class="flex-1 min-w-[180px]">
                                        <label class="block text-xs text-slate-500 mb-0.5">Content</label>
                                        <input type="text" name="content" value="<?= e($rec['content']) ?>" class="border border-slate-300 rounded px-2 py-1 text-xs font-mono w-full">
                                    </div>
                                    <div>
                                        <label class="block text-xs text-slate-500 mb-0.5">TTL</label>
                                        <input type="number" name="ttl" value="<?= (int)$rec['ttl'] ?>" min="1" class="border border-slate-300 rounded px-2 py-1 text-xs w-16">
                                    </div>
                                    <div class="flex items-end gap-1.5 pb-0.5">
                                        <input type="checkbox" name="proxied" value="1" <?= $rec['proxied'] ? 'checked' : '' ?> id="proxied-<?= e($rec['record_id']) ?>">
                                        <label for="proxied-<?= e($rec['record_id']) ?>" class="text-xs text-slate-600">Proxied</label>
                                    </div>
                                    <div class="flex gap-2 items-end">
                                        <button type="submit" class="px-3 py-1 bg-accent-600 text-white text-xs rounded hover:bg-accent-700">Save</button>
                                        <button type="button" onclick="closeEditForm('<?= e(addslashes($rec['record_id'])) ?>')" class="px-3 py-1 border border-slate-300 rounded text-xs hover:bg-slate-100">Cancel</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($dnsRecords)): ?>
                        <tr><td colspan="6" class="px-4 py-6 text-center text-slate-400">No records synced yet. Run a Cloudflare sync first.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add DNS Record Form -->
    <div class="bg-white border border-slate-200 rounded-lg p-5">
        <h2 class="text-sm font-semibold text-slate-700 mb-3">Add DNS Record</h2>
        <form method="POST" action="/domains/<?= $domain['id'] ?>/dns" class="flex flex-wrap gap-2 items-end text-sm">
            <div>
                <label class="block text-xs text-slate-500 mb-0.5">Type</label>
                <select name="type" class="border border-slate-300 rounded px-2 py-1.5 text-sm bg-white">
                    <?php foreach (['A','AAAA','CNAME','MX','TXT','NS','SRV','CAA'] as $t): ?>
                        <option value="<?= $t ?>"><?= $t ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-0.5">Name</label>
                <input type="text" name="name" placeholder="@" class="border border-slate-300 rounded px-2 py-1.5 text-sm font-mono w-36">
            </div>
            <div class="flex-1 min-w-[200px]">
                <label class="block text-xs text-slate-500 mb-0.5">Content</label>
                <input type="text" name="content" placeholder="1.2.3.4 or target.example.com" class="border border-slate-300 rounded px-2 py-1.5 text-sm font-mono w-full">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-0.5">TTL</label>
                <input type="number" name="ttl" value="1" min="1" class="border border-slate-300 rounded px-2 py-1.5 text-sm w-16">
            </div>
            <div class="flex items-end gap-1.5 pb-1.5">
                <input type="checkbox" name="proxied" value="1" id="new-proxied">
                <label for="new-proxied" class="text-xs text-slate-600">Proxied</label>
            </div>
            <div class="flex items-end">
                <button type="submit" class="px-3 py-1.5 bg-accent-600 text-white text-sm rounded hover:bg-accent-700">Add Record</button>
            </div>
        </form>
    </div>

    <?php endif ?>
</div>

<script>
function openEditForm(recordId) {
    document.getElementById('rec-edit-' + recordId).style.display = '';
    document.getElementById('rec-row-' + recordId).style.display = 'none';
}
function closeEditForm(recordId) {
    document.getElementById('rec-edit-' + recordId).style.display = 'none';
    document.getElementById('rec-row-' + recordId).style.display = '';
}
</script>
