<div class="max-w-4xl space-y-4">
    <h1 class="text-xl font-semibold text-slate-800">Deletion Log</h1>
    <p class="text-sm text-slate-500">Audit trail of permanently deleted clients and entities.</p>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Entity</th>
                        <th class="px-4 py-2.5 text-left">Type</th>
                        <th class="px-4 py-2.5 text-left">Related Data</th>
                        <th class="px-4 py-2.5 text-left">Deleted At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $related = [];
                        if ($row['related_data']) {
                            $related = json_decode($row['related_data'], true) ?: [];
                        }
                        ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium text-slate-800"><?= e($row['entity_name']) ?></td>
                            <td class="px-4 py-2.5">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                    <?= e(ucfirst($row['entity_type'])) ?>
                                </span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500 text-xs">
                                <?php if ($related): ?>
                                    <?php foreach ($related as $k => $v): ?>
                                        <span class="mr-2"><?= e(ucfirst($k)) ?>: <?= (int)$v ?></span>
                                    <?php endforeach ?>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif ?>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500"><?= formatDate($row['deleted_at']) ?></td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400">No deletions logged yet.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-xs text-slate-400"><a href="/settings" class="hover:underline">← Back to Settings</a></p>
</div>
