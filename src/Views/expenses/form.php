<?php $isEdit = !empty($expense['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Expense' : 'Add Expense' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/expenses/' . $expense['id'] : '/expenses' ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($expense['name'] ?? '') ?>"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="category" class="block text-sm font-medium text-slate-700 mb-1">Category <span class="text-red-500">*</span></label>
                <select id="category" name="category" class="w-full border <?= isset($errors['category']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($expense['category'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (isset($errors['category'])): ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['category']) ?></p>
                <?php endif ?>
            </div>
            <div>
                <label for="billing_cycle" class="block text-sm font-medium text-slate-700 mb-1">Billing Cycle</label>
                <select id="billing_cycle" name="billing_cycle" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <?php foreach ($cycles as $val => $label): ?>
                        <option value="<?= $val ?>" <?= ($expense['billing_cycle'] ?? 'one_off') === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="amount" class="block text-sm font-medium text-slate-700 mb-1">Amount (£) <span class="text-red-500">*</span></label>
                <input type="number" id="amount" name="amount" step="0.01" min="0"
                       value="<?= number_format((float)($expense['amount'] ?? 0), 2) ?>"
                       class="w-full border <?= isset($errors['amount']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php if (isset($errors['amount'])): ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['amount']) ?></p>
                <?php endif ?>
            </div>
            <div>
                <label for="date" class="block text-sm font-medium text-slate-700 mb-1">Date</label>
                <input type="date" id="date" name="date" value="<?= e($expense['date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <p class="text-xs text-slate-400 font-medium uppercase tracking-wide">Link to (optional)</p>

        <div class="grid grid-cols-3 gap-4">
            <div>
                <label for="client_id" class="block text-sm font-medium text-slate-700 mb-1">Client</label>
                <select id="client_id" name="client_id" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— None —</option>
                    <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= ($expense['client_id'] ?? null) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label for="server_id" class="block text-sm font-medium text-slate-700 mb-1">Server</label>
                <select id="server_id" name="server_id" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— None —</option>
                    <?php foreach ($servers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($expense['server_id'] ?? null) == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label for="project_id" class="block text-sm font-medium text-slate-700 mb-1">Project</label>
                <select id="project_id" name="project_id" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— None —</option>
                    <?php foreach ($projects as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= ($expense['project_id'] ?? null) == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($expense['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Expense' ?>
            </button>
            <a href="/expenses" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
