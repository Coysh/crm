<?php $isEdit = !empty($project['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Project' : 'Add Project' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/projects/' . $project['id'] : '/projects' ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <div>
            <label for="client_id" class="block text-sm font-medium text-slate-700 mb-1">Client <span class="text-red-500">*</span></label>
            <select id="client_id" name="client_id" class="w-full border <?= isset($errors['client_id']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">— Select Client —</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($project['client_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
            <?php if (isset($errors['client_id'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['client_id']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Project Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($project['name'] ?? '') ?>"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="income_category" class="block text-sm font-medium text-slate-700 mb-1">Income Category <span class="text-red-500">*</span></label>
                <select id="income_category" name="income_category" class="w-full border <?= isset($errors['income_category']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($project['income_category'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (isset($errors['income_category'])): ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['income_category']) ?></p>
                <?php endif ?>
            </div>
            <div>
                <label for="income" class="block text-sm font-medium text-slate-700 mb-1">Income (£)</label>
                <input type="number" id="income" name="income" step="0.01" min="0"
                       value="<?= number_format((float)($project['income'] ?? 0), 2) ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="start_date" class="block text-sm font-medium text-slate-700 mb-1">Start Date</label>
                <input type="date" id="start_date" name="start_date" value="<?= e($project['start_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div>
                <label for="end_date" class="block text-sm font-medium text-slate-700 mb-1">End Date</label>
                <input type="date" id="end_date" name="end_date" value="<?= e($project['end_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <div>
            <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select id="status" name="status" class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php foreach ($statuses as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($project['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($project['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Create Project' ?>
            </button>
            <a href="/projects" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
