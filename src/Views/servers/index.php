<div class="space-y-4">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Servers</h1>
        <a href="/servers/create" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            + Add Server
        </a>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Server</th>
                        <th class="px-4 py-2.5 text-left">Provider</th>
                        <th class="px-4 py-2.5 text-right">Monthly Cost</th>
                        <th class="px-4 py-2.5 text-center">Sites</th>
                        <th class="px-4 py-2.5 text-center">Clients</th>
                        <th class="px-4 py-2.5 text-right">Cost / Client</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($servers as $s): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-medium"><?= e($s['name']) ?></td>
                            <td class="px-4 py-2.5 text-slate-500"><?= e($s['provider'] ?: '—') ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums"><?= money($s['monthly_cost']) ?></td>
                            <td class="px-4 py-2.5 text-center tabular-nums"><?= (int)$s['site_count'] ?></td>
                            <td class="px-4 py-2.5 text-center tabular-nums"><?= (int)$s['client_count'] ?></td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-500"><?= money($s['cost_per_client']) ?></td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="/servers/<?= $s['id'] ?>/edit" class="text-xs text-slate-400 hover:text-slate-700 mr-2">Edit</a>
                                <form method="POST" action="/servers/<?= $s['id'] ?>/delete" class="inline">
                                    <button type="submit" onclick="return confirm('Delete server \'<?= e(addslashes($s['name'])) ?>\'?')" class="text-xs text-red-400 hover:text-red-600">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    <?php if (empty($servers)): ?>
                        <tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No servers yet.</td></tr>
                    <?php endif ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
