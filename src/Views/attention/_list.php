<?php
/**
 * Attention item rows. Expects $items; optional $showSnoozed (restore instead of snooze).
 * Buttons are wired by public/js/attention.js via data attributes.
 */
$showSnoozed = $showSnoozed ?? false;
$sevDot = ['high' => 'bg-red-500', 'medium' => 'bg-amber-400', 'low' => 'bg-slate-300'];
?>
<ul class="divide-y divide-slate-100" data-attention-list>
    <?php foreach ($items as $item): ?>
    <li class="px-5 py-2.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm"
        data-attention-row data-key="<?= e($item['key']) ?>" data-severity="<?= e($item['severity']) ?>">
        <div class="flex items-center gap-2 min-w-0 flex-1">
            <span class="w-2 h-2 rounded-full shrink-0 <?= $sevDot[$item['severity']] ?>" title="<?= e(ucfirst($item['severity'])) ?> priority"></span>
            <span class="text-xs text-slate-500 shrink-0 w-32 truncate"><?= e($item['kind']) ?></span>
            <a href="<?= e($item['url']) ?>" class="font-medium text-slate-800 hover:text-accent-600 truncate"><?= e($item['title']) ?></a>
            <?php if ($item['client_name']): ?>
                <a href="/clients/<?= (int)$item['client_id'] ?>" class="text-xs text-slate-400 hover:text-accent-600 shrink-0">— <?= e($item['client_name']) ?></a>
            <?php endif ?>
            <?php if ($item['detail']): ?>
                <span class="text-xs text-slate-500 truncate hidden sm:inline"><?= e($item['detail']) ?></span>
            <?php endif ?>
        </div>
        <div class="flex items-center gap-2 shrink-0 text-xs">
            <?php if ($showSnoozed): ?>
                <span class="text-slate-400"><?= $item['snoozed_until'] ? 'Until ' . e(formatDate($item['snoozed_until'])) : 'Dismissed' ?></span>
                <button type="button" data-attention-unsnooze class="px-2 py-1 border border-slate-300 rounded hover:bg-slate-50">Restore</button>
            <?php else: ?>
                <?php if (($item['action']['type'] ?? null) === 'renew'): ?>
                    <button type="button" data-attention-renew data-type="<?= e($item['action']['renewal_type']) ?>" data-id="<?= (int)$item['action']['id'] ?>"
                            class="px-2 py-1 border border-slate-300 rounded hover:bg-slate-50">Mark renewed</button>
                <?php endif ?>
                <details class="relative">
                    <summary class="list-none cursor-pointer px-2 py-1 border border-slate-300 rounded text-slate-600 hover:bg-slate-50">Snooze</summary>
                    <div class="absolute right-0 z-10 mt-1 w-32 bg-white border border-slate-200 rounded shadow-lg py-1">
                        <button type="button" data-attention-snooze="7"  class="block w-full text-left px-3 py-1.5 hover:bg-slate-50">7 days</button>
                        <button type="button" data-attention-snooze="30" class="block w-full text-left px-3 py-1.5 hover:bg-slate-50">30 days</button>
                        <button type="button" data-attention-snooze="90" class="block w-full text-left px-3 py-1.5 hover:bg-slate-50">90 days</button>
                        <button type="button" data-attention-snooze="dismiss" class="block w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-500 border-t border-slate-100">Dismiss</button>
                    </div>
                </details>
            <?php endif ?>
        </div>
    </li>
    <?php endforeach ?>
</ul>
