<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Sign in') ?> — Coysh CRM</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="h-full">
<div class="min-h-full flex flex-col items-center justify-center px-4 py-12">
    <div class="w-full max-w-sm">
        <div class="text-center mb-6">
            <span class="block text-slate-900 font-semibold text-lg tracking-wide">Coysh Digital</span>
            <span class="block text-slate-500 text-xs mt-0.5">CRM</span>
        </div>

        <?php foreach (getFlash() as $flash): ?>
            <?php $fc = $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>
            <div class="border <?= $fc ?> rounded px-4 py-2.5 text-sm mb-4">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach ?>

        <div class="bg-white border border-slate-200 rounded-lg shadow-sm p-6">
            <?= $content ?>
        </div>
    </div>
</div>
</body>
</html>
