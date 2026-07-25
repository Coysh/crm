<div class="max-w-2xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">MCP Access</h1>

    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Connect Claude to your CRM</h2>
        <p class="text-sm text-slate-500">Add this URL as a custom connector in Claude (Settings → Connectors) on web or mobile. You'll be asked to sign in to the CRM and approve access.</p>
        <p class="font-mono text-sm bg-slate-50 border border-slate-200 rounded px-3 py-2 select-all"><?= e($mcpUrl) ?></p>
        <p class="text-xs text-slate-400">Claude can read clients, P&amp;L, agreements/SLAs, domains, and renewals, log SLA work, and append client notes. It cannot delete or edit records.</p>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200">
            <h2 class="text-sm font-semibold text-slate-700">Connected Applications</h2>
        </div>
        <?php if ($clients): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500 uppercase tracking-wide bg-slate-50">
                        <tr>
                            <th class="px-4 py-2 text-left">Application</th>
                            <th class="px-4 py-2 text-left">Registered</th>
                            <th class="px-4 py-2 text-left">Last Used</th>
                            <th class="px-4 py-2 text-right">Active Grants</th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($clients as $c): ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-slate-700"><?= e($c['client_name'] ?: 'Unnamed application') ?></span>
                                    <span class="block text-xs text-slate-400 font-mono"><?= e(substr($c['client_id'], 0, 12)) ?>…</span>
                                </td>
                                <td class="px-4 py-2.5 text-xs text-slate-500"><?= formatDate($c['created_at']) ?></td>
                                <td class="px-4 py-2.5 text-xs text-slate-500"><?= $c['last_used_at'] ? formatDate($c['last_used_at']) : '—' ?></td>
                                <td class="px-4 py-2.5 text-right tabular-nums"><?= (int)$c['active_grants'] ?></td>
                                <td class="px-4 py-2.5 text-right">
                                    <form method="POST" action="/settings/mcp/clients/<?= (int)$c['id'] ?>/revoke"
                                          onsubmit="return confirm('Revoke all access for this application? It will need to reconnect and be re-approved.')">
                                        <?= csrfField() ?>
                                        <button class="text-xs text-red-500 hover:text-red-700">Revoke</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class="px-5 py-6 text-sm text-slate-400">No applications connected yet.</p>
        <?php endif ?>
    </div>
</div>
