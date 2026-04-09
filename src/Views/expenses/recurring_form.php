<?php
$isEdit     = !empty($cost['id']);
$isServerLinked = !empty($cost['server_id']) || !empty($cost['_for_server']);
$assignmentType = 'none';
if (!$isServerLinked) {
    if (!empty($cost['client_assignments'])) $assignmentType = 'client';
    elseif (!empty($cost['site_assignments'])) $assignmentType = 'site';
}
$assignedClientIds = $cost['client_assignments'] ?? [];
$assignedSiteIds   = $cost['site_assignments']   ?? [];
$forServer  = (int)($cost['_for_server'] ?? 0);
$fromBill   = (int)($_GET['from_bill'] ?? 0);
?>

<div class="max-w-2xl space-y-6">
    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Recurring Cost' : 'Add Recurring Cost' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/expenses/recurring/' . $cost['id'] : '/expenses/recurring' ?>"
          class="space-y-5 bg-white border border-slate-200 rounded-lg p-6">

        <div class="grid grid-cols-2 gap-4">
            <div class="col-span-2">
                <label class="block text-sm font-medium text-slate-700 mb-1">Name <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="<?= e($cost['name'] ?? '') ?>"
                       class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php if (isset($errors['name'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p><?php endif ?>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Category <span class="text-red-500">*</span></label>
                <select name="category_id" class="w-full border <?= isset($errors['category_id']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $catId => $catName): ?>
                        <option value="<?= $catId ?>" <?= ($cost['category_id'] ?? null) == $catId ? 'selected' : '' ?>><?= e($catName) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (isset($errors['category_id'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['category_id']) ?></p><?php endif ?>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Provider</label>
                <input type="text" name="provider" value="<?= e($cost['provider'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"
                       placeholder="e.g. WP Engine, Mailgun">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Amount <span class="text-red-500">*</span></label>
                <div class="flex gap-2">
                    <select name="currency" class="border border-slate-300 rounded px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 bg-white">
                        <?php foreach (['GBP' => '£ GBP', 'USD' => '$ USD', 'EUR' => '€ EUR'] as $code => $label): ?>
                            <option value="<?= $code ?>" <?= ($cost['currency'] ?? 'GBP') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach ?>
                    </select>
                    <input type="number" name="amount" step="0.01" min="0" value="<?= number_format((float)($cost['amount'] ?? 0), 2) ?>"
                           class="flex-1 border <?= isset($errors['amount']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                </div>
                <?php if (isset($errors['amount'])): ?><p class="text-xs text-red-600 mt-1"><?= e($errors['amount']) ?></p><?php endif ?>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Billing Cycle</label>
                <select name="billing_cycle" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="monthly" <?= ($cost['billing_cycle'] ?? 'monthly') === 'monthly' ? 'selected' : '' ?>>Monthly</option>
                    <option value="annual"  <?= ($cost['billing_cycle'] ?? 'monthly') === 'annual'  ? 'selected' : '' ?>>Annual</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Renewal Date</label>
                <input type="date" name="renewal_date" value="<?= e($cost['renewal_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">URL</label>
                <input type="url" name="url" value="<?= e($cost['url'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"
                       placeholder="https://...">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea name="notes" rows="2" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($cost['notes'] ?? '') ?></textarea>
        </div>

        <label class="flex items-center gap-2 text-sm text-slate-600 cursor-pointer">
            <input type="checkbox" name="is_active" value="1" <?= ($cost['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded border-slate-300 text-accent-600 focus:ring-accent-500">
            Active
        </label>

        <!-- Hidden fields for server/bill linking -->
        <?php if ($forServer): ?><input type="hidden" name="_for_server" value="<?= $forServer ?>"><?php endif ?>
        <?php if ($fromBill):  ?><input type="hidden" name="_from_bill"  value="<?= $fromBill  ?>"><?php endif ?>

        <!-- Assignment Section -->
        <div class="border-t border-slate-200 pt-4 space-y-3">
            <p class="text-sm font-medium text-slate-700">Assign to</p>

            <?php if ($isServerLinked): ?>
                <p class="text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded px-3 py-2">
                    This cost is linked to a server — apportionment is calculated automatically
                    based on which clients have sites on that server.
                </p>
                <input type="hidden" name="assignment_type" value="none">
            <?php else: ?>
            <div class="flex gap-4 text-sm">
                <?php foreach (['none' => 'Unassigned (general overhead)', 'client' => 'Specific clients', 'site' => 'Specific sites'] as $atype => $alabel): ?>
                    <label class="flex items-center gap-1.5 cursor-pointer">
                        <input type="radio" name="assignment_type" value="<?= $atype ?>"
                               class="text-accent-600 focus:ring-accent-500"
                               onchange="showAssignment('<?= $atype ?>')"
                               <?= $assignmentType === $atype ? 'checked' : '' ?>>
                        <?= $alabel ?>
                    </label>
                <?php endforeach ?>
            </div>

            <!-- Client checkboxes -->
            <div id="assign-client" class="<?= $assignmentType === 'client' ? '' : 'hidden' ?> max-h-56 overflow-y-auto border border-slate-200 rounded p-3 space-y-1.5">
                <?php foreach ($clients as $c): ?>
                    <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-slate-50 px-1 rounded">
                        <input type="checkbox" name="assignment_ids[]" value="<?= $c['id'] ?>"
                               class="rounded border-slate-300 text-accent-600 focus:ring-accent-500"
                               <?= in_array((string)$c['id'], array_map('strval', $assignedClientIds)) ? 'checked' : '' ?>>
                        <?= e($c['name']) ?>
                    </label>
                <?php endforeach ?>
                <?php if (empty($clients)): ?><p class="text-xs text-slate-400">No active clients.</p><?php endif ?>
            </div>

            <!-- Site checkboxes (grouped by client) -->
            <div id="assign-site" class="<?= $assignmentType === 'site' ? '' : 'hidden' ?> max-h-64 overflow-y-auto border border-slate-200 rounded p-3 space-y-2">
                <?php foreach ($sites as $clientName => $sitesGroup): ?>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-wide mt-1"><?= e($clientName) ?></p>
                    <?php foreach ($sitesGroup as $s): ?>
                        <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-slate-50 px-1 rounded ml-2">
                            <input type="checkbox" name="assignment_ids[]" value="<?= $s['id'] ?>"
                                   class="rounded border-slate-300 text-accent-600 focus:ring-accent-500"
                                   <?= in_array((string)$s['id'], array_map('strval', $assignedSiteIds)) ? 'checked' : '' ?>>
                            <?= e($s['domain_label']) ?>
                        </label>
                    <?php endforeach ?>
                <?php endforeach ?>
                <?php if (empty($sites)): ?><p class="text-xs text-slate-400">No sites.</p><?php endif ?>
            </div>
            <?php endif ?>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Recurring Cost' ?>
            </button>
            <a href="/expenses?tab=recurring" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>

<script>
function showAssignment(type) {
    document.getElementById('assign-client').classList.toggle('hidden', type !== 'client');
    document.getElementById('assign-site').classList.toggle('hidden', type !== 'site');
    // Uncheck all checkboxes in the hidden panels to avoid submitting stale data
    if (type !== 'client') document.querySelectorAll('#assign-client input[type=checkbox]').forEach(cb => cb.checked = false);
    if (type !== 'site')   document.querySelectorAll('#assign-site input[type=checkbox]').forEach(cb => cb.checked = false);
}
</script>
