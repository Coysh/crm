<?php $isEdit = !empty($server['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit ' . e($server['name']) : 'Add Server' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/servers/' . $server['id'] : '/servers' ?>" class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Server Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($server['name'] ?? '') ?>"
                   placeholder="e.g. Hetzner CX21"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="provider" class="block text-sm font-medium text-slate-700 mb-1">Provider</label>
            <input type="text" id="provider" name="provider" value="<?= e($server['provider'] ?? '') ?>"
                   placeholder="e.g. Hetzner, Homelab, SiteGround"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <div>
            <label for="monthly_cost" class="block text-sm font-medium text-slate-700 mb-1">Monthly Cost (£)</label>
            <input type="number" id="monthly_cost" name="monthly_cost" step="0.01" min="0"
                   value="<?= number_format((float)($server['monthly_cost'] ?? 0), 2) ?>"
                   class="w-full border <?= isset($errors['monthly_cost']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['monthly_cost'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['monthly_cost']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($server['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Create Server' ?>
            </button>
            <a href="/servers" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
