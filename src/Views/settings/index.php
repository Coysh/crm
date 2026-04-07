<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">Settings</h1>

    <!-- FreeAgent card -->
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-sm font-semibold text-slate-700">FreeAgent Integration</h2>
                <p class="text-sm text-slate-500 mt-1">Connect to FreeAgent to pull contacts, invoices, and bank transactions.</p>
            </div>
            <?php if ($connected): ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-green-100 text-green-700 text-xs font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Connected
                </span>
            <?php else: ?>
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-slate-100 text-slate-500 text-xs font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-slate-400"></span> Not connected
                </span>
            <?php endif ?>
        </div>
        <div class="mt-4">
            <a href="/settings/freeagent" class="text-sm text-accent-600 hover:underline">
                <?= $connected ? 'Manage FreeAgent connection →' : 'Set up FreeAgent →' ?>
            </a>
        </div>
    </div>

</div>
