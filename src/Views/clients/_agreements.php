<?php
/**
 * Agreements section for the client detail page.
 * Expects: $client (row incl. agreement_notes), $agreements (Agreement::findByClient
 * output, each with usage + 'attachments' rows attached).
 */
$typeLabels = \CoyshCRM\Models\Agreement::TYPES;
?>
<div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
    <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
        <h2 class="text-sm font-semibold text-slate-700">Agreements &amp; SLAs</h2>
        <a href="/clients/<?= $client['id'] ?>/agreements/create" class="text-xs text-accent-600 hover:underline">+ Add Agreement</a>
    </div>

    <?php if (empty($agreements)): ?>
        <div class="px-5 py-4">
            <p class="text-sm text-slate-400 italic">No agreements recorded.
                <a href="/clients/<?= $client['id'] ?>/agreements/create" class="text-accent-600 hover:underline not-italic">Add one →</a>
            </p>
        </div>
    <?php else: ?>
        <div class="divide-y divide-slate-100">
            <?php foreach ($agreements as $a): ?>
                <?php
                $isActive = $a['status'] === 'active';
                $renewalOverdue = $isActive && !empty($a['renewal_date']) && $a['renewal_date'] < date('Y-m-d');
                $coverage = array_filter([
                    !empty($a['covers_hosting']) ? 'Hosting' : null,
                    !empty($a['covers_support']) ? 'Support' : null,
                    !empty($a['covers_maintenance']) ? 'Maintenance' : null,
                ]);
                $hasHours = $a['included_hours'] !== null && (float)$a['included_hours'] > 0;
                ?>
                <div class="px-5 py-4 space-y-3 <?= $isActive ? '' : 'opacity-60' ?>">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-slate-800"><?= e($a['title']) ?></span>
                        <span class="px-2 py-0.5 rounded-full text-xs bg-slate-100 text-slate-600"><?= e($typeLabels[$a['agreement_type']] ?? $a['agreement_type']) ?></span>
                        <span class="px-2 py-0.5 rounded-full text-xs <?= statusBadge($a['status']) ?>"><?= ucfirst($a['status']) ?></span>
                        <?php foreach ($coverage as $c): ?>
                            <span class="px-2 py-0.5 rounded-full text-xs bg-accent-50 text-accent-700 border border-accent-200"><?= $c ?></span>
                        <?php endforeach ?>
                        <span class="ml-auto flex items-center gap-3">
                            <a href="/clients/<?= $client['id'] ?>/agreements/<?= $a['id'] ?>/edit" class="text-xs text-accent-600 hover:underline">Edit</a>
                            <form method="POST" action="/clients/<?= $client['id'] ?>/agreements/<?= $a['id'] ?>/delete"
                                  onsubmit="return confirm('Delete this agreement and its work log?')">
                                <?= csrfField() ?>
                                <button class="text-xs text-red-500 hover:text-red-700">Delete</button>
                            </form>
                        </span>
                    </div>

                    <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs text-slate-500">
                        <?php if ($a['fee_amount'] !== null): ?>
                            <span><span class="font-medium text-slate-600">Fee:</span>
                                <?= formatCurrency($a['fee_amount'], $a['fee_currency'] ?? 'GBP') ?><?= $a['fee_billing_cycle'] ? ' / ' . str_replace('_', '-', $a['fee_billing_cycle']) : '' ?></span>
                        <?php endif ?>
                        <?php if (!empty($a['recurring_reference'])): ?>
                            <span><span class="font-medium text-slate-600">Billed via:</span> <?= e($a['recurring_reference']) ?> (<?= e($a['recurring_status'] ?? '—') ?>)</span>
                        <?php endif ?>
                        <?php
                        // Make the revenue treatment explicit: an active agreement with no
                        // linked recurring invoice is what supplies this client's MRR.
                        $cycleMonthly = ['monthly' => 1, 'quarterly' => 3, 'annually' => 12];
                        $divisor = $cycleMonthly[$a['fee_billing_cycle'] ?? ''] ?? null;
                        if ($a['status'] === 'active' && $a['fee_amount'] !== null && $divisor):
                            if (empty($a['freeagent_recurring_invoice_id'])): ?>
                                <span class="text-slate-500">
                                    <span class="font-medium text-slate-600">Counted as MRR:</span>
                                    <?= money((float)$a['fee_amount'] / $divisor) ?> / month
                                </span>
                            <?php else: ?>
                                <span class="text-slate-400" title="Counted through the linked recurring invoice, not the agreement fee — avoids double counting.">
                                    Not counted separately
                                </span>
                            <?php endif ?>
                        <?php endif ?>
                        <?php if (!empty($a['start_date'])): ?>
                            <span><span class="font-medium text-slate-600">Started:</span> <?= formatDate($a['start_date']) ?></span>
                        <?php endif ?>
                        <?php if (!empty($a['renewal_date'])): ?>
                            <span class="<?= $renewalOverdue ? 'text-red-600 font-medium' : '' ?>">
                                <span class="font-medium <?= $renewalOverdue ? 'text-red-600' : 'text-slate-600' ?>">Renewal:</span>
                                <?= formatDate($a['renewal_date']) ?><?= $renewalOverdue ? ' — overdue' : '' ?>
                            </span>
                        <?php endif ?>
                    </div>

                    <?php if (!empty($a['response_terms'])): ?>
                        <p class="text-xs text-slate-500"><span class="font-medium text-slate-600">Response terms:</span> <?= e($a['response_terms']) ?></p>
                    <?php endif ?>

                    <?php if ($hasHours): ?>
                        <?php
                        $included  = (float)$a['included_hours'];
                        $used      = (float)($a['hours_used'] ?? 0);
                        $remaining = (float)($a['hours_remaining'] ?? $included);
                        $pct       = $included > 0 ? min(100, round($used / $included * 100)) : 0;
                        $barColour = $pct >= 100 ? 'bg-red-500' : ($pct >= 75 ? 'bg-amber-400' : 'bg-accent-500');
                        ?>
                        <div class="space-y-1">
                            <div class="flex items-center justify-between text-xs">
                                <span class="text-slate-600 font-medium">
                                    Hours: <?= rtrim(rtrim(number_format($used, 2), '0'), '.') ?> used of <?= rtrim(rtrim(number_format($included, 2), '0'), '.') ?>
                                    (<?= e($a['hours_period'] ?? 'annually') ?>)
                                </span>
                                <span class="<?= $remaining <= 0 ? 'text-red-600 font-medium' : 'text-slate-500' ?>">
                                    <?= rtrim(rtrim(number_format($remaining, 2), '0'), '.') ?> remaining<?= !empty($a['period_start']) ? ' since ' . formatDate($a['period_start']) : '' ?>
                                </span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-1.5">
                                <div class="h-1.5 rounded-full <?= $barColour ?>" style="width: <?= $pct ?>%"></div>
                            </div>
                        </div>

                        <!-- Quick-add work log -->
                        <form method="POST" action="/clients/<?= $client['id'] ?>/agreements/<?= $a['id'] ?>/work"
                              class="flex flex-wrap items-end gap-2">
                            <?= csrfField() ?>
                            <div>
                                <label class="block text-xs text-slate-500 mb-0.5">Date</label>
                                <input type="date" name="work_date" value="<?= date('Y-m-d') ?>"
                                       class="border border-slate-300 rounded px-2 py-1 text-xs">
                            </div>
                            <div>
                                <label class="block text-xs text-slate-500 mb-0.5">Hours</label>
                                <input type="number" name="hours" step="0.25" min="0.25" required placeholder="1.5"
                                       class="w-20 border border-slate-300 rounded px-2 py-1 text-xs">
                            </div>
                            <div class="flex-1 min-w-[160px]">
                                <label class="block text-xs text-slate-500 mb-0.5">Description</label>
                                <input type="text" name="description" placeholder="What was done?"
                                       class="w-full border border-slate-300 rounded px-2 py-1 text-xs">
                            </div>
                            <button class="px-3 py-1 bg-accent-600 text-white text-xs rounded hover:bg-accent-700">Log Work</button>
                        </form>

                        <?php if (!empty($a['work_log'])): ?>
                            <details class="text-xs">
                                <summary class="cursor-pointer text-slate-500 hover:text-slate-700">Recent work (<?= count($a['work_log']) ?>)</summary>
                                <table class="w-full mt-2">
                                    <tbody class="divide-y divide-slate-100">
                                        <?php foreach ($a['work_log'] as $w): ?>
                                            <tr>
                                                <td class="py-1 pr-3 text-slate-400 whitespace-nowrap"><?= formatDate($w['work_date']) ?></td>
                                                <td class="py-1 pr-3 tabular-nums whitespace-nowrap"><?= rtrim(rtrim(number_format((float)$w['hours'], 2), '0'), '.') ?>h</td>
                                                <td class="py-1 pr-3 text-slate-600 w-full"><?= e($w['description'] ?: '—') ?></td>
                                                <td class="py-1 text-right">
                                                    <form method="POST" action="/clients/<?= $client['id'] ?>/agreements/<?= $a['id'] ?>/work/<?= $w['id'] ?>/delete"
                                                          onsubmit="return confirm('Remove this work log entry?')">
                                                        <?= csrfField() ?>
                                                        <button class="text-red-400 hover:text-red-600">✕</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach ?>
                                    </tbody>
                                </table>
                            </details>
                        <?php endif ?>
                    <?php endif ?>

                    <?php if (!empty($a['attachments'])): ?>
                        <div class="flex flex-wrap gap-2 text-xs">
                            <?php foreach ($a['attachments'] as $att): ?>
                                <a href="/clients/<?= $client['id'] ?>/attachments/<?= $att['id'] ?>/download" target="_blank"
                                   class="inline-flex items-center gap-1 px-2 py-0.5 border border-slate-200 rounded text-slate-600 hover:border-accent-300 hover:text-accent-700">
                                    📄 <?= e($att['original_name']) ?>
                                </a>
                            <?php endforeach ?>
                        </div>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php if (!empty($client['agreement_notes'])): ?>
        <div class="px-5 py-3 border-t border-slate-200 bg-slate-50">
            <p class="text-xs font-medium text-slate-500 mb-1">Legacy agreement notes</p>
            <p class="text-sm text-slate-600 whitespace-pre-wrap"><?= e($client['agreement_notes']) ?></p>
        </div>
    <?php endif ?>
</div>
