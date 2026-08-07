<div class="space-y-6 max-w-3xl">

    <!-- Header -->
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-800 flex items-center gap-2">
                <?= e($site['domain_name'] ?? 'Unnamed Site') ?>
                <?php if (($site['status'] ?? 'active') === 'archived'): ?>
                    <span class="px-2 py-0.5 rounded text-xs font-medium <?= statusBadge('archived') ?>">Archived</span>
                <?php endif ?>
            </h1>
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
            <?php $siteStatus = $site['status'] ?? 'active'; ?>
            <form method="POST" action="/sites/<?= $site['id'] ?>/archive" class="inline">
                <button type="submit"
                        onclick="return confirm('<?= $siteStatus === 'archived' ? 'Restore this site?' : 'Archive this site? It will be excluded from cost apportionment and health checks.' ?>')"
                        class="px-3 py-1.5 text-sm border border-slate-300 rounded hover:bg-slate-50 text-slate-600">
                    <?= $siteStatus === 'archived' ? 'Restore' : 'Archive' ?>
                </button>
            </form>
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

    <!-- WPMGR details -->
    <?php if ($site['wpmgr_site_id']): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center gap-2">
            <h2 class="text-sm font-semibold text-slate-700">WPMGR</h2>
            <span class="flex items-center gap-1 text-xs text-slate-500">
                <span class="w-1.5 h-1.5 rounded-full <?= $site['wpmgr_health_status'] === 'healthy' ? 'bg-green-400' : 'bg-slate-300' ?>"></span>
                <?= e($site['wpmgr_health_status'] ?? 'unknown') ?>
            </span>
        </div>
        <dl class="divide-y divide-slate-100">
            <?php
            $wpmgrFields = [
                'WordPress Version' => $site['wpmgr_wp_version'],
                'PHP Version'       => $site['wpmgr_php_version'],
                'Updates Available' => $site['wpmgr_updates_available'] ? (int)$site['wpmgr_updates_available'] : null,
                'Last Backup'       => $site['wpmgr_last_backup_at'] ? formatDate($site['wpmgr_last_backup_at']) . ' (' . e($site['wpmgr_last_backup_status'] ?? '?') . ')' : null,
                'Uptime (30d)'      => $site['wpmgr_uptime_pct'] !== null ? number_format((float)$site['wpmgr_uptime_pct'], 2) . '%' : null,
                'TLS Expires'       => $site['wpmgr_tls_expires_at'] ? formatDate($site['wpmgr_tls_expires_at']) : null,
                'Connection State'  => $site['wpmgr_connection_state'],
            ];
            foreach ($wpmgrFields as $label => $value): if ($value === null || $value === '') continue; ?>
                <div class="px-5 py-3 flex gap-4">
                    <dt class="text-sm text-slate-500 w-36 shrink-0"><?= $label ?></dt>
                    <dd class="text-sm text-slate-800"><?= $value ?></dd>
                </div>
            <?php endforeach ?>
        </dl>
    </div>
    <?php endif ?>

    <!-- Uptime Kuma -->
    <?php if ($kumaMonitors): ?>
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 bg-slate-50 flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Uptime Kuma</h2>
            <a href="/settings/uptime-kuma" class="text-xs text-slate-400 hover:text-slate-600">Manage →</a>
        </div>

        <?php foreach ($kumaMonitors as $mi => $m):
            $state    = uptimeStatus($m['status'] === null ? null : (int)$m['status']);
            $certDays = $m['cert_days_remaining'] === null ? null : (int)$m['cert_days_remaining'];
            $fields = [
                'Status'         => '<span class="' . $state['text'] . '">' . $state['label'] . '</span>'
                                    . ((int)$m['status'] === 0 && $m['status_changed_at'] ? ' <span class="text-slate-400">for ' . formatDurationSince($m['status_changed_at']) . '</span>' : ''),
                'Uptime (24h)'   => $m['uptime_24h'] !== null ? number_format((float)$m['uptime_24h'], 2) . '%' : null,
                'Uptime (30d)'   => $m['uptime_30d'] !== null ? number_format((float)$m['uptime_30d'], 2) . '%' : null,
                'Response Time'  => $m['response_time_ms'] !== null ? (int)$m['response_time_ms'] . ' ms' : null,
                'TLS Expires In' => $certDays === null ? null
                                    : '<span class="' . ($certDays < 14 ? 'text-red-600' : ($certDays < 30 ? 'text-amber-600' : 'text-slate-800')) . '">' . $certDays . ' days</span>',
                'Monitor Type'   => $m['monitor_type'],
                'Last Checked'   => $m['last_synced_at'] ? formatDurationSince($m['last_synced_at']) . ' ago' : null,
            ];
        ?>
            <div class="<?= $mi > 0 ? 'border-t-4 border-slate-100' : '' ?> <?= $m['is_stale'] ? 'opacity-60' : '' ?>">
                <div class="px-5 py-2 flex items-center gap-2 <?= count($kumaMonitors) > 1 ? 'bg-slate-50/60' : '' ?>">
                    <span class="w-1.5 h-1.5 rounded-full <?= $state['dot'] ?>"></span>
                    <span class="text-xs font-medium text-slate-600"><?= e($m['monitor_name']) ?></span>
                    <?php if ($m['link_is_manual']): ?><span class="text-xs text-slate-400">manually linked</span><?php endif ?>
                    <?php if ($m['is_stale']): ?><span class="text-xs text-slate-400">— stale, no longer reported</span><?php endif ?>
                </div>
                <dl class="divide-y divide-slate-100">
                    <?php foreach ($fields as $label => $value): if ($value === null || $value === '') continue; ?>
                        <div class="px-5 py-3 flex gap-4">
                            <dt class="text-sm text-slate-500 w-36 shrink-0"><?= $label ?></dt>
                            <dd class="text-sm text-slate-800"><?= $value ?></dd>
                        </div>
                    <?php endforeach ?>
                </dl>
            </div>
        <?php endforeach ?>

        <?php if ($kumaChart): ?>
            <div class="px-5 py-4 border-t border-slate-200">
                <h3 class="text-xs font-semibold text-slate-500 uppercase tracking-wide mb-3">Response Time — Last 48 Hours</h3>
                <div class="h-48"><canvas id="kuma-response-chart"></canvas></div>
            </div>
            <script>
                window.kumaChartData = <?= json_encode($kumaChart, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            </script>
        <?php endif ?>

        <div class="px-5 py-3 border-t border-slate-200 bg-slate-50 text-xs text-slate-500">
            Uptime is calculated from samples taken on each sync, so it only covers the period since the
            integration was switched on. Alerting stays in Uptime Kuma.
        </div>
    </div>
    <?php endif ?>

</div>

<?php if (!empty($kumaChart)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var canvas = document.getElementById('kuma-response-chart');
    var data   = window.kumaChartData;
    if (!canvas || !data || typeof Chart === 'undefined') return;

    // Literal hex, not Tailwind classes — tailwind.config.js only scans src/Views
    // for class names, but this is a JS value, so it would never be emitted anyway.
    var palette = (window.CrmCharts && window.CrmCharts.palette) || ['#4f46e5'];

    new Chart(canvas, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: data.series.map(function (s, i) {
                return {
                    label: s.label,
                    data: s.points,
                    borderColor: palette[i % palette.length],
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    pointRadius: 0,
                    pointHoverRadius: 3,
                    tension: 0.25,
                    spanGaps: false,
                };
            }),
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: data.series.length > 1, position: 'bottom' },
                tooltip: {
                    callbacks: {
                        label: function (ctx) {
                            return ' ' + ctx.dataset.label + ': ' + (ctx.parsed.y === null ? 'no data' : ctx.parsed.y + ' ms');
                        },
                    },
                },
            },
            scales: {
                y: {
                    beginAtZero: true,
                    border: { display: false },
                    grid: { color: '#f1f5f9', drawTicks: false },
                    ticks: { padding: 6, callback: function (v) { return v + ' ms'; } },
                },
                x: {
                    border: { display: false },
                    grid: { display: false },
                    ticks: { padding: 4, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
                },
            },
        },
    });
});
</script>
<?php endif ?>
