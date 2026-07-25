<div class="space-y-5">
    <div>
        <h1 class="text-base font-semibold text-slate-800">Authorise access to your CRM</h1>
        <p class="text-sm text-slate-500 mt-2">
            <span class="font-medium text-slate-700"><?= e($request['client_name']) ?></span>
            is requesting access to your CRM data via MCP.
        </p>
    </div>

    <div class="bg-slate-50 border border-slate-200 rounded p-3 text-xs text-slate-600 space-y-1.5">
        <p class="font-medium text-slate-700">This connection will be able to:</p>
        <ul class="list-disc ml-4 space-y-0.5">
            <li>Read clients, P&amp;L, agreements/SLAs, domains, and renewals</li>
            <li>Log SLA work hours and append notes to clients</li>
        </ul>
        <p class="text-slate-400 pt-1">It cannot delete or edit existing records. You can revoke access at any time under Settings → MCP Access.</p>
    </div>

    <p class="text-xs text-slate-400 break-all">Redirects to: <?= e($request['redirect_uri']) ?></p>

    <form method="POST" action="/oauth/approve" class="flex items-center gap-3">
        <?= csrfField() ?>
        <button type="submit" name="decision" value="approve"
                class="flex-1 px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
            Approve
        </button>
        <button type="submit" name="decision" value="deny"
                class="flex-1 px-4 py-2 border border-slate-300 text-slate-600 text-sm rounded hover:bg-slate-50">
            Deny
        </button>
    </form>
</div>
