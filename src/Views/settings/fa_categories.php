<div class="max-w-2xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">Category Mapping</h1>

    <?php if (!$faIncomeCategories && !$faExpenseCategories): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center text-sm text-slate-400">
            No FreeAgent categories found yet.
            <a href="/freeagent" class="text-accent-600 hover:underline ml-1">Run a sync first to populate categories.</a>
        </div>
    <?php else: ?>

    <form method="POST" action="/settings/freeagent/categories" class="space-y-6">

        <!-- Income categories -->
        <?php if ($faIncomeCategories): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50">
                <h2 class="text-sm font-semibold text-slate-700">Invoice Categories → CRM Income Categories</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">FreeAgent Category</th>
                        <th class="px-4 py-2.5 text-left">Maps to CRM Income Category</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($faIncomeCategories as $faCat): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-mono text-xs"><?= e($faCat) ?></td>
                            <td class="px-4 py-2.5">
                                <select name="income[<?= e($faCat) ?>]"
                                        class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                                    <option value="">— Unmapped —</option>
                                    <?php foreach ($incomeCategories as $val => $label): ?>
                                        <option value="<?= $val ?>"
                                            <?= ($mappingIndex['income:' . $faCat] ?? '') === $val ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>

        <!-- Expense categories -->
        <?php if ($faExpenseCategories): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200 bg-slate-50">
                <h2 class="text-sm font-semibold text-slate-700">Bank Transaction Categories → CRM Expense Categories</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">FreeAgent Category</th>
                        <th class="px-4 py-2.5 text-left">Maps to CRM Expense Category</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($faExpenseCategories as $faCat): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-mono text-xs"><?= e($faCat) ?></td>
                            <td class="px-4 py-2.5">
                                <select name="expense[<?= e($faCat) ?>]"
                                        class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                                    <option value="">— Unmapped —</option>
                                    <?php foreach ($expenseCategories as $val => $label): ?>
                                        <option value="<?= $val ?>"
                                            <?= ($mappingIndex['expense:' . $faCat] ?? '') === $val ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>

        <div>
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                Save Mappings
            </button>
        </div>

    </form>

    <?php endif ?>

</div>
