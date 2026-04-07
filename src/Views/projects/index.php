<?php $categories = \CoyshCRM\Models\Project::incomeCategories(); ?>

<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Projects</h1>
        <a href="/projects/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            + Add Project
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap gap-3 items-end">
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
            <label for="status" class="block text-xs text-slate-500 mb-1">Status</label>
            <select id="status" name="status" class="border border-slate-300 rounded px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">All Statuses</option>
                <?php foreach (\CoyshCRM\Models\Project::statuses() as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $status === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>
        <button type="submit" class="px-3 py-1.5 border border-slate-300 rounded text-sm hover:bg-slate-50">Filter</button>
        <?php if ($clientId || $status): ?>
            <a href="/projects" class="text-xs text-slate-400 hover:text-slate-700 self-center">Clear</a>
        <?php endif ?>
    </form>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Project</th>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-left">Category</th>
                        <th class="px-4 py-2.5 text-right">Income</th>
                        <th class="px-4 py-2.5 text-left">Dates</th>
                        <th class="px-4 py-2.5 text-center">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($projects as $p): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium"><?= e($p['name']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <a href="/clients/<?= $p['client_id'] ?>" class="hover:text-accent-600"><?= e($p['client_name']) ?></a>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($categories[$p['income_category']] ?? $p['income_category']) ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= money($p['income']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500 text-xs">
                                <?= formatDate($p['start_date']) ?>
                                <?php if ($p['end_date']): ?> → <?= formatDate($p['end_date']) ?><?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= statusBadge($p['status']) ?>"><?= ucfirst($p['status']) ?></span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="/projects/<?= $p['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/projects/<?= $p['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete this project?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($projects)): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No projects found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
