<div class="space-y-6 max-w-3xl">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-800"><?= e($site['domain_name'] ?? 'Unnamed Site') ?></h1>
            <?php if ($site['client_name']): ?>
                <p class="text-sm text-slate-500 mt-0.5">
                    <a href="/clients/<?= $site['client_id'] ?>" class="text-accent-600 hover:underline"><?= e($site['client_name']) ?></a>
                    <?php if ($site['server_name']): ?>
                        · <?= e($site['server_name']) ?>
                    <?php endif ?>
                </p>
            <?php else: ?>
                <p class="text-sm text-amber-600 mt-0.5">Unassigned</p>
            <?php endif ?>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="/sites/<?= $site['id'] ?>/edit"
               class="px-3 py-1.5 text-sm border border-slate-300 rounded hover:bg-slate-50">Edit</a>
            <?php if ($site['client_id']): ?>
                <a href="/clients/<?= $site['client_id'] ?>/sites/<?= $site['id'] ?>/edit"
                   class="px-3 py-1.5 text-sm border border-slate-300 rounded hover:bg-slate-50 text-slate-500">
                    Client view
                </a>
            <?php endif ?>
            <form method="POST" action="/sites/<?= $site['id'] ?>/delete" class="inline">
                <button type="submit"
                        onclick="return confirm('Delete this site? This cannot be undone.')"
                        class="px-3 py-1.5 text-sm border border-red-200 rounded hover:bg-red-50 text-red-600">
                    Delete
                </button>
            </form>
        </div>
    </div>

    <!-- Site details -->
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="text-sm font-semibold text-slate-700">Site Details</h2>
        </div>
        <dl class="divide-y divide-slate-100">
            <?php
            $fields = [
                'Website Stack'     => $site['website_stack'],
                'CSS Framework'     => $site['css_framework'],
                'SMTP Service'      => $site['smtp_service'],
                'Git Repo'          => $site['git_repo'] ? '<a href="' . e($site['git_repo']) . '" target="_blank" rel="noopener" class="text-accent-600 hover:underline">' . e($site['git_repo']) . '</a>' : null,
                'Deployment CI/CD'  => $site['has_deployment_pipeline'] ? '<span class="text-green-600 font-medium">Yes</span>' : 'No',
            ];
            foreach ($fields as $label => $value): ?>
                <div class="px-5 py-3 flex gap-4">
                    <dt class="text-sm text-slate-500 w-36 shrink-0"><?= $label ?></dt>
                    <dd class="text-sm text-slate-800"><?= $value ?: '<span class="text-slate-300">—</span>' ?></dd>
                </div>
            <?php endforeach ?>
            <?php if ($site['notes']): ?>
                <div class="px-5 py-3 flex gap-4">
                    <dt class="text-sm text-slate-500 w-36 shrink-0">Notes</dt>
                    <dd class="text-sm text-slate-800"><?= nl2br(e($site['notes'])) ?></dd>
                </div>
            <?php endif ?>
        </dl>
    </div>

    <!-- Domain info -->
    <?php if ($site['domain_name']): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="text-sm font-semibold text-slate-700">Domain</h2>
        </div>
        <dl class="divide-y divide-slate-100">
            <?php
            $domFields = [
                'Domain'          => e($site['domain_name']),
                'Registrar'       => $site['registrar'],
                'Renewal Date'    => $site['domain_renewal'] ? formatDate($site['domain_renewal']) : null,
                'Annual Cost'     => $site['domain_cost'] ? money($site['domain_cost']) : null,
                'Cloudflare'      => $site['cloudflare_proxied'] ? 'Proxied' : 'Not proxied',
            ];
            foreach ($domFields as $label => $value): ?>
                <div class="px-5 py-3 flex gap-4">
                    <dt class="text-sm text-slate-500 w-36 shrink-0"><?= $label ?></dt>
                    <dd class="text-sm text-slate-800"><?= $value ?: '<span class="text-slate-300">—</span>' ?></dd>
                </div>
            <?php endforeach ?>
        </dl>
    </div>
    <?php endif ?>

    <!-- Server info -->
    <?php if ($site['server_name']): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50">
            <h2 class="text-sm font-semibold text-slate-700">Server</h2>
        </div>
        <dl class="divide-y divide-slate-100">
            <div class="px-5 py-3 flex gap-4">
                <dt class="text-sm text-slate-500 w-36 shrink-0">Name</dt>
                <dd class="text-sm text-slate-800">
                    <a href="/servers/<?= $site['server_id'] ?>/edit" class="text-accent-600 hover:underline"><?= e($site['server_name']) ?></a>
                </dd>
            </div>
            <?php if ($site['server_provider']): ?>
            <div class="px-5 py-3 flex gap-4">
                <dt class="text-sm text-slate-500 w-36 shrink-0">Provider</dt>
                <dd class="text-sm text-slate-800"><?= e($site['server_provider']) ?></dd>
            </div>
            <?php endif ?>
        </dl>
    </div>
    <?php endif ?>

    <!-- Ploi details -->
    <?php if ($site['ploi_site_id']): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-2">
            <h2 class="text-sm font-semibold text-slate-700">Ploi</h2>
            <span class="flex items-center gap-1 text-xs text-slate-500">
                <span class="w-1.5 h-1.5 rounded-full <?= $site['ploi_status'] === 'active' ? 'bg-green-400' : 'bg-slate-300' ?>"></span>
                <?= e($site['ploi_status'] ?? 'unknown') ?>
            </span>
        </div>
        <dl class="divide-y divide-slate-100">
            <?php
            $ploiFields = [
                'Domain'      => $site['ploi_domain'],
                'Type'        => $site['ploi_project_type'],
                'PHP Version' => $site['ploi_php_version'],
                'Repository'  => $site['ploi_repository'],
                'Branch'      => $site['ploi_branch'],
                'Web Dir'     => $site['ploi_web_directory'],
                'SSL'         => $site['ploi_has_ssl'] ? 'Yes' : 'No',
                'Test Domain' => $site['ploi_test_domain'],
            ];
            foreach ($ploiFields as $label => $value): if (!$value) continue; ?>
                <div class="px-5 py-3 flex gap-4">
                    <dt class="text-sm text-slate-500 w-36 shrink-0"><?= $label ?></dt>
                    <dd class="text-sm font-mono text-xs text-slate-800"><?= e($value) ?></dd>
                </div>
            <?php endforeach ?>
        </dl>
    </div>
    <?php endif ?>

</div>
