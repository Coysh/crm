<div class="max-w-2xl space-y-6">

    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-xl font-semibold text-slate-800 font-mono"><?= e($domain['domain']) ?></h1>
            <?php if ($client): ?>
                <p class="text-sm text-slate-500 mt-1">
                    Client: <a href="/clients/<?= $client['id'] ?>" class="text-accent-600 hover:underline"><?= e($client['name']) ?></a>
                </p>
            <?php endif ?>
        </div>
        <a href="/domains/<?= $domain['id'] ?>/edit" class="px-3 py-1.5 border border-slate-300 rounded text-sm text-slate-600 hover:bg-slate-50">
            Edit
        </a>
    </div>

    <!-- Domain Details -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <h2 class="text-sm font-semibold text-slate-700">Domain Details</h2>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Registrar</dt>
                <dd class="mt-0.5 font-medium text-slate-800"><?= $domain['registrar'] ? e($domain['registrar']) : '<span class="text-slate-400">—</span>' ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Cloudflare Proxied</dt>
                <dd class="mt-0.5">
                    <?php if ($domain['cloudflare_proxied']): ?>
                        <span class="inline-block px-2 py-0.5 rounded-full text-xs bg-orange-100 text-orange-700">Yes</span>
                    <?php else: ?>
                        <span class="text-slate-400">No</span>
                    <?php endif ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Renewal Date</dt>
                <dd class="mt-0.5 <?= ($domain['renewal_date'] && $domain['renewal_date'] < date('Y-m-d')) ? 'text-red-600 font-medium' : 'text-slate-800' ?>">
                    <?= $domain['renewal_date'] ? formatDate($domain['renewal_date']) : '<span class="text-slate-400">—</span>' ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Renewal Period</dt>
                <dd class="mt-0.5 text-slate-800"><?= (int)($domain['renewal_years'] ?? 1) ?> year<?= (int)($domain['renewal_years'] ?? 1) > 1 ? 's' : '' ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">My Cost <span class="text-slate-400 font-normal">(per renewal)</span></dt>
                <dd class="mt-0.5 font-medium text-slate-800">
                    <?= $domain['annual_cost'] !== null ? formatCurrency($domain['annual_cost'], $domain['currency'] ?? 'GBP') : '<span class="text-slate-400">—</span>' ?>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Client Charge <span class="text-slate-400 font-normal">(per renewal)</span></dt>
                <dd class="mt-0.5 font-medium text-slate-800">
                    <?= ($domain['client_charge'] ?? null) !== null ? formatCurrency($domain['client_charge'], $domain['currency'] ?? 'GBP') : '<span class="text-slate-400">—</span>' ?>
                </dd>
            </div>
        </dl>
    </div>

    <!-- Cloudflare Zone Panel -->
    <?php if ($cfZone): ?>
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold text-slate-700">Cloudflare Zone</h2>
            <a href="https://dash.cloudflare.com/?to=/:account/<?= e($cfZone['zone_id']) ?>" target="_blank" rel="noopener noreferrer"
               class="text-xs text-accent-600 hover:underline">
                Open in Cloudflare ↗
            </a>
        </div>
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Zone ID</dt>
                <dd class="mt-0.5 font-mono text-xs text-slate-600"><?= e($cfZone['zone_id']) ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Status</dt>
                <dd class="mt-0.5">
                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-medium <?= $cfZone['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-600' ?>">
                        <?= e(ucfirst($cfZone['status'] ?? '—')) ?>
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Plan</dt>
                <dd class="mt-0.5 text-slate-800"><?= $cfZone['plan'] ? e($cfZone['plan']) : '<span class="text-slate-400">—</span>' ?></dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500 uppercase tracking-wide">SSL Status</dt>
                <dd class="mt-0.5 text-slate-800"><?= $cfZone['ssl_status'] ? e($cfZone['ssl_status']) : '<span class="text-slate-400">—</span>' ?></dd>
            </div>
            <?php if ($cfZone['name_servers']): ?>
            <div class="col-span-2">
                <dt class="text-xs text-slate-500 uppercase tracking-wide">Nameservers</dt>
                <dd class="mt-0.5 text-slate-800 text-xs font-mono">
                    <?php
                    $ns = json_decode($cfZone['name_servers'], true);
                    echo is_array($ns) ? implode(', ', array_map('htmlspecialchars', $ns)) : e($cfZone['name_servers']);
                    ?>
                </dd>
            </div>
            <?php endif ?>
        </dl>
        <p class="text-xs text-slate-400">Last synced: <?= $cfZone['last_synced_at'] ? formatDate($cfZone['last_synced_at']) : '—' ?></p>
        <a href="/domains/<?= $domain['id'] ?>/dns" class="inline-block text-sm text-accent-600 hover:underline">View DNS Records →</a>
    </div>
    <?php endif ?>

    <p class="text-xs text-slate-400"><a href="/domains" class="hover:underline">← Back to Domains</a></p>
</div>
