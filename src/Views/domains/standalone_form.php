<?php $isEdit = !empty($domain['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Domain' : 'Add Domain' ?>
    </h1>

    <form method="POST"
          action="<?= $isEdit ? "/domains/{$domain['id']}" : '/domains' ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <div>
            <label for="client_id" class="block text-sm font-medium text-slate-700 mb-1">Client <span class="text-slate-400 font-normal">(optional)</span></label>
            <select id="client_id" name="client_id"
                    class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 bg-white">
                <option value="">— No client —</option>
                <?php foreach ($clients as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (int)($domain['client_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <div>
            <label for="domain" class="block text-sm font-medium text-slate-700 mb-1">Domain Name <span class="text-red-500">*</span></label>
            <input type="text" id="domain" name="domain" value="<?= e($domain['domain'] ?? '') ?>"
                   placeholder="example.co.uk"
                   class="w-full border <?= isset($errors['domain']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['domain'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['domain']) ?></p>
            <?php endif ?>
        </div>

        <div>
            <label for="registrar" class="block text-sm font-medium text-slate-700 mb-1">Registrar</label>
            <input type="text" id="registrar" name="registrar" value="<?= e($domain['registrar'] ?? '') ?>"
                   placeholder="e.g. Cloudflare, Namecheap, 123-reg"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <div class="flex items-center gap-3">
            <input type="checkbox" id="cloudflare_proxied" name="cloudflare_proxied" value="1"
                   <?= ($domain['cloudflare_proxied'] ?? 0) ? 'checked' : '' ?>
                   class="rounded border-slate-300 text-accent-600">
            <label for="cloudflare_proxied" class="text-sm text-slate-700">Cloudflare Proxied</label>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="renewal_date" class="block text-sm font-medium text-slate-700 mb-1">Renewal Date</label>
                <input type="date" id="renewal_date" name="renewal_date" value="<?= e($domain['renewal_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div>
                <label for="annual_cost" class="block text-sm font-medium text-slate-700 mb-1">Annual Cost</label>
                <div class="flex gap-2">
                    <select name="currency" class="border border-slate-300 rounded px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 bg-white">
                        <?php foreach (['GBP' => '£ GBP', 'USD' => '$ USD', 'EUR' => '€ EUR'] as $code => $label): ?>
                            <option value="<?= $code ?>" <?= ($domain['currency'] ?? 'GBP') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach ?>
                    </select>
                    <input type="number" id="annual_cost" name="annual_cost" step="0.01" min="0"
                           value="<?= $domain['annual_cost'] !== null && $domain['annual_cost'] !== '' ? number_format((float)$domain['annual_cost'], 2) : '' ?>"
                           class="flex-1 border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Domain' ?>
            </button>
            <a href="<?= $isEdit ? "/domains/{$domain['id']}" : '/domains' ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>

    <?php if ($isEdit && ($domain['client_id'] ?? null)): ?>
    <!-- Charge client for this domain -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Charge Client for This Domain</h2>
        <?php if (!empty($linkedRecurringCost)): ?>
            <div class="flex items-center gap-3 text-sm">
                <span class="text-green-500 font-bold">✓</span>
                <span class="text-slate-700">Linked to recurring cost: <strong><?= e($linkedRecurringCost['name']) ?></strong></span>
                <a href="/expenses/recurring/<?= $linkedRecurringCost['id'] ?>/edit" class="text-xs text-accent-600 hover:underline">Edit</a>
            </div>
            <p class="text-xs text-slate-400">
                <?= money($linkedRecurringCost['amount']) ?> / <?= e($linkedRecurringCost['billing_cycle']) ?>
                <?php if ($linkedRecurringCost['renewal_date']): ?>
                    · Renewal: <?= formatDate($linkedRecurringCost['renewal_date']) ?>
                <?php endif ?>
            </p>
        <?php else: ?>
            <p class="text-sm text-slate-500">No recurring cost linked to this domain for this client.</p>
            <form method="POST" action="/domains/<?= $domain['id'] ?>/create-recurring-cost">
                <button type="submit" class="px-3 py-1.5 bg-accent-600 text-white text-sm rounded hover:bg-accent-700"
                        onclick="return confirm('Create a recurring cost for this domain billed to the client?')">
                    Create Recurring Cost for This Domain
                </button>
            </form>
            <p class="text-xs text-slate-400">
                This will create a recurring cost "Domain: <?= e($domain['domain']) ?>"
                with amount <?= $domain['annual_cost'] ? money($domain['annual_cost']) : '£0.00' ?>/year
                <?php if ($domain['renewal_date']): ?>, renewal date <?= formatDate($domain['renewal_date']) ?><?php endif ?>,
                linked to the client.
            </p>
        <?php endif ?>
    </div>
    <?php endif ?>
</div>
