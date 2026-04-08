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
                <p><?= $ploiStats['servers_linked'] ?> of <?= $ploiStats['servers_total'] ?> Ploi servers linked</p>
                <?php if ($ploiStats['unlinked_servers']): ?><p>Unlinked: <?= e(implode(', ', $ploiStats['unlinked_servers'])) ?></p><?php endif ?>
                <p><?= $ploiStats['sites_linked'] ?> of <?= $ploiStats['sites_total'] ?> Ploi sites linked</p>
                <?php if ($ploiStats['unlinked_sites']): ?><p>Unlinked: <?= e(implode(', ', $ploiStats['unlinked_sites'])) ?></p><?php endif ?>
                <?php if (!empty($ploiCfg['last_sync_at'])): ?><p>Last sync: <?= formatDate($ploiCfg['last_sync_at']) ?></p><?php endif ?>
                <?php if ($ploiStats['last_error']): ?><p class="text-red-600">Last error: <?= e($ploiStats['last_error']['error_message']) ?></p><?php endif ?>
            </div>
        </div>
    </div>
</div>
