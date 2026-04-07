<?php $isEdit = !empty($package['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Package' : 'Add Package' ?>
        <span class="text-slate-400 font-normal text-base">— <?= e($client['name']) ?></span>
    </h1>

    <form method="POST"
          action="<?= $isEdit ? "/clients/{$client['id']}/packages/{$package['id']}" : "/clients/{$client['id']}/packages" ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Package Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($package['name'] ?? '') ?>"
                   placeholder="e.g. Hosting & Support"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="fee" class="block text-sm font-medium text-slate-700 mb-1">Fee (£) <span class="text-red-500">*</span></label>
                <input type="number" id="fee" name="fee" step="0.01" min="0"
                       value="<?= number_format((float)($package['fee'] ?? 0), 2) ?>"
                       class="w-full border <?= isset($errors['fee']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php if (isset($errors['fee'])): ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['fee']) ?></p>
                <?php endif ?>
            </div>
            <div>
                <label for="billing_cycle" class="block text-sm font-medium text-slate-700 mb-1">Billing Cycle</label>
                <select id="billing_cycle" name="billing_cycle" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="monthly" <?= ($package['billing_cycle'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="annual"  <?= ($package['billing_cycle'] ?? '') === 'annual' ? 'selected' : '' ?>>Annual</option>
                </select>
            </div>
        </div>

        <div>
            <label for="renewal_date" class="block text-sm font-medium text-slate-700 mb-1">Renewal Date</label>
            <input type="date" id="renewal_date" name="renewal_date" value="<?= e($package['renewal_date'] ?? '') ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($package['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-3">
            <input type="checkbox" id="is_active" name="is_active" value="1"
                   <?= ($package['is_active'] ?? 1) ? 'checked' : '' ?>
                   class="rounded border-slate-300 text-accent-600">
            <label for="is_active" class="text-sm text-slate-700">Active</label>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Package' ?>
            </button>
            <a href="/clients/<?= $client['id'] ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
