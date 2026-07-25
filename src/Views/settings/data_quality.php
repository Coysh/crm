<div class="max-w-3xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="text-xl font-semibold text-slate-800">Data Quality</h1>
        <span class="px-3 py-1 rounded-full text-sm font-medium <?= $totalIssues === 0 ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>">
            <?= $totalIssues === 0 ? 'All clear' : $totalIssues . ' issue' . ($totalIssues === 1 ? '' : 's') ?>
        </span>
    </div>
    <p class="text-sm text-slate-500">Checks for missing links and incomplete records that skew the P&amp;L or hide renewals. Fix items at their source — this page is read-only.</p>

    <?php foreach ($checks as $check): ?>
        <?php $count = count($check['rows']); ?>
        <div class="bg-white border <?= $count ? 'border-amber-200' : 'border-slate-200' ?> rounded-lg overflow-hidden">
            <div class="px-5 py-3 flex items-center justify-between gap-3 <?= $count ? 'bg-amber-50/50' : '' ?>">
                <div>
                    <h2 class="text-sm font-semibold text-slate-700"><?= e($check['title']) ?></h2>
                    <p class="text-xs text-slate-500 mt-0.5"><?= e($check['description']) ?></p>
                </div>
                <span class="shrink-0 px-2 py-0.5 rounded-full text-xs font-medium <?= $count ? 'bg-amber-100 text-amber-700' : 'bg-green-100 text-green-700' ?>">
                    <?= $count ?: '✓' ?>
                </span>
            </div>
            <?php if ($check['error']): ?>
                <p class="px-5 py-2 text-xs text-slate-400 border-t border-slate-100">Check unavailable: <?= e($check['error']) ?></p>
            <?php elseif ($count): ?>
                <ul class="border-t border-slate-100 divide-y divide-slate-50 max-h-64 overflow-y-auto">
                    <?php foreach ($check['rows'] as $row): ?>
                        <li class="px-5 py-2 text-sm">
                            <a href="<?= e($row['url']) ?>" class="text-accent-600 hover:underline"><?= e($row['label']) ?></a>
                        </li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>
        </div>
    <?php endforeach ?>
</div>
