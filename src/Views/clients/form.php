<?php $isEdit = !empty($client['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit ' . e($client['name']) : 'Add Client' ?>
    </h1>

    <form method="POST" action="<?= $isEdit ? '/clients/' . $client['id'] : '/clients' ?>" class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <!-- Name -->
        <div>
            <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Client Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" value="<?= e($client['name'] ?? '') ?>"
                   class="w-full border <?= isset($errors['name']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['name'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['name']) ?></p>
            <?php endif ?>
        </div>

        <!-- Status -->
        <div>
            <label for="status" class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select id="status" name="status" class="border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                <?php foreach (['active' => 'Active', 'archived' => 'Archived'] as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($client['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach ?>
            </select>
        </div>

        <!-- Contact name -->
        <div>
            <label for="contact_name" class="block text-sm font-medium text-slate-700 mb-1">Contact Name</label>
            <input type="text" id="contact_name" name="contact_name" value="<?= e($client['contact_name'] ?? '') ?>"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <!-- Contact email -->
        <div>
            <label for="contact_email" class="block text-sm font-medium text-slate-700 mb-1">Contact Email</label>
            <input type="email" id="contact_email" name="contact_email" value="<?= e($client['contact_email'] ?? '') ?>"
                   class="w-full border <?= isset($errors['contact_email']) ? 'border-red-400' : 'border-slate-300' ?> rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            <?php if (isset($errors['contact_email'])): ?>
                <p class="text-xs text-red-600 mt-1"><?= e($errors['contact_email']) ?></p>
            <?php endif ?>
        </div>

        <!-- Client Type -->
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-2">Client Type</label>
            <div class="flex flex-wrap gap-4">
                <?php foreach (['managed' => 'Managed', 'support_only' => 'Support Only', 'consultancy_only' => 'Consultancy Only'] as $val => $label): ?>
                    <label class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="radio" name="client_type" value="<?= $val ?>"
                               onchange="toggleAgreementNotes()"
                               <?= ($client['client_type'] ?? 'managed') === $val ? 'checked' : '' ?>>
                        <?= $label ?>
                    </label>
                <?php endforeach ?>
            </div>
            <p class="text-xs text-slate-400 mt-1">Managed = full site ownership + hosting; Support Only = ad-hoc support; Consultancy Only = advice/strategy, no infrastructure.</p>
        </div>

        <!-- Agreement Notes (shown for non-managed types) -->
        <div id="agreement_notes_wrap" <?= ($client['client_type'] ?? 'managed') === 'managed' ? 'style="display:none"' : '' ?>>
            <label for="agreement_notes" class="block text-sm font-medium text-slate-700 mb-1">Agreement Notes</label>
            <textarea id="agreement_notes" name="agreement_notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($client['agreement_notes'] ?? '') ?></textarea>
            <p class="text-xs text-slate-400 mt-1">Scope of engagement, included hours, SLA, or other arrangement details.</p>
        </div>

        <!-- Notes -->
        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($client['notes'] ?? '') ?></textarea>
        </div>

        <script>
        function toggleAgreementNotes() {
            const type = document.querySelector('input[name="client_type"]:checked')?.value ?? 'managed';
            document.getElementById('agreement_notes_wrap').style.display = type === 'managed' ? 'none' : '';
        }
        </script>

        <!-- Actions -->
        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Create Client' ?>
            </button>
            <a href="<?= $isEdit ? '/clients/' . $client['id'] : '/clients' ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
