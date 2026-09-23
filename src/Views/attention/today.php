<?php
$groups = [
    'high'   => ['Needs action now', 'Sites down, failed jobs, overdue invoices and renewals'],
    'medium' => ['This week', 'Renewals due within 7 days, SLA hours used up, recent overdue invoices'],
    'low'    => ['Housekeeping', 'Chronic client health flags and data gaps — snooze or dismiss what\'s intentional'],
];
$byGroup = array_fill_keys(array_keys($groups), []);
foreach ($items as $i) $byGroup[$i['severity']][] = $i;
?>
<div class="max-w-5xl space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-slate-800">Today</h1>
            <p class="text-sm text-slate-500 mt-0.5">
                <?php if ($showSnoozed): ?>
                    Snoozed and dismissed items. Restore one to put it back on the list.
                <?php else: ?>
                    <?= $counts['high'] ?> urgent · <?= $counts['medium'] ?> this week · <?= $counts['low'] ?> housekeeping
                <?php endif ?>
            </p>
        </div>
        <div class="flex gap-2 text-xs">
            <a href="/today" class="px-2.5 py-1 rounded <?= !$showSnoozed ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Open</a>
            <a href="/today?snoozed=1" class="px-2.5 py-1 rounded <?= $showSnoozed ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200' ?>">Snoozed</a>
        </div>
    </div>

    <?php if (!$items): ?>
        <div class="bg-white border border-slate-200 rounded-lg px-5 py-10 text-center text-sm text-slate-500">
            <?= $showSnoozed ? 'Nothing snoozed.' : 'All clear — nothing needs your attention.' ?>
        </div>
    <?php endif ?>

    <?php foreach ($groups as $sev => [$heading, $hint]): if (!$byGroup[$sev]) continue; ?>
        <section class="bg-white border border-slate-200 rounded-lg" data-attention-group>
            <div class="px-5 py-3 border-b border-slate-200 flex items-baseline justify-between gap-3">
                <h2 class="text-sm font-semibold text-slate-700"><?= e($heading) ?> <span class="text-slate-400 font-normal" data-attention-count><?= count($byGroup[$sev]) ?></span></h2>
                <p class="text-xs text-slate-400 hidden md:block"><?= e($hint) ?></p>
            </div>
            <?php $items = $byGroup[$sev]; include __DIR__ . '/_list.php'; ?>
        </section>
    <?php endforeach ?>
</div>
