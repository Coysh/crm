<nav class="flex flex-wrap gap-1 border-b border-slate-200 pb-3">
<?php foreach (['/email'=>'Overview','/email/campaigns'=>'Campaigns','/email/templates'=>'Templates','/email/segments'=>'Segments','/email/contacts'=>'Contacts','/settings/email'=>'Settings'] as $url=>$label): ?>
    <?php $active = $url === '/email' ? (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/email') : str_starts_with(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH),$url); ?>
    <a href="<?= $url ?>" class="px-3 py-1.5 rounded text-sm <?= $active?'bg-accent-600 text-white':'text-slate-600 hover:bg-slate-100' ?>"><?= $label ?></a>
<?php endforeach ?>
</nav>
