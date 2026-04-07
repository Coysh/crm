<?php $isEdit = !empty($client['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit ' . e($client['name']) : 'Add Client' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/clients/' . $client['id'] : '/clients' ?>" class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <!-- Name -->
        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Client Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($client['name'] ?? '') ?>"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <!-- Status -->
        <div>
            <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select id="status" name="status" class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php foreach (['active' => 'Active', 'archived' => 'Archived'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($client['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <!-- Contact name -->
        <div>
            <label for="contact_name" class="block text-sm font-medium text-slate-700 mb-1">Contact Name</label>
            <input type="text" id="contact_name" name="contact_name" value="<?= e($client['contact_name'] ?? '') ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <!-- Contact email -->
        <div>
            <label for="contact_email" class="block text-sm font-medium text-slate-700 mb-1">Contact Email</label>
            <input type="email" id="contact_email" name="contact_email" value="<?= e($client['contact_email'] ?? '') ?>"
                   class="w-full border <?= isset($errors['contact_email']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['contact_email'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['contact_email']) ?></p>
            <?php endif ?>
        </div>

        <!-- Notes -->
        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($client['notes'] ?? '') ?></textarea>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Create Client' ?>
            </button>
            <a href="<?= $isEdit ? '/clients/' . $client['id'] : '/clients' ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
