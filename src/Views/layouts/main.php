<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Coysh Digital CRM') ?> — Coysh CRM</title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body class="h-full">

<div class="flex h-full min-h-screen">

    <!-- Sidebar -->
    <nav class="w-56 shrink-0 bg-slate-900 flex flex-col">
        <div class="px-5 py-5 border-b border-slate-700">
            <span class="text-white font-semibold text-sm tracking-wide">Coysh Digital</span>
            <span class="block text-slate-400 text-xs mt-0.5">CRM</span>
        </div>

        <?php
        $currentPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

        // Check FA connection status for sidebar indicator (single cheap query)
        global $db;
        $faConnected = false;
        $ploiConnected = false;
        if (isset($db)) {
            try {
                $faRow = $db->query("SELECT access_token FROM freeagent_config WHERE id = 1")->fetch();
                $faConnected = !empty($faRow['access_token']);
                $ploiRow = $db->query("SELECT api_token FROM ploi_config WHERE id = 1")->fetch();
                $ploiConnected = !empty($ploiRow['api_token']);
            } catch (\Throwable) {}
        }

        $navItems = [
            '/'           => ['label' => 'Dashboard',  'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
            '/clients'    => ['label' => 'Clients',    'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
            '/sites'      => ['label' => 'Sites',      'icon' => 'M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9'],
            '/domains'    => ['label' => 'Domains',    'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            '/servers'    => ['label' => 'Servers',    'icon' => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01'],
            '/projects'   => ['label' => 'Projects',   'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
            '/insights'   => ['label' => 'Insights',   'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            '/expenses'   => ['label' => 'Expenses',   'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
            '/freeagent'  => ['label' => 'FreeAgent',  'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
            '/settings'   => ['label' => 'Settings',   'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
        ];
        ?>

        <ul class="flex-1 px-3 py-4 space-y-0.5">
            <?php foreach ($navItems as $path => $item): ?>
                <?php
                $isActive = ($path === '/')
                    ? ($currentPath === '/')
                    : str_starts_with($currentPath, $path);
                $cls = $isActive
                    ? 'bg-accent-600 text-white'
                    : 'text-slate-400 hover:bg-slate-800 hover:text-white';
                ?>
                <li>
                    <a href="<?= $path ?>" class="flex items-center gap-3 px-3 py-2 rounded text-sm font-medium transition-colors <?= $cls ?>">
                        <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <?php foreach (explode('M', $item['icon']) as $i => $d): if (!$d) continue; ?>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M<?= $d ?>"/>
                            <?php endforeach ?>
                        </svg>
                        <?= $item['label'] ?>
                        <?php if ($path === '/freeagent' || $path === '/settings'): ?>
                            <span class="ml-auto w-1.5 h-1.5 rounded-full <?= $faConnected ? 'bg-green-400' : 'bg-slate-600' ?>"></span>
                        <?php endif ?>
                    </a>
                </li>
            <?php endforeach ?>
        </ul>

        <!-- Integration status footer -->
        <div class="px-4 py-3 border-t border-slate-700">
            <a href="/settings/freeagent" class="flex items-center gap-2 text-xs <?= $faConnected ? 'text-green-400' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $faConnected ? 'bg-green-400' : 'bg-slate-600' ?>"></span>
                <?= $faConnected ? 'FreeAgent connected' : 'FreeAgent not connected' ?>
            </a>
            <a href="/settings/ploi" class="flex items-center gap-2 text-xs mt-1 <?= $ploiConnected ? 'text-green-400' : 'text-slate-500 hover:text-slate-300' ?>">
                <span class="w-1.5 h-1.5 rounded-full <?= $ploiConnected ? 'bg-green-400' : 'bg-slate-600' ?>"></span>
                <?= $ploiConnected ? 'Ploi connected' : 'Ploi not connected' ?>
            </a>
        </div>
    </nav>

    <!-- Main content -->
    <div class="flex-1 flex flex-col min-w-0 overflow-auto">

        <!-- Flash messages -->
        <?php foreach (getFlash() as $flash): ?>
            <?php $fc = $flash['type'] === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800' ?>
            <div class="border-b <?= $fc ?> px-6 py-3 text-sm flex items-center gap-2">
                <?= e($flash['message']) ?>
            </div>
        <?php endforeach ?>

        <!-- Breadcrumbs -->
        <?php if (!empty($breadcrumbs)): ?>
            <nav class="px-6 py-3 border-b border-slate-200 bg-white text-sm text-slate-500 flex items-center gap-1.5">
                <?php foreach ($breadcrumbs as $i => [$label, $url]): ?>
                    <?php if ($i > 0): ?><span class="text-slate-300">/</span><?php endif ?>
                    <?php if ($url && $i < count($breadcrumbs) - 1): ?>
                        <a href="<?= e($url) ?>" class="hover:text-slate-900"><?= e($label) ?></a>
                    <?php else: ?>
                        <span class="text-slate-700 font-medium"><?= e($label) ?></span>
                    <?php endif ?>
                <?php endforeach ?>
            </nav>
        <?php endif ?>

        <main class="flex-1 p-6">
            <?= $content ?>
        </main>
    </div>
</div>

</body>
</html>
