<div class="max-w-3xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">Settings</h1>

    <div class="grid md:grid-cols-2 gap-4">
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
