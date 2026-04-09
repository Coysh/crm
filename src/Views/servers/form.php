<?php $isEdit = !empty($server['id']); ?>

<div class="max-w-3xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800"><?= $isEdit ? 'Edit ' . e($server['name']) : 'Add Server' ?></h1>

    <form method="POST" action="<?= $isEdit ? '/servers/' . $server['id'] : '/servers' ?>" class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Server Name *</label>
            <input type="text" name="name" value="<?= e($server['name'] ?? '') ?>" class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Provider</label>
            <input type="text" name="provider" value="<?= e($server['provider'] ?? '') ?>" class="w-full border rounded px-3 py-2 text-sm">
        </div>
        <?php if ($ploiConnected): ?>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Ploi Server</label>
            <select name="ploi_server_id" class="w-full border rounded px-3 py-2 text-sm">
                <option value="">None</option>
                <?php $current = $ploiData['id'] ?? null; foreach ($ploiOptions as $ps): ?>
                    <option value="<?= $ps['id'] ?>" <?= (string)$current === (string)$ps['id'] ? 'selected' : '' ?>><?= e($ps['name']) ?><?= $ps['is_stale'] ? ' (stale)' : '' ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <?php endif ?>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea name="notes" rows="3" class="w-full border rounded px-3 py-2 text-sm"><?= e($server['notes'] ?? '') ?></textarea>
        </div>

        <button class="px-4 py-2 bg-accent-600 text-white text-sm rounded"><?= $isEdit ? 'Save Changes' : 'Create Server' ?></button>
    </form>

    <?php if ($isEdit): ?>
        <!-- Hosting Cost (via Recurring Costs) -->
        <div class="bg-white border border-slate-200 rounded-lg p-5">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold text-slate-700">Hosting Cost</h2>
                <?php if (!empty($linkedCost)): ?>
                    <a href="/expenses/recurring/<?= $linkedCost['id'] ?>/edit" class="text-xs text-accent-600 hover:underline">Edit recurring cost</a>
                <?php else: ?>
                    <a href="/expenses/recurring/create?for_server=<?= $server['id'] ?>" class="text-xs text-accent-600 hover:underline">+ Add recurring cost</a>
                <?php endif ?>
            </div>
            <?php if (!empty($linkedCost)): ?>
                <?php $monthlyCost = ($linkedCost['billing_cycle'] ?? 'monthly') === 'annual' ? $linkedCost['amount'] / 12.0 : $linkedCost['amount']; ?>
                <p class="text-sm text-slate-700">
                    <span class="font-medium"><?= money($monthlyCost) ?>/mo</span>
                    <span class="text-slate-400 mx-1">·</span>
                    <span class="font-medium"><?= money($monthlyCost * 12) ?>/yr</span>
                    <?php if (($linkedCost['billing_cycle'] ?? '') === 'annual'): ?>
                        <span class="text-xs text-slate-400 ml-1">(<?= money($linkedCost['amount']) ?>/yr ÷ 12)</span>
                    <?php endif ?>
                    <span class="text-slate-400 ml-2">— <?= e($linkedCost['name']) ?></span>
                </p>
                <p class="text-xs text-slate-400 mt-0.5">Category: <?= e($linkedCost['category_name']) ?></p>
            <?php else: ?>
                <p class="text-sm text-slate-400">No hosting cost set.
                    <a href="/expenses/recurring/create?for_server=<?= $server['id'] ?>" class="text-accent-600 hover:underline">Add recurring cost</a>
                </p>
            <?php endif ?>
        </div>

        <div class="bg-white border border-slate-200 rounded-lg p-5">
            <h2 class="text-sm font-semibold text-slate-700 mb-3">Ploi Server Data</h2>
            <?php if ($ploiData): ?>
                <p class="text-sm">IP: <?= e($ploiData['ip_address'] ?: '—') ?> · Provider: <?= e($ploiData['provider'] ?: '—') ?> · Region: <?= e($ploiData['region'] ?: '—') ?></p>
                <p class="text-sm">Status: <?= e($ploiData['status'] ?: '—') ?><?= $ploiData['is_stale'] ? ' (stale in Ploi)' : '' ?></p>
                <p class="text-sm">PHP: <?php $vers = json_decode($ploiData['php_versions'] ?? '[]', true) ?: []; foreach($vers as $v){ echo e($v) . (($ploiData['php_cli_version']??'')===$v?' [CLI]':'') . ' '; } ?></p>
                <p class="text-sm">Sites on this server: <?= (int)$ploiData['site_count'] ?></p>
                <ul class="text-xs text-slate-500 list-disc ml-4 mt-2"><?php foreach (($ploiData['sites'] ?? []) as $site): ?><li><?= e($site['domain']) ?> (<?= e($site['project_type'] ?: 'unknown') ?>)</li><?php endforeach ?></ul>
                <p class="text-xs text-slate-400 mt-2">Last synced: <?= formatDate($ploiData['last_synced_at'] ?? null) ?></p>
            <?php else: ?>
                <p class="text-sm text-slate-400">Link a Ploi server in settings.</p>
            <?php endif ?>
        </div>
    <?php endif ?>
</div>
