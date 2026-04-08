<div class="max-w-2xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">Ploi Settings</h1>

    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <form method="POST" action="/settings/ploi" class="space-y-3">
            <label class="block text-sm font-medium text-slate-700">API Token (Bearer)</label>
            <input type="password" name="api_token" value="<?= e($ploiCfg['api_token'] ?? '') ?>" class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono">
            <button class="px-4 py-2 bg-accent-600 text-white text-sm rounded">Save Token</button>
        </form>

        <div class="flex gap-2">
            <form method="POST" action="/settings/ploi/test"><button class="px-3 py-1.5 border rounded text-sm">Test Connection</button></form>
            <form method="POST" action="/settings/ploi/sync"><button class="px-3 py-1.5 border rounded text-sm">Sync Now</button></form>
            <form method="POST" action="/settings/ploi/disconnect"><button class="px-3 py-1.5 border rounded text-sm text-red-600">Disconnect</button></form>
        </div>

        <p class="text-xs text-slate-500">Status: <?= $connected ? 'Connected' : 'Not connected' ?>. Last sync: <?= !empty($ploiCfg['last_sync_at']) ? formatDate($ploiCfg['last_sync_at']) : '—' ?></p>
        <?php if ($lastError): ?>
            <p class="text-xs text-red-600">Last sync error: <?= e($lastError['error_message']) ?></p>
        <?php endif ?>
    </div>
</div>
