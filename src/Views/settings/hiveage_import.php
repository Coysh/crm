<?php
$fieldLabels = [
    'reference'   => 'Invoice Number',
    'client_name' => 'Client Name',
    'dated_on'    => 'Invoice Date',
    'due_date'    => 'Due Date',
    'total_value' => 'Total Amount',
    'status'      => 'Status',
    'currency'    => 'Currency',
    'paid_on'     => 'Paid Date',
];
?>

<div class="max-w-4xl space-y-6">

    <h1 class="text-xl font-semibold text-slate-800">Hiveage Invoice Import</h1>

    <?php if ($step === 1): ?>

    <!-- Step 1: Upload -->
    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
        <p class="text-sm text-slate-600">
            Upload a CSV export from Hiveage to import historic invoices. Duplicate invoices (matched by invoice number) will be skipped.
        </p>
        <form method="POST" action="/settings/import/hiveage/upload" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">CSV File</label>
                <input type="file" name="csv" accept=".csv,text/csv" required
                       class="text-sm text-slate-600">
            </div>
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                Upload &amp; Preview
            </button>
        </form>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
        <h2 class="text-sm font-semibold text-slate-700">Danger Zone</h2>
        <p class="text-xs text-slate-500">Remove all previously imported Hiveage invoices from the database.</p>
        <form method="POST" action="/settings/import/hiveage/clear">
            <button type="submit" onclick="return confirm('Remove all Hiveage invoices? This cannot be undone.')"
                    class="px-3 py-1.5 text-sm border border-red-200 rounded hover:bg-red-50 text-red-600">
                Clear All Hiveage Records
            </button>
        </form>
    </div>

    <?php elseif ($step === 2): ?>

    <!-- Step 2: Preview & column mapping -->
    <form method="POST" action="/settings/import/hiveage/confirm" class="space-y-6">

        <!-- Column mapping -->
        <div class="bg-white border border-slate-200 rounded-lg p-5 space-y-4">
            <h2 class="text-sm font-semibold text-slate-700">Detected Column Mapping</h2>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <?php foreach ($fieldLabels as $key => $label): ?>
                <div>
                    <label class="block text-xs text-slate-500 mb-1"><?= $label ?></label>
                    <select name="fieldmap_<?= $key ?>" class="w-full border border-slate-200 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-accent-400">
                        <option value="">— none —</option>
                        <?php foreach ($headers as $i => $h): ?>
                            <option value="<?= $i ?>" <?= $fieldMap[$key] === $i ? 'selected' : '' ?>><?= e($h) ?></option>
                        <?php endforeach ?>
                    </select>
                </div>
                <?php endforeach ?>
            </div>
        </div>

        <!-- CSV preview -->
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-700">CSV Preview (first 10 rows)</h2>
            </div>
            <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="bg-slate-50 text-slate-500">
                    <tr>
                        <?php foreach ($headers as $h): ?>
                            <th class="px-3 py-2 text-left font-medium whitespace-nowrap"><?= e($h) ?></th>
                        <?php endforeach ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($preview as $row): ?>
                    <tr class="hover:bg-slate-50">
                        <?php foreach ($row as $cell): ?>
                            <td class="px-3 py-1.5 text-slate-600 whitespace-nowrap"><?= e($cell) ?></td>
                        <?php endforeach ?>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
            </div>
        </div>

        <!-- Client matching -->
        <?php if ($matches): ?>
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
            <div class="px-5 py-3 border-b border-slate-200">
                <h2 class="text-sm font-semibold text-slate-700">Client Matching</h2>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wide">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Hiveage Client Name</th>
                        <th class="px-4 py-2.5 text-left">Best Match</th>
                        <th class="px-4 py-2.5 text-center">Score</th>
                        <th class="px-4 py-2.5 text-left">Use Client</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($matches as $hiveName => $match): ?>
                    <tr class="hover:bg-slate-50">
                        <td class="px-4 py-2.5 font-medium text-slate-700"><?= e($hiveName) ?></td>
                        <td class="px-4 py-2.5 text-slate-500">
                            <?= $match['client_name'] ? e($match['client_name']) : '<span class="text-slate-300">No match</span>' ?>
                        </td>
                        <td class="px-4 py-2.5 text-center">
                            <?php if ($match['score'] >= 70): ?>
                                <span class="text-green-600 font-medium"><?= $match['score'] ?>%</span>
                            <?php elseif ($match['score'] > 0): ?>
                                <span class="text-amber-500"><?= $match['score'] ?>%</span>
                            <?php else: ?>
                                <span class="text-slate-300">—</span>
                            <?php endif ?>
                        </td>
                        <td class="px-4 py-2.5">
                            <select name="client_<?= md5($hiveName) ?>"
                                    class="border border-slate-200 rounded px-2 py-0.5 text-xs text-slate-600 focus:outline-none focus:ring-1 focus:ring-accent-400">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($crmClients as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($match['score'] >= 70 && $match['client_id'] == $c['id']) ? 'selected' : '' ?>>
                                        <?= e($c['name']) ?>
                                    </option>
                                <?php endforeach ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach ?>
                </tbody>
            </table>
        </div>
        <?php endif ?>

        <div class="flex gap-3">
            <button type="submit" class="px-4 py-2 bg-accent-600 text-white text-sm font-medium rounded hover:bg-accent-700">
                Confirm Import
            </button>
            <a href="/settings/import/hiveage" class="px-4 py-2 text-sm border border-slate-300 rounded hover:bg-slate-50 text-slate-600">
                Cancel
            </a>
        </div>

    </form>

    <?php endif ?>

</div>
