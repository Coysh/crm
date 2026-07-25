<?php $isEdit = !empty($agreement['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Agreement' : 'Add Agreement' ?>
        <span class="text-slate-400 font-normal text-base">— <?= e($client['name']) ?></span>
    </h1>

    <form method="POST"
          action="<?= $isEdit ? "/clients/{$client['id']}/agreements/{$agreement['id']}" : "/clients/{$client['id']}/agreements" ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">
        <?= csrfField() ?>

        <div>
            <label for="title" class="block text-sm font-medium text-slate-700 mb-1">Title <span class="text-red-500">*</span></label>
            <input type="text" id="title" name="title" value="<?= e($agreement['title'] ?? '') ?>"
                   placeholder="e.g. Website SLA 2026, Website build agreement"
                   class="w-full border <?= isset($errors['title']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['title'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['title']) ?></p>
            <?php endif ?>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="agreement_type" class="block text-sm font-medium text-slate-700 mb-1">Type</label>
                <select id="agreement_type" name="agreement_type"
                        class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <?php foreach (\CoyshCRM\Models\Agreement::TYPES as $value => $label): ?>
                        <option value="<?= $value ?>" <?= ($agreement['agreement_type'] ?? 'support') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status</label>
                <select id="status" name="status"
                        class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <?php foreach (\CoyshCRM\Models\Agreement::STATUSES as $value): ?>
                        <option value="<?= $value ?>" <?= ($agreement['status'] ?? 'active') === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <div>
            <span class="block text-sm font-medium text-slate-700 mb-1">Coverage</span>
            <div class="flex flex-wrap gap-4">
                <?php foreach (['covers_hosting' => 'Hosting', 'covers_support' => 'Support', 'covers_maintenance' => 'Maintenance'] as $field => $label): ?>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input type="checkbox" name="<?= $field ?>" value="1"
                               <?= !empty($agreement[$field]) ? 'checked' : '' ?>
                               class="rounded border-slate-300 text-accent-600">
                        <?= $label ?>
                    </label>
                <?php endforeach ?>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="included_hours" class="block text-sm font-medium text-slate-700 mb-1">Included Hours <span class="text-slate-400 font-normal text-xs">(blank = none)</span></label>
                <input type="number" id="included_hours" name="included_hours" step="0.25" min="0"
                       value="<?= e($agreement['included_hours'] ?? '') ?>"
                       class="w-full border <?= isset($errors['included_hours']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php if (isset($errors['included_hours'])): ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['included_hours']) ?></p>
                <?php endif ?>
            </div>
            <div>
                <label for="hours_period" class="block text-sm font-medium text-slate-700 mb-1">Hours Period</label>
                <select id="hours_period" name="hours_period"
                        class="w-full border <?= isset($errors['hours_period']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">—</option>
                    <?php foreach (\CoyshCRM\Models\Agreement::HOURS_PERIODS as $value): ?>
                        <option value="<?= $value ?>" <?= ($agreement['hours_period'] ?? '') === $value ? 'selected' : '' ?>><?= ucfirst($value) ?></option>
                    <?php endforeach ?>
                </select>
                <?php if (isset($errors['hours_period'])): ?>
                    <p class="text-xs text-red-600 mt-1"><?= e($errors['hours_period']) ?></p>
                <?php endif ?>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="fee_amount" class="block text-sm font-medium text-slate-700 mb-1">Fee</label>
                <div class="flex gap-2">
                    <select name="fee_currency" class="border border-slate-300 rounded px-2 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                        <?php foreach (['GBP' => '£ GBP', 'USD' => '$ USD', 'EUR' => '€ EUR'] as $code => $label): ?>
                            <option value="<?= $code ?>" <?= ($agreement['fee_currency'] ?? 'GBP') === $code ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach ?>
                    </select>
                    <input type="number" id="fee_amount" name="fee_amount" step="0.01" min="0"
                           value="<?= e($agreement['fee_amount'] ?? '') ?>"
                           class="flex-1 border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                </div>
            </div>
            <div>
                <label for="fee_billing_cycle" class="block text-sm font-medium text-slate-700 mb-1">Billing Cycle</label>
                <select id="fee_billing_cycle" name="fee_billing_cycle"
                        class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">—</option>
                    <?php foreach (\CoyshCRM\Models\Agreement::BILLING_CYCLES as $value): ?>
                        <option value="<?= $value ?>" <?= ($agreement['fee_billing_cycle'] ?? '') === $value ? 'selected' : '' ?>><?= ucfirst(str_replace('_', '-', $value)) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <?php if (!empty($recurring)): ?>
        <div>
            <label for="freeagent_recurring_invoice_id" class="block text-sm font-medium text-slate-700 mb-1">
                Billed via FreeAgent Recurring Invoice
                <span class="text-slate-400 font-normal text-xs">(optional — links the fee to synced revenue)</span>
            </label>
            <select id="freeagent_recurring_invoice_id" name="freeagent_recurring_invoice_id"
                    class="w-full border border-slate-300 rounded px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-accent-500">
                <option value="">Not linked</option>
                <?php foreach ($recurring as $ri): ?>
                    <option value="<?= (int)$ri['id'] ?>" <?= (int)($agreement['freeagent_recurring_invoice_id'] ?? 0) === (int)$ri['id'] ? 'selected' : '' ?>>
                        <?= e($ri['reference']) ?> — <?= e($ri['frequency']) ?>, <?= money($ri['net_value']) ?> (<?= e($ri['recurring_status']) ?>)
                    </option>
                <?php endforeach ?>
            </select>
        </div>
        <?php endif ?>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="start_date" class="block text-sm font-medium text-slate-700 mb-1">Start Date <span class="text-slate-400 font-normal text-xs">(anchors the hours period)</span></label>
                <input type="date" id="start_date" name="start_date" value="<?= e($agreement['start_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div>
                <label for="renewal_date" class="block text-sm font-medium text-slate-700 mb-1">Renewal / Review Date</label>
                <input type="date" id="renewal_date" name="renewal_date" value="<?= e($agreement['renewal_date'] ?? '') ?>"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <div>
            <label for="response_terms" class="block text-sm font-medium text-slate-700 mb-1">Response Terms</label>
            <textarea id="response_terms" name="response_terms" rows="2"
                      placeholder="e.g. Critical issues within 4 working hours; routine requests within 2 working days"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($agreement['response_terms'] ?? '') ?></textarea>
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      placeholder="Scope, exclusions, out-of-hours rates, anything else worth remembering"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($agreement['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Agreement' ?>
            </button>
            <a href="/clients/<?= $client['id'] ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
