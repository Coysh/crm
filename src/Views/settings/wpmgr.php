<div class="max-w-2xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">WPMGR Settings</h1>

    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <form method="POST" action="/settings/wpmgr" class="space-y-3">
            <div>
                <label class="block text-sm font-medium text-slate-700">Base URL</label>
                <input type="text" name="base_url" value="<?= e($wpmgrCfg['base_url'] ?? '') ?>"
                       placeholder="https://wpmgr.example.com"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">API Key</label>
                <input type="password" name="api_key" value="" autocomplete="off"
                       placeholder="<?= $connected ? '••••••••  key saved — enter a new one to replace' : 'Paste your WPMGR API key' ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
            </div>
            <button class="px-4 py-2 bg-accent-600 text-white text-sm rounded">Save</button>
        </form>

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="/settings/wpmgr/test"><button class="px-3 py-1.5 border rounded text-sm">Test Connection</button></form>
            <form method="POST" action="/settings/wpmgr/sync"><button class="px-3 py-1.5 border rounded text-sm">Sync Now</button></form>
            <form method="POST" action="/settings/wpmgr/disconnect"><button class="px-3 py-1.5 border rounded text-sm text-red-600">Disconnect</button></form>
        </div>

        <p class="text-xs text-slate-500">Status: <?= $connected ? 'Connected' : 'Not connected' ?>. Last sync: <?= !empty($wpmgrCfg['last_sync_at']) ? formatDate($wpmgrCfg['last_sync_at']) : '—' ?></p>
        <?php if ($lastError): ?>
            <div class="flex items-center gap-3">
                <p class="text-xs text-red-600">Last sync error: <?= e($lastError['error_message']) ?></p>
                <form method="POST" action="/settings/wpmgr/errors/dismiss">
                    <button class="text-xs text-slate-400 hover:text-slate-600 underline">Dismiss</button>
                </form>
            </div>
        <?php endif ?>
    </div>

    <?php if ($sites): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Synced Sites</h2>
            <span class="text-xs text-slate-400"><?= count($sites) ?> total</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2 text-left">URL</th>
                    <th class="px-4 py-2 text-left">WP</th>
                    <th class="px-4 py-2 text-left">Updates</th>
                    <th class="px-4 py-2 text-left">Linked CRM Site</th>
                    <th class="px-4 py-2 text-left">Stale</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($sites as $s): ?>
                    <tr class="hover:bg-slate-50 <?= $s['is_stale'] ? 'opacity-50' : '' ?>">
                        <td class="px-4 py-2 font-mono text-xs"><?= e($s['url']) ?></td>
                        <td class="px-4 py-2 text-slate-600"><?= e($s['wp_version'] ?: '—') ?></td>
                        <td class="px-4 py-2">
                            <?php if ((int)$s['updates_available'] > 0): ?>
                                <span class="px-1.5 py-0.5 rounded text-xs bg-amber-100 text-amber-800"><?= (int)$s['updates_available'] ?></span>
                            <?php else: ?>
                                <span class="text-slate-300">—</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-sm">
                            <?php if ($s['client_site_id']): ?>
                                <a href="/sites/<?= (int)$s['client_site_id'] ?>" class="text-accent-600 hover:underline"><?= e($s['client_site_domain'] ?: ('Site #' . $s['client_site_id'])) ?></a>
                            <?php else: ?>
                                <span class="text-amber-600 mr-2">Unmatched</span>
                                <form method="POST" action="/settings/wpmgr/sites/<?= (int)$s['id'] ?>/create-site" class="inline">
                                    <button type="submit"
                                            onclick="return confirm('Create a CRM site for this WPMGR site?')"
                                            class="text-xs text-accent-600 hover:underline">Create Site</button>
                                </form>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-400"><?= $s['is_stale'] ? 'Yes' : '—' ?></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif ?>
</div>
