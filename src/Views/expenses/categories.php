<div class="max-w-xl space-y-5">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Expense Categories</h1>
        <a href="/expenses" class="text-sm text-slate-500 hover:text-slate-800">← Back to Expenses</a>
    </div>

    <!-- Add category form -->
    <form method="POST" action="/expenses/categories" class="flex items-end gap-2 bg-white border border-slate-200 rounded-lg p-4">
        <div class="flex-1">
            <label class="block text-xs text-slate-500 mb-1">New Category Name</label>
            <input type="text" name="name" placeholder="e.g. CDN Services"
                   class="w-full border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>
        <button type="submit" class="px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700 shrink-0">
            Add Category
        </button>
    </form>

    <!-- Categories list -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-2.5 text-left">Name</th>
                    <th class="px-4 py-2.5 text-left">Slug</th>
                    <th class="px-4 py-2.5 text-right">Expenses</th>
                    <th class="px-4 py-2.5 text-right">Recurring</th>
                    <th class="px-4 py-2.5"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <?php foreach ($categories as $cat): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 font-medium">
                            <form method="POST" action="/expenses/categories/<?= $cat['id'] ?>" class="flex items-center gap-2">
                                <input type="text" name="name" value="<?= e($cat['name']) ?>"
                                       class="border border-transparent hover:border-slate-300 focus:border-slate-400 rounded px-2 py-0.5 text-sm focus:outline-none w-48">
                                <button type="submit" class="text-xs text-accent-600 hover:underline">Save</button>
                            </form>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs text-slate-500"><?= e($cat['slug']) ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500"><?= (int)$cat['expense_count'] ?></td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-slate-500"><?= (int)$cat['recurring_count'] ?></td>
                        <td class="px-4 py-2.5 text-right">
                            <?php if (!$cat['is_default'] && !$cat['expense_count'] && !$cat['recurring_count']): ?>
                                <form method="POST" action="/expenses/categories/<?= $cat['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this category?')"
                                            class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            <?php else: ?>
                                <span class="text-xs text-slate-300"><?= $cat['is_default'] ? 'Default' : 'In use' ?></span>
                            <?php endif ?>
                        </td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    </div>

</div>
