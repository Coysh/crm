<div class="max-w-3xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">Cloudflare Settings</h1>

    <!-- Connection -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">API Token</h2>
        <form method="POST" action="/settings/cloudflare" class="space-y-3">
            <div>
                <label class="block text-xs text-slate-500 mb-1">API Token</label>
                <?php if ($connected && $maskedToken): ?>
                    <p class="text-sm text-slate-600 mb-2 font-mono">Current: <?= e($maskedToken) ?></p>
                <?php endif ?>
                <input type="password" name="api_token" placeholder="Paste new token to replace"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500">
                <p class="text-xs text-slate-400 mt-1">Create a token in Cloudflare Dashboard → My Profile → API Tokens. Requires Zone:Read and DNS:Edit permissions.</p>
            </div>
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm rounded hover:bg-accent-700">Save Token</button>
        </form>

        <div class="flex flex-wrap gap-2 pt-2">
            <form method="POST" action="/settings/cloudflare/test">
                <button class="px-3 py-1.5 border rounded text-sm hover:bg-slate-50">Test Connection</button>
            </form>
            <?php if ($connected): ?>
                <form method="POST" action="/settings/cloudflare/sync">
                    <button class="px-3 py-1.5 border rounded text-sm hover:bg-slate-50">Sync Zones &amp; DNS</button>
                </form>
                <form method="POST" action="/settings/cloudflare/disconnect"
                      onsubmit="return confirm('Disconnect Cloudflare? The local zone data will be preserved.')">
                    <button class="px-3 py-1.5 border rounded text-sm text-red-600 hover:bg-slate-50">Disconnect</button>
                </form>
            <?php endif ?>
        </div>

        <p class="text-xs text-slate-500">
            Status: <span class="<?= $connected ? 'text-green-600' : 'text-slate-500' ?>"><?= $connected ? 'Connected' : 'Not connected' ?></span>
            <?php if (!empty($config['last_sync_at'])): ?>
                &nbsp;· Last sync: <?= formatDate($config['last_sync_at']) ?>
            <?php endif ?>
        </p>
    </div>

    <!-- Zone Mapping -->
    <?php if ($zones): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Zones</h2>
            <span class="text-xs text-slate-400"><?= count($zones) ?> zone(s)</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Zone</th>
                        <th class="px-4 py-2.5 text-left">Status</th>
                        <th class="px-4 py-2.5 text-left">Plan</th>
                        <th class="px-4 py-2.5 text-left">Linked Domain</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($zones as $zone): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium"><?= e($zone['name']) ?></td>
                            <td class="px-4 py-2.5">
                                <span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?= $zone['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' ?>">
                                    <?= e(ucfirst($zone['status'] ?? '—')) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500 text-xs"><?= $zone['plan'] ? e($zone['plan']) : '—' ?></td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <?php if ($zone['domain_id']): ?>
                                    <a href="/domains/<?= $zone['domain_id'] ?>" class="text-accent-600 hover:underline text-xs">
                                        <?= e($zone['domain_name'] ?? 'Domain #' . $zone['domain_id']) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-slate-300 text-xs">Not linked</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <?php if ($zone['domain_id']): ?>
                                    <form method="POST" action="/settings/cloudflare/zones/<?= urlencode($zone['zone_id']) ?>/unlink"
                                          onsubmit="return confirm('Unlink this zone?')" class="inline">
                                        <button class="text-xs text-slate-400 hover:text-red-600">Unlink</button>
                                    </form>
                                <?php elseif ($availableDomains): ?>
                                    <form method="POST" action="/settings/cloudflare/zones/<?= urlencode($zone['zone_id']) ?>/link" class="flex items-center gap-1 justify-end">
                                        <select name="domain_id" class="border border-slate-300 rounded px-2 py-0.5 text-xs bg-white">
                                            <option value="">Select domain…</option>
                                            <?php foreach ($availableDomains as $d): ?>
                                                <option value="<?= $d['id'] ?>"><?= e($d['domain']) ?></option>
                                            <?php endforeach ?>
                                        </select>
                                        <button class="text-xs text-accent-600 hover:underline">Link</button>
                                    </form>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php elseif ($connected): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-6 text-center text-sm text-slate-400">
            No zones synced yet. Click "Sync Zones &amp; DNS" to import your Cloudflare zones.
        </div>
    <?php endif ?>
</div>
