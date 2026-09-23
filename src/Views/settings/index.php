<div class="max-w-3xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">Settings</h1>

    <?php
        $ago = fn(?string $t) => ($d = formatDurationSince($t)) === 'just now' ? $d : "{$d} ago";
        $lastLine = function (?string $out): string {
            $lines = array_filter(array_map('trim', explode("\n", (string)$out)));
            return (string)end($lines);
        };
        $stateStyle = [
            'ok'      => ['bg-green-100 text-green-700', 'OK'],
            'skipped' => ['bg-slate-100 text-slate-500', 'Skipped'],
            'running' => ['bg-blue-100 text-blue-700', 'Running'],
            'failed'  => ['bg-red-100 text-red-700', 'Failed'],
            'stale'   => ['bg-amber-100 text-amber-700', 'Stale'],
            'never'   => ['bg-slate-100 text-slate-500', 'Never run'],
        ];
    ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden" id="jobs">
        <div class="px-5 py-3 border-b border-slate-200">
            <h2 class="text-sm font-semibold text-slate-700">Scheduled Jobs</h2>
        </div>
        <?php if (!$cronInstalled): ?>
            <div class="px-5 py-3 text-xs text-amber-800 bg-amber-50 border-b border-amber-200">
                <code>scripts/cron.php</code> hasn't run yet. Replace the individual sync cron lines with this single entry:
                <pre class="mt-2 font-mono bg-white border border-amber-200 rounded px-2 py-1 overflow-x-auto">* * * * * cd <?= e(BASE_PATH) ?> &amp;&amp; php scripts/cron.php &gt;&gt; data/cron.log 2&gt;&amp;1</pre>
            </div>
        <?php endif ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 text-left">
                <tr>
                    <th class="px-5 py-2 font-medium">Job</th>
                    <th class="px-3 py-2 font-medium">Schedule</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 font-medium">Last success</th>
                    <th class="px-5 py-2 font-medium">Last error</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            <?php foreach ($jobs as $name => $j): [$cls, $lbl] = $stateStyle[$j['state']]; ?>
                <tr>
                    <td class="px-5 py-2"><a href="<?= e($j['url']) ?>" class="text-slate-800 hover:text-accent-600"><?= e($j['label']) ?></a></td>
                    <td class="px-3 py-2 text-xs text-slate-500 whitespace-nowrap"><?= e($j['schedule']) ?></td>
                    <td class="px-3 py-2"><span class="inline-block px-1.5 py-0.5 rounded text-xs font-medium <?= $cls ?>" title="<?= e($lastLine($j['last_run']['output'] ?? '')) ?>"><?= $lbl ?></span></td>
                    <td class="px-3 py-2 text-xs text-slate-500 whitespace-nowrap"><?= $j['last_ok_at'] ? e($ago($j['last_ok_at'])) : '—' ?></td>
                    <td class="px-5 py-2 text-xs text-red-600">
                        <?php if ($j['last_failure']): ?>
                            <span title="<?= e($j['last_failure']['output']) ?>"><?= e($ago($j['last_failure']['started_at'])) ?> — <?= e(mb_strimwidth($lastLine($j['last_failure']['output']), 0, 90, '…')) ?></span>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6 scroll-mt-4" id="notifications">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-700">Notifications</h2>
                <p class="text-sm text-slate-500 mt-1">A morning email of everything on <a href="/today" class="text-accent-600 hover:underline">Today</a>, and an immediate email when a monitored site goes down. Sent through Mailgun.</p>
            </div>
            <?php if (!empty($notifyCfg['last_digest_at'])): ?>
                <span class="text-xs text-slate-400 whitespace-nowrap">Last digest <?= e($ago($notifyCfg['last_digest_at'])) ?></span>
            <?php endif ?>
        </div>
        <?php if (!$mailgunReady): ?>
            <p class="mt-3 text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded px-3 py-2">Mailgun isn't configured yet — set the API key and sending domain in <a href="/settings/email" class="underline">Email Marketing</a> first.</p>
        <?php endif ?>
        <form method="POST" action="/settings/notifications" class="mt-4 grid sm:grid-cols-2 gap-4 text-sm">
            <?= csrfField() ?>
            <label class="block">
                <span class="block font-medium text-slate-700 mb-1">Send to</span>
                <input type="email" name="recipient" value="<?= e($notifyCfg['recipient'] ?? '') ?>" placeholder="you@example.com"
                       class="w-full border border-slate-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent-500">
            </label>
            <label class="block">
                <span class="block font-medium text-slate-700 mb-1">Digest time (UK)</span>
                <select name="digest_time" class="w-full border border-slate-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <?php for ($m = 5 * 60; $m <= 10 * 60; $m += 30): $t = sprintf('%02d:%02d', intdiv($m, 60), $m % 60); ?>
                        <option value="<?= $t ?>" <?= ($notifyCfg['digest_time'] ?? '07:30') === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endfor ?>
                </select>
            </label>
            <div class="sm:col-span-2 space-y-2">
                <label class="flex items-center gap-2"><input type="checkbox" name="digest_enabled" value="1" <?= !empty($notifyCfg['digest_enabled']) ? 'checked' : '' ?>> Daily digest (skipped when nothing is urgent or due this week)</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="include_low" value="1" <?= !empty($notifyCfg['include_low']) ? 'checked' : '' ?>> Include housekeeping items in the digest</label>
                <label class="flex items-center gap-2"><input type="checkbox" name="site_down_alerts" value="1" <?= !empty($notifyCfg['site_down_alerts']) ? 'checked' : '' ?>> Email me as soon as a monitored site goes down (once per outage)</label>
            </div>
            <div class="sm:col-span-2 flex items-center gap-3">
                <button type="submit" class="px-3 py-1.5 bg-accent-600 text-white rounded text-sm hover:bg-accent-700">Save</button>
                <button type="submit" formaction="/settings/notifications/test" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50" <?= $mailgunReady && !empty($notifyCfg['recipient']) ? '' : 'disabled' ?>>Send digest now</button>
            </div>
        </form>
    </div>

    <div class="grid md:grid-cols-2 gap-4">
        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">Email Marketing</h2>
            <p class="text-sm text-slate-500 mt-1">Configure Mailgun delivery, sender identity, webhooks, and campaign images.</p>
            <a href="/settings/email" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage Email Marketing →</a>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">FreeAgent Integration</h2>
            <p class="text-sm text-slate-500 mt-1">Sync contacts, invoices, and bank transactions.</p>
            <p class="text-xs mt-3 <?= $connected ? 'text-green-700' : 'text-slate-500' ?>"><?= $connected ? 'Connected' : 'Not connected' ?></p>
            <a href="/settings/freeagent" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage FreeAgent →</a>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">Ploi Integration</h2>
            <p class="text-sm text-slate-500 mt-1">Read-only server/site reference sync from Ploi.</p>
            <p class="text-xs mt-3 <?= $ploiConnected ? 'text-green-700' : 'text-slate-500' ?>"><?= $ploiConnected ? 'Connected' : 'Not connected' ?></p>
            <a href="/settings/ploi" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage Ploi →</a>

            <div class="mt-4 text-xs text-slate-600 space-y-1">
                <p><?= $ploiStats['servers_total'] ?> servers synced (auto-created in CRM)</p>
                <p><?= $ploiStats['sites_linked'] ?> of <?= $ploiStats['sites_total'] ?> sites assigned to a client</p>
                <?php if ($ploiStats['unlinked_sites']): ?><p class="text-amber-600">Unassigned: <?= e(implode(', ', $ploiStats['unlinked_sites'])) ?><?= count($ploiStats['unlinked_sites']) === 8 ? '…' : '' ?></p><?php endif ?>
                <?php if (!empty($ploiCfg['last_sync_at'])): ?><p>Last sync: <?= formatDate($ploiCfg['last_sync_at']) ?></p><?php endif ?>
                <?php if ($ploiStats['last_error']): ?><p class="text-red-600">Last error: <?= e($ploiStats['last_error']['error_message']) ?></p><?php endif ?>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">WPMGR Integration</h2>
            <p class="text-sm text-slate-500 mt-1">Read-only WordPress site sync (versions, updates, backups, uptime).</p>
            <p class="text-xs mt-3 <?= $wpmgrConnected ? 'text-green-700' : 'text-slate-500' ?>"><?= $wpmgrConnected ? 'Connected' : 'Not connected' ?></p>
            <a href="/settings/wpmgr" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage WPMGR →</a>

            <div class="mt-4 text-xs text-slate-600 space-y-1">
                <p><?= $wpmgrStats['sites_linked'] ?> of <?= $wpmgrStats['sites_total'] ?> sites linked to a CRM site</p>
                <?php if (!empty($wpmgrCfg['last_sync_at'])): ?><p>Last sync: <?= formatDate($wpmgrCfg['last_sync_at']) ?></p><?php endif ?>
                <?php if ($wpmgrStats['last_error']): ?><p class="text-red-600">Last error: <?= e($wpmgrStats['last_error']['error_message']) ?></p><?php endif ?>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">Uptime Kuma Integration</h2>
            <p class="text-sm text-slate-500 mt-1">Read-only uptime, response time and TLS expiry per site.</p>
            <p class="text-xs mt-3 <?= $kumaConnected ? 'text-green-700' : 'text-slate-500' ?>"><?= $kumaConnected ? 'Connected' : 'Not connected' ?></p>
            <a href="/settings/uptime-kuma" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage Uptime Kuma →</a>

            <div class="mt-4 text-xs text-slate-600 space-y-1">
                <p><?= $kumaStats['monitors_linked'] ?> of <?= $kumaStats['monitors_total'] ?> monitors linked to a CRM site</p>
                <?php if ($kumaStats['monitors_down'] > 0): ?><p class="text-red-600"><?= $kumaStats['monitors_down'] ?> currently down</p><?php endif ?>
                <?php if (!empty($kumaCfg['last_sync_at'])): ?><p>Last sync: <?= formatDurationSince($kumaCfg['last_sync_at']) ?> ago</p><?php endif ?>
                <?php if ($kumaStats['last_error']): ?><p class="text-red-600">Last error: <?= e($kumaStats['last_error']['error_message']) ?></p><?php endif ?>
            </div>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">Hiveage Invoice Import</h2>
            <p class="text-sm text-slate-500 mt-1">Import historic invoices from a Hiveage CSV export.</p>
            <a href="/settings/import/hiveage" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Import Hiveage Data →</a>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">Cloudflare Integration</h2>
            <p class="text-sm text-slate-500 mt-1">Sync DNS zones, manage DNS records, and link zones to domains.</p>
            <?php
            $cfConnected = false;
            try {
                global $db;
                $cfRow = $db->query("SELECT api_token FROM cloudflare_config WHERE id = 1")->fetch();
                $cfConnected = !empty($cfRow['api_token']);
            } catch (\Throwable) {}
            ?>
            <p class="text-xs mt-3 <?= $cfConnected ? 'text-green-700' : 'text-slate-500' ?>"><?= $cfConnected ? 'Connected' : 'Not connected' ?></p>
            <a href="/settings/cloudflare" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage Cloudflare →</a>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">MCP Access (Claude)</h2>
            <p class="text-sm text-slate-500 mt-1">Connect Claude to the CRM via a secured MCP endpoint; manage and revoke connected apps.</p>
            <a href="/settings/mcp" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Manage MCP Access →</a>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-700">Data Quality</h2>
                <?php if (($dataQualityIssues ?? 0) > 0): ?>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700"><?= (int)$dataQualityIssues ?></span>
                <?php else: ?>
                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">✓</span>
                <?php endif ?>
            </div>
            <p class="text-sm text-slate-500 mt-1">Missing links and incomplete records that skew P&amp;L or hide renewals.</p>
            <a href="/settings/data-quality" class="text-sm text-accent-600 hover:underline mt-3 inline-block">Review Data Quality →</a>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-sm font-semibold text-slate-700">Deletion Log</h2>
            <p class="text-sm text-slate-500 mt-1">Audit trail of permanently deleted clients and entities.</p>
            <a href="/settings/deletion-log" class="text-sm text-accent-600 hover:underline mt-3 inline-block">View Deletion Log →</a>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-6 col-span-full">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-sm font-semibold text-slate-700">Exchange Rates</h2>
                    <p class="text-sm text-slate-500 mt-1">Used to convert USD/EUR recurring costs and domain fees to GBP in P&amp;L calculations. Historic transactions are not recalculated.</p>
                </div>
                <form method="POST" action="/settings/exchange-rates/refresh">
                    <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50 whitespace-nowrap">
                        Refresh Rates
                    </button>
                </form>
            </div>

            <?php if (!empty($exchangeRates)): ?>
                <?php
                    $rateDate = $exchangeRates[0]['date'] ?? null;
                    $stale    = $rateDate && $rateDate < date('Y-m-d', strtotime('-1 day'));
                ?>
                <?php if ($stale): ?>
                    <p class="mt-3 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded px-3 py-2">
                        Rates are from <?= e($rateDate) ?> — more than 24 hours old. Click Refresh to update.
                    </p>
                <?php endif ?>
                <div class="mt-3 flex gap-6 text-sm">
                    <?php foreach ($exchangeRates as $rate): ?>
                        <p class="text-slate-600">
                            1 GBP = <span class="font-mono tabular-nums font-medium"><?= number_format((float)$rate['rate'], 4) ?></span>
                            <?= e($rate['currency']) ?>
                            <span class="text-xs text-slate-400 ml-1">(<?= e($rate['date']) ?>)</span>
                        </p>
                    <?php endforeach ?>
                </div>
            <?php else: ?>
                <p class="mt-3 text-xs text-slate-400">No rates cached yet. Click Refresh to fetch today's rates from Frankfurter.</p>
            <?php endif ?>
        </div>
    </div>
</div>
