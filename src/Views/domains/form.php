<?php $isEdit = !empty($domain['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Domain' : 'Add Domain' ?>
        <span class="text-slate-400 font-normal text-base">— <?= e($client['name']) ?></span>
    </h1>

    <form method="POST"
          action="<?= $isEdit ? "/clients/{$client['id']}/domains/{$domain['id']}" : "/clients/{$client['id']}/domains" ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

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
                <label for="renewal_years" class="block text-sm font-medium text-slate-700 mb-1">Renewal Period</label>
                <select id="renewal_years" name="renewal_years"
                        class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 bg-white">
                    <?php for ($y = 1; $y <= 10; $y++): ?>
                        <option value="<?= $y ?>" <?= (int)($domain['renewal_years'] ?? 1) === $y ? 'selected' : '' ?>><?= $y ?> year<?= $y > 1 ? 's' : '' ?></option>
                    <?php endfor ?>
                </select>
            </div>
        </div>

        <?php $annualCost = $domain['annual_cost'] ?? null; ?>
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="annual_cost" class="block text-sm font-medium text-slate-700 mb-1">My Cost <span class="text-slate-400 font-normal text-xs">(per renewal)</span></label>
                <div class="flex gap-2">
                    <select name="currency" class="border border-slate-300 rounded px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 bg-white">
                        <?php foreach (['GBP' => '£ GBP', 'USD' => '$ USD', 'EUR' => '€ EUR'] as $code => $label): ?>
                            <option value="<?= $code ?>" <?= ($domain['currency'] ?? 'GBP') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach ?>
                    </select>
                    <input type="number" id="annual_cost" name="annual_cost" step="0.01" min="0"
                           value="<?= $annualCost !== null && $annualCost !== '' ? number_format((float)$annualCost, 2) : '' ?>"
                           class="flex-1 border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                </div>
            </div>
            <div>
                <?php $clientCharge = $domain['client_charge'] ?? null; ?>
                <label for="client_charge" class="block text-sm font-medium text-slate-700 mb-1">Client Charge <span class="text-slate-400 font-normal text-xs">(per renewal)</span></label>
                <div class="flex gap-2">
                    <select name="client_charge_currency" class="border border-slate-300 rounded px-2 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500 bg-white">
                        <?php foreach (['GBP' => '£ GBP', 'USD' => '$ USD', 'EUR' => '€ EUR'] as $code => $label): ?>
                            <option value="<?= $code ?>" <?= ($domain['client_charge_currency'] ?? 'GBP') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach ?>
                    </select>
                    <input type="number" id="client_charge" name="client_charge" step="0.01" min="0"
                           value="<?= $clientCharge !== null && $clientCharge !== '' ? number_format((float)$clientCharge, 2) : '' ?>"
                           class="flex-1 border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Domain' ?>
            </button>
            <a href="/clients/<?= $client['id'] ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
