<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Expenses</h1>
        <a href="/expenses/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            + Add Expense
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label for="category" class="block text-xs text-slate-500 mb-1">Category</label>
            <select id="category" name="category" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Categories</option>
                <?php foreach ($categories as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $category === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="client_id" class="block text-xs text-slate-500 mb-1">Client</label>
            <select id="client_id" name="client_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Clients</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $clientId == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <div>
            <label for="server_id" class="block text-xs text-slate-500 mb-1">Server</label>
            <select id="server_id" name="server_id" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Servers</option>
                <?php foreach ($servers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $serverId == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">Filter</button>
        <?php if ($category || $clientId || $serverId): ?>
            <a href="/expenses" class="text-xs text-slate-400 hover:text-slate-700 self-center">Clear</a>
        <?php endif ?>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Name</th>
                        <th class="px-4 py-2.5 text-left">Category</th>
                        <th class="px-4 py-2.5 text-right">Amount</th>
                        <th class="px-4 py-2.5 text-left">Cycle</th>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-left">Server</th>
                        <th class="px-4 py-2.5 text-left">Date</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($expenses as $exp): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium"><?= e($exp['name']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($categories[$exp['category']] ?? $exp['category']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= money($exp['amount']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= ucfirst($exp['billing_cycle'] ?: '—') ?></td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <?php if ($exp['client_id']): ?>
                                    <a href="/clients/<?= $exp['client_id'] ?>" class="hover:text-accent-600"><?= e($exp['client_name']) ?></a>
                                <?php else: ?><span class="text-slate-300">—</span><?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($exp['server_name'] ?: '—') ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= formatDate($exp['date']) ?></td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="/expenses/<?= $exp['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/expenses/<?= $exp['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this expense?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($expenses)): ?>
                        <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No expenses found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
