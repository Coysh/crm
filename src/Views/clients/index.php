<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Clients</h1>
        <a href="/clients/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            + Add Client
        </a>
    </div>

    <!-- Status filter tabs -->
    <div class="flex gap-1 border-b border-slate-200">
        <?php foreach (['active' => 'Active', 'archived' => 'Archived', 'all' => 'All'] as $val => $label): ?>
            <?php $active = $filter === $val ?>
            <a href="?status=<?= $val ?>"
               class="px-4 py-2 text-sm font-medium border-b-2 -mb-px <?= $active ? 'border-accent-600 text-accent-600' : 'border-transparent text-slate-500 hover:text-slate-800' ?>">
                <?= $label ?>
            </a>
        <?php endforeach ?>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Client</th>
                        <th class="px-4 py-2.5 text-left">Contact</th>
                        <th class="px-4 py-2.5 text-center">Sites</th>
                        <th class="px-4 py-2.5 text-right">MRR</th>
                        <th class="px-4 py-2.5 text-center">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($clients as $client): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium">
                                <a href="/clients/<?= $client['id'] ?>" class="text-accent-600 hover:underline"><?= e($client['name']) ?></a>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500">
                                <?php if ($client['contact_name']): ?>
                                    <?= e($client['contact_name']) ?>
                                    <?php if ($client['contact_email']): ?>
                                        <span class="text-xs ml-1">&lt;<?= e($client['contact_email']) ?>&gt;</span>
                                    <?php endif ?>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-center tabular-nums"><?= (int)$client['site_count'] ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-medium"><?= money($client['mrr']) ?></td>
                            <td class="px-4 py-2.5 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= statusBadge($client['status']) ?>">
                                    <?= ucfirst($client['status']) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="/clients/<?= $client['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700">Edit</a>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($clients)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No clients found.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
