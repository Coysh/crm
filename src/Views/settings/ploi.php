<div class="max-w-2xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">Ploi Settings</h1>

    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <form method="POST" action="/settings/ploi" class="space-y-3">
            <label class="block text-sm font-medium text-slate-700">API Token (Bearer)</label>
            <input type="password" name="api_token" value="" autocomplete="off"
                   placeholder="<?= $connected ? '••••••••  token saved — enter a new one to replace' : 'Paste your Ploi API token' ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
            <button class="px-4 py-2 bg-accent-600 text-white text-sm rounded">Save Token</button>
        </form>

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="/settings/ploi/test"><button class="px-3 py-1.5 border rounded text-sm">Test Connection</button></form>
            <form method="POST" action="/settings/ploi/sync"><button class="px-3 py-1.5 border rounded text-sm">Sync Now</button></form>
            <form method="POST" action="/settings/ploi/sync-domains"
                  onsubmit="return confirm('Create and link domain records for all Ploi-imported sites that are missing them?')">
                <button class="px-3 py-1.5 border border-amber-300 bg-amber-50 text-amber-700 rounded text-sm hover:bg-amber-100">Re-sync Domains</button>
            </form>
            <form method="POST" action="/settings/ploi/disconnect"><button class="px-3 py-1.5 border rounded text-sm text-red-600">Disconnect</button></form>
        </div>

        <p class="text-xs text-slate-500">Status: <?= $connected ? 'Connected' : 'Not connected' ?>. Last sync: <?= !empty($ploiCfg['last_sync_at']) ? formatDate($ploiCfg['last_sync_at']) : '—' ?></p>
        <?php if ($lastError): ?>
            <div class="flex items-center gap-3">
                <p class="text-xs text-red-600">Last sync error: <?= e($lastError['error_message']) ?></p>
                <form method="POST" action="/settings/ploi/errors/dismiss">
                    <button class="text-xs text-slate-400 hover:text-slate-600 underline">Dismiss</button>
                </form>
            </div>
        <?php endif ?>
    </div>

    <!-- Stale Ploi records -->
    <?php
    $reportServers = $staleReport['servers'] ?? [];
    $reportSites   = $staleReport['sites'] ?? [];
    ?>
    <?php if (!empty($staleServers) || !empty($staleSites)): ?>
    <div class="bg-white border border-amber-200 rounded-lg p-6 space-y-4">
        <div>
            <h2 class="text-sm font-semibold text-slate-700">Deleted in Ploi</h2>
            <p class="text-xs text-slate-500 mt-1">
                These servers and sites no longer exist in Ploi, so syncs skip them. A site moved to another
                server comes back under a new Ploi ID with an empty CRM record beside it — transfer the old
                record onto it so the client, domain, notes and cost links follow the move instead of being
                stranded on the deleted server.
            </p>
        </div>

        <?php if (!empty($reportServers)): ?>
            <div class="text-xs text-slate-600 space-y-1">
                <span class="font-medium">Servers:</span>
                <?php foreach ($reportServers as $srv): ?>
                    <div class="flex flex-wrap items-baseline gap-2">
                        <span class="inline-block bg-amber-50 border border-amber-200 rounded px-2 py-0.5"><?= e($srv['name'] ?? '') ?> <span class="text-slate-400">#<?= (int)$srv['ploi_id'] ?></span></span>
                        <?php if (!empty($srv['blockers'])): ?>
                            <span class="text-slate-400">CRM server record still linked to <?= e(implode(', ', $srv['blockers'])) ?></span>
                        <?php elseif (!empty($srv['server_id'])): ?>
                            <span class="text-slate-400">CRM server record “<?= e($srv['crm_server_name'] ?? '') ?>” no longer used</span>
                        <?php endif ?>
                    </div>
                <?php endforeach ?>
            </div>
        <?php endif ?>

        <form method="POST" action="/settings/ploi/stale/reconcile" class="space-y-3"
              onsubmit="return confirm('Apply these choices? Transferred records move to their new server, records marked for deletion are removed for good.')">
            <?= csrfField() ?>

            <?php if (count($reportSites) > 1): ?>
                <div class="flex flex-wrap items-center gap-2 text-xs text-slate-500">
                    <span><?= count($reportSites) ?> sites</span>
                    <button type="button" class="px-2 py-1 border border-slate-300 rounded hover:bg-slate-50" onclick="staleSetAll('transfer')">Transfer all matched</button>
                    <button type="button" class="px-2 py-1 border border-slate-300 rounded hover:bg-slate-50" onclick="staleSetAll('keep')">Keep all</button>
                </div>
                <script>
                    function staleSetAll(action) {
                        document.querySelectorAll('input[type=radio][name^="action["][value="' + action + '"]')
                            .forEach(function (radio) { radio.checked = true; });
                    }
                </script>
            <?php endif ?>

            <?php foreach ($reportSites as $site): ?>
                <?php
                $sid        = (int)$site['id'];
                $candidates = $site['candidates'] ?? [];
                $hasRecord  = !empty($site['client_site_id']);
                $default    = ($candidates && $hasRecord) ? 'transfer' : 'keep';
                ?>
                <div class="border border-slate-200 rounded p-3 space-y-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <span class="font-mono text-sm text-slate-700"><?= e($site['domain'] ?? '') ?></span>
                        <span class="text-xs text-slate-400">was on <?= e($site['old_server_name'] ?? 'a deleted server') ?></span>
                    </div>

                    <p class="text-xs text-slate-500">
                        CRM record:
                        <?php if ($hasRecord): ?>
                            <?= e($site['client_name'] ?: 'no client assigned') ?><?= !empty($site['crm_domain']) ? ' · ' . e($site['crm_domain']) : '' ?><?= !empty($site['crm_notes']) ? ' · has notes' : '' ?>
                        <?php else: ?>
                            none linked
                        <?php endif ?>
                    </p>

                    <div class="space-y-1 text-xs text-slate-600">
                        <?php if ($candidates && $hasRecord): ?>
                            <label class="flex items-center gap-2">
                                <input type="radio" name="action[<?= $sid ?>]" value="transfer" <?= $default === 'transfer' ? 'checked' : '' ?>>
                                <span>Transfer CRM record to</span>
                                <select name="successor[<?= $sid ?>]" class="border border-slate-300 rounded px-2 py-1 text-xs">
                                    <?php foreach ($candidates as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>">
                                            <?= e($c['server_name'] ?? 'unknown server') ?> (#<?= (int)$c['ploi_id'] ?>)<?= !empty($c['client_name']) ? ' — ' . e($c['client_name']) : '' ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </label>
                        <?php elseif ($hasRecord): ?>
                            <p class="text-slate-400">No live Ploi site serves this domain, so there is nothing to transfer to.</p>
                        <?php endif ?>

                        <label class="flex items-center gap-2">
                            <input type="radio" name="action[<?= $sid ?>]" value="keep" <?= $default === 'keep' ? 'checked' : '' ?>>
                            <span>Keep the CRM record as it is</span>
                        </label>

                        <?php if ($hasRecord): ?>
                            <label class="flex items-center gap-2">
                                <input type="radio" name="action[<?= $sid ?>]" value="delete">
                                <span class="text-red-600">Delete the CRM site record too</span>
                            </label>
                        <?php endif ?>
                    </div>
                </div>
            <?php endforeach ?>

            <label class="flex items-center gap-2 text-xs text-slate-600">
                <input type="checkbox" name="remove_crm_servers" value="1" checked>
                <span>Also delete the CRM server records for the deleted servers, when nothing else is linked to them</span>
            </label>

            <div class="flex flex-wrap items-center gap-3 pt-1">
                <button class="px-3 py-1.5 border border-amber-300 bg-amber-50 text-amber-700 rounded text-sm hover:bg-amber-100">Apply &amp; Remove Old Records</button>
                <span class="text-xs text-slate-400">Removes the stale Ploi rows either way.</span>
            </div>
        </form>

        <form method="POST" action="/settings/ploi/stale/purge"
              onsubmit="return confirm('Remove the stale Ploi records listed above? CRM servers, sites and clients are not affected.')">
            <button class="text-xs text-slate-400 hover:text-slate-600 underline">Purge stale Ploi rows only, without transferring anything</button>
        </form>
    </div>
    <?php endif ?>

    <!-- Excluded Servers -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Excluded Servers</h2>
        <p class="text-xs text-slate-500">These servers are skipped entirely during Ploi syncs.</p>
        <?php if (!empty($serverExclusions)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500 uppercase tracking-wide bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left">Ploi Server ID</th>
                            <th class="px-3 py-2 text-left">Name</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                            <th class="px-3 py-2 text-left">Excluded At</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($serverExclusions as $ex): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 tabular-nums text-slate-500"><?= (int)$ex['ploi_server_id'] ?></td>
                                <td class="px-3 py-2 text-xs"><?= e($ex['name'] ?? '—') ?></td>
                                <td class="px-3 py-2 text-slate-500 text-xs"><?= e($ex['reason'] ?? '—') ?></td>
                                <td class="px-3 py-2 text-slate-400 text-xs"><?= formatDate($ex['created_at']) ?></td>
                                <td class="px-3 py-2 text-right">
                                    <form method="POST" action="/settings/ploi/server-exclusions/<?= (int)$ex['id'] ?>/remove"
                                          onsubmit="return confirm('Remove this exclusion? This server will be included in future syncs.')">
                                        <button class="text-xs text-red-500 hover:text-red-700">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-xs text-slate-400">No excluded servers.</p>
        <?php endif ?>
    </div>

    <!-- Sync Exclusions -->
    <?php
    $exclusions = [];
    try {
        global $db;
        $exclusions = $db->query("SELECT * FROM ploi_sync_exclusions ORDER BY created_at DESC")->fetchAll();
    } catch (\Throwable) {}
    ?>
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Excluded Sites</h2>
        <p class="text-xs text-slate-500">These sites are skipped during Ploi syncs (e.g. because they were deleted from the CRM).</p>
        <?php if ($exclusions): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500 uppercase tracking-wide bg-slate-50">
                        <tr>
                            <th class="px-3 py-2 text-left">Ploi Site ID</th>
                            <th class="px-3 py-2 text-left">Domain</th>
                            <th class="px-3 py-2 text-left">Reason</th>
                            <th class="px-3 py-2 text-left">Excluded At</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($exclusions as $ex): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-3 py-2 tabular-nums text-slate-500"><?= (int)$ex['ploi_site_id'] ?></td>
                                <td class="px-3 py-2 font-mono text-xs"><?= e($ex['domain'] ?? '—') ?></td>
                                <td class="px-3 py-2 text-slate-500 text-xs"><?= e($ex['reason'] ?? '—') ?></td>
                                <td class="px-3 py-2 text-slate-400 text-xs"><?= formatDate($ex['created_at']) ?></td>
                                <td class="px-3 py-2 text-right">
                                    <form method="POST" action="/settings/ploi/exclusions/<?= (int)$ex['id'] ?>/remove"
                                          onsubmit="return confirm('Remove this exclusion? This site will be included in future syncs.')">
                                        <button class="text-xs text-red-500 hover:text-red-700">Remove</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="text-xs text-slate-400">No exclusions.</p>
        <?php endif ?>
    </div>
</div>
