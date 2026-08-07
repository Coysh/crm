<div class="max-w-5xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">Uptime Kuma Settings</h1>

    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-5 max-w-2xl">
        <form method="POST" action="/settings/uptime-kuma/credentials" class="space-y-3">
            <?= csrfField() ?>
            <h2 class="text-sm font-semibold text-slate-700">Account access</h2>
            <p class="text-xs text-slate-500 -mt-1">
                Uptime Kuma's REST endpoint is read-only, so reading richer data and creating monitors both
                go over its Socket.IO API — which authenticates with your account, not an API key.
            </p>
            <div>
                <label class="block text-sm font-medium text-slate-700">Base URL</label>
                <input type="text" name="base_url" value="<?= e($kumaCfg['base_url'] ?? '') ?>"
                       placeholder="https://uptime.example.com"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700">Username</label>
                    <input type="text" name="username" value="<?= e($kumaCfg['username'] ?? '') ?>" autocomplete="off"
                           class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700">Password</label>
                    <input type="password" name="password" value="" autocomplete="new-password"
                           placeholder="<?= !empty($kumaCfg['password']) ? '••••••••  saved' : '' ?>"
                           class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700">2FA code <span class="text-slate-400 font-normal">(only if enabled on Uptime Kuma)</span></label>
                <input type="text" name="totp" value="" autocomplete="off" inputmode="numeric" placeholder="123456"
                       class="w-40 border border-slate-300 rounded px-3 py-2 text-sm font-mono">
                <p class="text-xs text-slate-500 mt-1">
                    Needed once, to connect. The session token returned is kept so later syncs don't ask again.
                </p>
            </div>
            <button class="px-4 py-2 bg-accent-600 text-white text-sm rounded">Connect</button>
        </form>

        <form method="POST" action="/settings/uptime-kuma" class="space-y-3 border-t border-slate-100 pt-5">
            <?= csrfField() ?>
            <h2 class="text-sm font-semibold text-slate-700">API key <span class="text-slate-400 font-normal">(optional)</span></h2>
            <p class="text-xs text-slate-500 -mt-1">
                Only used as a fallback when no account is connected. It can read status via
                <code class="font-mono">/metrics</code> but cannot create monitors or read uptime figures.
            </p>
            <input type="password" name="api_key" value="" autocomplete="off"
                   placeholder="<?= !empty($kumaCfg['api_key']) ? '••••••••  key saved — enter a new one to replace' : 'uk1_…' ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
            <input type="hidden" name="base_url" value="<?= e($kumaCfg['base_url'] ?? '') ?>">
            <button class="px-3 py-1.5 border rounded text-sm">Save API key</button>
        </form>

        <div class="flex flex-wrap gap-2">
            <form method="POST" action="/settings/uptime-kuma/test"><?= csrfField() ?><button class="px-3 py-1.5 border rounded text-sm">Test Connection</button></form>
            <form method="POST" action="/settings/uptime-kuma/sync"><?= csrfField() ?><button class="px-3 py-1.5 border rounded text-sm">Sync Now</button></form>
            <form method="POST" action="/settings/uptime-kuma/disconnect"><?= csrfField() ?><button class="px-3 py-1.5 border rounded text-sm text-red-600">Disconnect</button></form>
        </div>

        <p class="text-xs text-slate-500">
            Status:
            <?php if ($canWrite): ?>
                <span class="text-green-700">Connected (Socket.IO — full read + monitor creation)</span>
            <?php elseif ($connected): ?>
                <span class="text-amber-700">Connected via API key only — read-only, no monitor creation</span>
            <?php else: ?>
                Not connected
            <?php endif ?>
            · Last sync: <?= !empty($kumaCfg['last_sync_at']) ? formatDurationSince($kumaCfg['last_sync_at']) . ' ago' : '—' ?>
        </p>
        <?php if ($lastError): ?>
            <div class="flex items-center gap-3">
                <p class="text-xs text-red-600">Last sync error: <?= e($lastError['error_message']) ?></p>
                <form method="POST" action="/settings/uptime-kuma/errors/dismiss">
                    <?= csrfField() ?>
                    <button class="text-xs text-slate-400 hover:text-slate-600 underline">Dismiss</button>
                </form>
            </div>
        <?php endif ?>
    </div>

    <?php if ($canWrite): ?>
    <!-- Template monitor -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 max-w-2xl">
        <h2 class="text-sm font-semibold text-slate-700">Template monitor</h2>
        <p class="text-xs text-slate-500 mt-1">
            New monitors are created by cloning this one, so they inherit its notifications, check interval,
            retries and accepted status codes. Only the name and URL differ. Change it in Uptime Kuma and
            every monitor made from here afterwards follows suit.
        </p>
        <form method="POST" action="/settings/uptime-kuma/template" class="flex items-center gap-2 mt-3">
            <?= csrfField() ?>
            <select name="template_monitor_id" class="border border-slate-300 rounded px-3 py-2 text-sm flex-1">
                <option value="">— none chosen —</option>
                <?php foreach ($monitors as $m): if (!$m['kuma_id']) continue; ?>
                    <option value="<?= (int)$m['kuma_id'] ?>" <?= (int)($kumaCfg['template_monitor_id'] ?? 0) === (int)$m['kuma_id'] ? 'selected' : '' ?>>
                        <?= e($m['monitor_name']) ?>
                    </option>
                <?php endforeach ?>
            </select>
            <button class="px-4 py-2 bg-accent-600 text-white text-sm rounded">Save</button>
        </form>
        <?php if (empty($kumaCfg['template_monitor_id'])): ?>
            <p class="text-xs text-amber-600 mt-2">Choose a template before creating monitors.</p>
        <?php endif ?>
    </div>

    <!-- Sites with no monitor -->
    <?php if ($unmonitored): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <form method="POST" action="/settings/uptime-kuma/monitors/create">
            <?= csrfField() ?>
            <input type="hidden" name="redirect" value="/settings/uptime-kuma">
            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Active sites with no monitor</h2>
                <span class="text-xs text-slate-400"><?= count($unmonitored) ?> site(s)</span>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($unmonitored as $u): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2 w-8">
                                <input type="checkbox" name="site_ids[]" value="<?= (int)$u['id'] ?>"
                                       class="rounded border-slate-300 text-accent-600 focus:ring-accent-500">
                            </td>
                            <td class="px-4 py-2 font-mono text-xs">
                                <a href="/sites/<?= (int)$u['id'] ?>" class="text-accent-600 hover:underline"><?= e($u['domain']) ?></a>
                            </td>
                            <td class="px-4 py-2 text-xs text-slate-500"><?= e($u['client_name'] ?: 'Unassigned') ?></td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            <div class="px-5 py-3 border-t border-slate-200 bg-slate-50 flex items-center gap-3">
                <button type="submit" <?= empty($kumaCfg['template_monitor_id']) ? 'disabled' : '' ?>
                        onclick="return confirm('Create Uptime Kuma monitors for the selected sites?')"
                        class="px-3 py-1.5 bg-accent-600 text-white text-sm rounded disabled:opacity-40 disabled:cursor-not-allowed">
                    Create monitors for selected
                </button>
                <span class="text-xs text-slate-500">Sites already monitored in Uptime Kuma are skipped, so this is safe to re-run.</span>
            </div>
        </form>
    </div>
    <?php endif ?>
    <?php endif ?>

    <?php if ($monitors): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Monitors</h2>
            <span class="text-xs text-slate-400"><?= count($monitors) ?> total</span>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2 text-left">Monitor</th>
                    <th class="px-4 py-2 text-left">Type</th>
                    <th class="px-4 py-2 text-left">Status</th>
                    <th class="px-4 py-2 text-right">Response</th>
                    <th class="px-4 py-2 text-right">24h</th>
                    <th class="px-4 py-2 text-right">30d</th>
                    <th class="px-4 py-2 text-right">Cert</th>
                    <th class="px-4 py-2 text-left">Linked CRM Site</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($monitors as $m):
                    $state = uptimeStatus(
                        $m['status'] === null ? null : (int)$m['status'],
                        $m['active'] === null ? null : (int)$m['active']
                    );
                    $certDays = $m['cert_days_remaining'] === null ? null : (int)$m['cert_days_remaining'];
                ?>
                    <tr class="hover:bg-slate-50 <?= $m['is_stale'] ? 'opacity-50' : '' ?>">
                        <td class="px-4 py-2">
                            <span class="text-slate-800"><?= e($m['monitor_name']) ?></span>
                            <?php if ($m['is_stale']): ?>
                                <span class="ml-1 text-xs text-slate-400">(stale)</span>
                            <?php elseif ((int)$m['missed_syncs'] > 0): ?>
                                <span class="ml-1 text-xs text-amber-600">missing (<?= (int)$m['missed_syncs'] ?>)</span>
                            <?php endif ?>
                            <?php if ($m['created_by_crm']): ?>
                                <span class="ml-1 text-xs text-slate-400">created here</span>
                            <?php endif ?>
                            <?php if (!empty($m['monitor_url']) || !empty($m['monitor_hostname'])): ?>
                                <div class="text-xs text-slate-400 font-mono"><?= e($m['monitor_url'] ?: $m['monitor_hostname']) ?></div>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-xs text-slate-500"><?= e($m['monitor_type'] ?: '—') ?></td>
                        <td class="px-4 py-2">
                            <span class="flex items-center gap-1.5 text-xs <?= $state['text'] ?>">
                                <span class="w-1.5 h-1.5 rounded-full <?= $state['dot'] ?>"></span><?= $state['label'] ?>
                            </span>
                            <?php if ((int)$m['status'] === 0 && !empty($m['status_changed_at'])): ?>
                                <div class="text-xs text-slate-400">for <?= formatDurationSince($m['status_changed_at']) ?></div>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-right text-xs text-slate-600"><?= $m['response_time_ms'] !== null ? (int)$m['response_time_ms'] . ' ms' : '—' ?></td>
                        <td class="px-4 py-2 text-right text-xs text-slate-600"><?= $m['uptime_24h'] !== null ? number_format((float)$m['uptime_24h'], 2) . '%' : '—' ?></td>
                        <td class="px-4 py-2 text-right text-xs text-slate-600">
                            <?= $m['uptime_30d'] !== null ? number_format((float)$m['uptime_30d'], 2) . '%' : '—' ?>
                            <?php if ($m['uptime_30d'] !== null && $m['uptime_is_local']): ?>
                                <span class="text-slate-400" title="Calculated from this CRM's own samples — Uptime Kuma hasn't reported a figure for this monitor">*</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2 text-right text-xs">
                            <?php if ($certDays === null): ?>
                                <span class="text-slate-300">—</span>
                            <?php else: ?>
                                <span class="<?= $certDays < 14 ? 'text-red-600' : ($certDays < 30 ? 'text-amber-600' : 'text-slate-600') ?>"><?= $certDays ?>d</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2">
                            <?php if ($m['client_site_id']): ?>
                                <div class="flex items-center gap-2">
                                    <a href="/sites/<?= (int)$m['client_site_id'] ?>" class="text-accent-600 hover:underline text-xs">
                                        <?= e($m['client_site_domain'] ?: 'Site #' . (int)$m['client_site_id']) ?>
                                    </a>
                                    <?php if ($m['link_is_manual']): ?>
                                        <span class="text-xs text-slate-400">manual</span>
                                    <?php endif ?>
                                    <form method="POST" action="/settings/uptime-kuma/monitors/<?= (int)$m['id'] ?>/unlink" class="inline">
                                        <?= csrfField() ?>
                                        <button type="submit" class="text-xs text-slate-400 hover:text-slate-600 underline">Unlink</button>
                                    </form>
                                </div>
                                <?php if (!empty($m['client_name'])): ?>
                                    <div class="text-xs text-slate-400"><?= e($m['client_name']) ?></div>
                                <?php endif ?>
                            <?php else: ?>
                                <form method="POST" action="/settings/uptime-kuma/monitors/<?= (int)$m['id'] ?>/link" class="flex items-center gap-2">
                                    <?= csrfField() ?>
                                    <select name="client_site_id" class="border border-slate-300 rounded px-2 py-1 text-xs max-w-48">
                                        <option value="">Unmatched — link to…</option>
                                        <?php foreach ($siteOptions as $opt): ?>
                                            <option value="<?= (int)$opt['id'] ?>"><?= e($opt['label']) ?><?= $opt['client_name'] ? ' — ' . e($opt['client_name']) : '' ?></option>
                                        <?php endforeach ?>
                                    </select>
                                    <button type="submit" class="text-xs text-accent-600 hover:underline">Link</button>
                                </form>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
        </div>
        <div class="px-5 py-3 border-t border-slate-200 bg-slate-50 text-xs text-slate-500">
            <?php if ($canWrite): ?>
                Uptime figures come from Uptime Kuma itself. A <span class="text-slate-400">*</span> marks one
                calculated from this CRM's own samples instead, which only covers the period since the
                integration was switched on.
            <?php else: ?>
                Uptime percentages are calculated by this CRM from the samples it takes on each sync, so they
                only cover the period since the integration was switched on. Connect an account above to read
                Uptime Kuma's own figures instead.
                <span class="block mt-1">Without an account, monitors are keyed by name — renaming one in Uptime Kuma creates a new row here and marks the old one stale.</span>
            <?php endif ?>
            <span class="block mt-1">Uptime Kuma remains the system of record and the only thing that sends alerts.</span>
        </div>
    </div>
    <?php elseif ($connected): ?>
        <p class="text-sm text-slate-500">No monitors synced yet — hit <strong>Sync Now</strong>.</p>
    <?php endif ?>
</div>
