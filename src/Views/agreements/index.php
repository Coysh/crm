<?php $typeLabels = \CoyshCRM\Models\Agreement::TYPES; ?>
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-slate-800">Agreements &amp; SLAs</h1>
        <div class="flex items-center gap-2 text-sm">
            <?php foreach ([null => 'All', 'active' => 'Active', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $value => $label): ?>
                <a href="/agreements<?= $value ? "?status=$value" : '' ?>"
                   class="px-3 py-1 rounded-full text-xs <?= ($status ?? null) === $value ? 'bg-accent-600 text-white' : 'bg-white border border-slate-200 text-slate-600 hover:border-accent-300' ?>">
                    <?= $label ?>
                </a>
            <?php endforeach ?>
        </div>
    </div>

    <?php if (empty($agreements)): ?>
        <div class="bg-white border border-slate-200 rounded-lg p-8 text-center">
            <p class="text-sm text-slate-400">No agreements recorded yet. Add them from a client's page.</p>
        </div>
    <?php else: ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-xs text-slate-500 uppercase tracking-wide bg-slate-50">
                        <tr>
                            <th class="px-4 py-2.5 text-left">Client</th>
                            <th class="px-4 py-2.5 text-left">Agreement</th>
                            <th class="px-4 py-2.5 text-left">Type</th>
                            <th class="px-4 py-2.5 text-left">Coverage</th>
                            <th class="px-4 py-2.5 text-right">Hours</th>
                            <th class="px-4 py-2.5 text-right">Fee</th>
                            <th class="px-4 py-2.5 text-left">Renewal</th>
                            <th class="px-4 py-2.5 text-left">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($agreements as $a): ?>
                            <?php
                            $renewalOverdue = $a['status'] === 'active' && !empty($a['renewal_date']) && $a['renewal_date'] < date('Y-m-d');
                            $coverage = array_filter([
                                !empty($a['covers_hosting']) ? 'Hosting' : null,
                                !empty($a['covers_support']) ? 'Support' : null,
                                !empty($a['covers_maintenance']) ? 'Maintenance' : null,
                            ]);
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <a href="/clients/<?= (int)$a['client_id'] ?>" class="text-accent-600 hover:underline"><?= e($a['client_name']) ?></a>
                                </td>
                                <td class="px-4 py-2.5 text-slate-700"><?= e($a['title']) ?></td>
                                <td class="px-4 py-2.5 text-xs text-slate-500"><?= e($typeLabels[$a['agreement_type']] ?? $a['agreement_type']) ?></td>
                                <td class="px-4 py-2.5 text-xs text-slate-500"><?= $coverage ? implode(' · ', $coverage) : '—' ?></td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-xs">
                                    <?php if ($a['included_hours'] !== null): ?>
                                        <span class="<?= ($a['hours_remaining'] ?? 1) <= 0 ? 'text-red-600 font-medium' : 'text-slate-600' ?>">
                                            <?= rtrim(rtrim(number_format((float)$a['hours_used'], 2), '0'), '.') ?> / <?= rtrim(rtrim(number_format((float)$a['included_hours'], 2), '0'), '.') ?>
                                        </span>
                                        <span class="text-slate-400">/<?= substr($a['hours_period'] ?? 'yr', 0, 2) ?></span>
                                    <?php else: ?>
                                        <span class="text-slate-300">—</span>
                                    <?php endif ?>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums text-xs text-slate-600">
                                    <?= $a['fee_amount'] !== null ? formatCurrency($a['fee_amount'], $a['fee_currency'] ?? 'GBP') . ($a['fee_billing_cycle'] ? '<span class="text-slate-400">/' . substr($a['fee_billing_cycle'], 0, 2) . '</span>' : '') : '<span class="text-slate-300">—</span>' ?>
                                </td>
                                <td class="px-4 py-2.5 text-xs <?= $renewalOverdue ? 'text-red-600 font-medium' : 'text-slate-500' ?>">
                                    <?= formatDate($a['renewal_date']) ?><?= $renewalOverdue ? ' ⚠' : '' ?>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="px-2 py-0.5 rounded-full text-xs <?= statusBadge($a['status']) ?>"><?= ucfirst($a['status']) ?></span>
                                </td>
                            </tr>
                        <?php endforeach ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif ?>
</div>
