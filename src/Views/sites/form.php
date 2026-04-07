<?php $isEdit = !empty($site['id']); ?>

<div class="max-w-xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">
        <?= $isEdit ? 'Edit Site' : 'Add Site' ?>
        <span class="text-slate-400 font-normal text-base">— <?= e($client['name']) ?></span>
    </h1>

    <form method="POST"
          action="<?= $isEdit ? "/clients/{$client['id']}/sites/{$site['id']}" : "/clients/{$client['id']}/sites" ?>"
          class="space-y-4 bg-white border border-slate-200 rounded-lg p-6">

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="domain_id" class="block text-sm font-medium text-slate-700 mb-1">Domain</label>
                <select id="domain_id" name="domain_id" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— None —</option>
                    <?php foreach ($domains as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($site['domain_id'] ?? null) == $d['id'] ? 'selected' : '' ?>><?= e($d['domain']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
            <div>
                <label for="server_id" class="block text-sm font-medium text-slate-700 mb-1">Server</label>
                <select id="server_id" name="server_id" class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
                    <option value="">— None —</option>
                    <?php foreach ($servers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($site['server_id'] ?? null) == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                    <?php endforeach ?>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label for="website_stack" class="block text-sm font-medium text-slate-700 mb-1">Website Stack</label>
                <input type="text" id="website_stack" name="website_stack" value="<?= e($site['website_stack'] ?? '') ?>"
                       placeholder="e.g. WordPress, Craft CMS, Static"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
            <div>
                <label for="css_framework" class="block text-sm font-medium text-slate-700 mb-1">CSS Framework</label>
                <input type="text" id="css_framework" name="css_framework" value="<?= e($site['css_framework'] ?? '') ?>"
                       placeholder="e.g. Tailwind, Bootstrap, None"
                       class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
            </div>
        </div>

        <div>
            <label for="smtp_service" class="block text-sm font-medium text-slate-700 mb-1">SMTP Service</label>
            <input type="text" id="smtp_service" name="smtp_service" value="<?= e($site['smtp_service'] ?? '') ?>"
                   placeholder="e.g. Mailgun, Brevo, Postmark, None"
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <div>
            <label for="git_repo" class="block text-sm font-medium text-slate-700 mb-1">Git Repository URL</label>
            <input type="url" id="git_repo" name="git_repo" value="<?= e($site['git_repo'] ?? '') ?>"
                   placeholder="https://github.com/..."
                   class="w-full border border-slate-300 rounded px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-accent-500">
        </div>

        <div class="flex items-center gap-3">
            <input type="checkbox" id="has_deployment_pipeline" name="has_deployment_pipeline" value="1"
                   <?= ($site['has_deployment_pipeline'] ?? 0) ? 'checked' : '' ?>
                   class="rounded border-slate-300 text-accent-600">
            <label for="has_deployment_pipeline" class="text-sm text-slate-700">Has Deployment Pipeline</label>
        </div>

        <div>
            <label for="notes" class="block text-sm font-medium text-slate-700 mb-1">Notes</label>
            <textarea id="notes" name="notes" rows="3"
                      class="w-full border border-slate-300 rounded px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-accent-500"><?= e($site['notes'] ?? '') ?></textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                <?= $isEdit ? 'Save Changes' : 'Add Site' ?>
            </button>
            <a href="/clients/<?= $client['id'] ?>" class="text-sm text-slate-500 hover:text-slate-800">Cancel</a>
        </div>

    </form>
</div>
