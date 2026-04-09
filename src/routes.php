<?php

declare(strict_types=1);

use Bramus\Router\Router;

/** @var Router $router */
/** @var PDO $db */

// ── Dashboard ──────────────────────────────────────────────────────────────
$router->get('/', function () use ($db) {
    (new CoyshCRM\Controllers\DashboardController($db))->index();
});

// ── Sites (standalone) ─────────────────────────────────────────────────────
$router->get('/sites', function () use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->index();
});
$router->get('/sites/create', function () use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->create();
});
$router->post('/sites', function () use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->store();
});
$router->get('/sites/matching', function () use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->matching();
});
$router->get('/sites/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->show((int)$id);
});
$router->get('/sites/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->edit((int)$id);
});
$router->post('/sites/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->update((int)$id);
});
$router->post('/sites/(\d+)/client', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->updateClient((int)$id);
});
$router->post('/sites/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SiteController($db))->destroy((int)$id);
});

// ── Clients ────────────────────────────────────────────────────────────────
$router->get('/clients', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->index();
});
$router->get('/clients/create', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->create();
});
$router->post('/clients', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->store();
});
$router->get('/clients/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->show((int)$id);
});
$router->get('/clients/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->edit((int)$id);
});
$router->post('/clients/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->update((int)$id);
});
$router->post('/clients/(\d+)/archive', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->archive((int)$id);
});
$router->post('/clients/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->destroy((int)$id);
});
$router->get('/clients/(\d+)/merge', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->merge((int)$id);
});
$router->post('/clients/(\d+)/merge', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->doMerge((int)$id);
});
$router->post('/clients/delete-all-archived', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->destroyAllArchived();
});
$router->post('/clients/bulk-archive', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->bulkArchive();
});
$router->post('/clients/bulk-restore', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->bulkRestore();
});
$router->post('/clients/bulk-delete', function () use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->bulkDelete();
});


$router->post('/clients/(\d+)/attachments', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->uploadAttachment((int)$id);
});
$router->get('/clients/(\d+)/attachments/(\d+)/download', function ($clientId, $attachmentId) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->downloadAttachment((int)$clientId, (int)$attachmentId);
});
$router->post('/clients/(\d+)/attachments/(\d+)/delete', function ($clientId, $attachmentId) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->deleteAttachment((int)$clientId, (int)$attachmentId);
});

// ── Domains (standalone list) ─────────────────────────────────────────────
$router->get('/domains', function () use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->index();
});
$router->get('/domains/create', function () use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->create();
});
$router->post('/domains', function () use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->store();
});
$router->get('/domains/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->show((int)$id);
});
$router->get('/domains/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->edit((int)$id);
});
$router->post('/domains/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->update((int)$id);
});
$router->post('/domains/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->destroy((int)$id);
});
$router->post('/domains/bulk-delete', function () use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->bulkDelete();
});
$router->post('/domains/(\d+)/archive', function ($id) use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->archive((int)$id);
});
$router->post('/domains/(\d+)/create-recurring-cost', function ($id) use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->createRecurringCost((int)$id);
});
$router->post('/domains/bulk-archive', function () use ($db) {
    (new CoyshCRM\Controllers\DomainListController($db))->bulkArchive();
});

// ── Domains (sub-resource of client) ──────────────────────────────────────
$router->get('/clients/(\d+)/domains/create', function ($clientId) use ($db) {
    (new CoyshCRM\Controllers\DomainController($db))->create((int)$clientId);
});
$router->post('/clients/(\d+)/domains', function ($clientId) use ($db) {
    (new CoyshCRM\Controllers\DomainController($db))->store((int)$clientId);
});
$router->get('/clients/(\d+)/domains/(\d+)/edit', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\DomainController($db))->edit((int)$clientId, (int)$id);
});
$router->post('/clients/(\d+)/domains/(\d+)', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\DomainController($db))->update((int)$clientId, (int)$id);
});
$router->post('/clients/(\d+)/domains/(\d+)/delete', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\DomainController($db))->destroy((int)$clientId, (int)$id);
});

// ── Client Sites (sub-resource of client) ─────────────────────────────────
$router->get('/clients/(\d+)/sites/create', function ($clientId) use ($db) {
    (new CoyshCRM\Controllers\ClientSiteController($db))->create((int)$clientId);
});
$router->post('/clients/(\d+)/sites', function ($clientId) use ($db) {
    (new CoyshCRM\Controllers\ClientSiteController($db))->store((int)$clientId);
});
$router->get('/clients/(\d+)/sites/(\d+)/edit', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\ClientSiteController($db))->edit((int)$clientId, (int)$id);
});
$router->post('/clients/(\d+)/sites/(\d+)', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\ClientSiteController($db))->update((int)$clientId, (int)$id);
});
$router->post('/clients/(\d+)/sites/(\d+)/delete', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\ClientSiteController($db))->destroy((int)$clientId, (int)$id);
});


// ── Servers ────────────────────────────────────────────────────────────────
$router->get('/servers', function () use ($db) {
    (new CoyshCRM\Controllers\ServerController($db))->index();
});
$router->get('/servers/create', function () use ($db) {
    (new CoyshCRM\Controllers\ServerController($db))->create();
});
$router->post('/servers', function () use ($db) {
    (new CoyshCRM\Controllers\ServerController($db))->store();
});
$router->get('/servers/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ServerController($db))->edit((int)$id);
});
$router->post('/servers/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ServerController($db))->update((int)$id);
});
$router->post('/servers/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ServerController($db))->destroy((int)$id);
});

// ── Projects ───────────────────────────────────────────────────────────────
$router->get('/projects', function () use ($db) {
    (new CoyshCRM\Controllers\ProjectController($db))->index();
});
$router->get('/projects/create', function () use ($db) {
    (new CoyshCRM\Controllers\ProjectController($db))->create();
});
$router->post('/projects', function () use ($db) {
    (new CoyshCRM\Controllers\ProjectController($db))->store();
});
$router->get('/projects/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ProjectController($db))->edit((int)$id);
});
$router->post('/projects/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ProjectController($db))->update((int)$id);
});
$router->post('/projects/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ProjectController($db))->destroy((int)$id);
});

// ── Expenses ───────────────────────────────────────────────────────────────
$router->get('/expenses', function () use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->index();
});
$router->get('/expenses/create', function () use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->create();
});
$router->post('/expenses', function () use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->store();
});
$router->get('/expenses/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->edit((int)$id);
});
$router->post('/expenses/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->update((int)$id);
});
$router->post('/expenses/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->destroy((int)$id);
});
$router->post('/expenses/(\d+)/client', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->updateClient((int)$id);
});
$router->post('/expenses/(\d+)/toggle-ignore', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->toggleIgnore((int)$id);
});

// ── Recurring Costs ────────────────────────────────────────────────────────
$router->get('/expenses/recurring/create', function () use ($db) {
    (new CoyshCRM\Controllers\RecurringCostController($db))->create();
});
$router->post('/expenses/recurring', function () use ($db) {
    (new CoyshCRM\Controllers\RecurringCostController($db))->store();
});
$router->get('/expenses/recurring/(\d+)/edit', function ($id) use ($db) {
    (new CoyshCRM\Controllers\RecurringCostController($db))->edit((int)$id);
});
$router->post('/expenses/recurring/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\RecurringCostController($db))->update((int)$id);
});
$router->post('/expenses/recurring/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\RecurringCostController($db))->destroy((int)$id);
});
$router->post('/expenses/recurring/(\d+)/toggle', function ($id) use ($db) {
    (new CoyshCRM\Controllers\RecurringCostController($db))->toggle((int)$id);
});

// ── Expense Suggestions ────────────────────────────────────────────────────
$router->post('/expenses/suggestions/dismiss', function () use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->dismissSuggestion();
});

// ── FreeAgent Bills ────────────────────────────────────────────────────────
$router->post('/expenses/bills/(\d+)/dismiss', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->billDismiss((int)$id);
});

// ── Expense Categories ──────────────────────────────────────────────────────
$router->get('/expenses/categories', function () use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->categories();
});
$router->post('/expenses/categories', function () use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->storeCategory();
});
$router->post('/expenses/categories/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->updateCategory((int)$id);
});
$router->post('/expenses/categories/(\d+)/delete', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ExpenseController($db))->destroyCategory((int)$id);
});

// ── Settings ───────────────────────────────────────────────────────────────
$router->get('/settings', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->index();
});


// ── Deletion Log ───────────────────────────────────────────────────────────
$router->get('/settings/deletion-log', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->deletionLog();
});

// ── Ploi Settings ─────────────────────────────────────────────────────────-
$router->get('/settings/ploi', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->ploi();
});
$router->post('/settings/ploi', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->savePloi();
});
$router->post('/settings/ploi/test', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->testPloi();
});
$router->post('/settings/ploi/disconnect', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->disconnectPloi();
});
$router->post('/settings/ploi/sync', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->syncPloi();
});
$router->post('/settings/ploi/sync-domains', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->syncPloiDomains();
});
$router->post('/settings/ploi/exclusions/(\d+)/remove', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->removePloiExclusion((int)$id);
});

// ── Cloudflare Settings ────────────────────────────────────────────────────
$router->get('/settings/cloudflare', function () use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->settings();
});
$router->post('/settings/cloudflare', function () use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->save();
});
$router->post('/settings/cloudflare/test', function () use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->test();
});
$router->post('/settings/cloudflare/sync', function () use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->sync();
});
$router->post('/settings/cloudflare/disconnect', function () use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->disconnect();
});
$router->post('/settings/cloudflare/zones/([^/]+)/link', function ($zoneId) use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->linkZone($zoneId);
});
$router->post('/settings/cloudflare/zones/([^/]+)/unlink', function ($zoneId) use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->unlinkZone($zoneId);
});
$router->get('/domains/(\d+)/dns', function ($id) use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->dnsIndex((int)$id);
});
$router->post('/domains/(\d+)/dns', function ($id) use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->createDns((int)$id);
});
$router->post('/domains/(\d+)/dns/([^/]+)', function ($id, $recordId) use ($db) {
    (new CoyshCRM\Controllers\CloudflareController($db))->updateDns((int)$id, $recordId);
});

// ── FreeAgent Settings ─────────────────────────────────────────────────────
$router->get('/settings/freeagent', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->freeagent();
});
$router->post('/settings/freeagent', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->saveFreeagent();
});
$router->get('/settings/freeagent/connect', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->connect();
});
$router->get('/settings/freeagent/callback', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->callback();
});
$router->post('/settings/freeagent/disconnect', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->disconnect();
});
$router->get('/settings/freeagent/contacts', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->contacts();
});
$router->post('/settings/freeagent/contacts/(\d+)/map', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->saveContactMap((int)$id);
});
$router->post('/settings/freeagent/contacts/rematch', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->rematchContacts();
});
$router->post('/settings/freeagent/contacts/create-unmatched', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->createClientsForUnmatched();
});
$router->post('/settings/freeagent/contacts/(\d+)/create-client', function ($id) use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->createClientFromContact((int)$id);
});
$router->get('/settings/freeagent/categories', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->categories();
});
$router->post('/settings/freeagent/categories', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->saveCategories();
});

// ── FreeAgent Data Pages ───────────────────────────────────────────────────
$router->get('/freeagent', function () use ($db) {
    (new CoyshCRM\Controllers\FreeAgentController($db))->index();
});
$router->post('/freeagent/sync', function () use ($db) {
    (new CoyshCRM\Controllers\FreeAgentController($db))->sync();
});
$router->get('/freeagent/client/(\d+)', function ($id) use ($db) {
    (new CoyshCRM\Controllers\FreeAgentController($db))->clientData((int)$id);
});
$router->post('/freeagent/invoices/(\d+)/client', function ($id) use ($db) {
    (new CoyshCRM\Controllers\FreeAgentController($db))->updateInvoiceClient((int)$id);
});
$router->post('/freeagent/recurring/(\d+)/client', function ($id) use ($db) {
    (new CoyshCRM\Controllers\FreeAgentController($db))->updateRecurringClient((int)$id);
});

// ── Insights ───────────────────────────────────────────────────────────────
$router->get('/insights', function () use ($db) {
    (new CoyshCRM\Controllers\InsightsController($db))->index();
});
$router->get('/insights/monthly-chart', function () use ($db) {
    (new CoyshCRM\Controllers\InsightsController($db))->monthlyChart();
});
$router->get('/insights/month-detail', function () use ($db) {
    (new CoyshCRM\Controllers\InsightsController($db))->monthDetail();
});

// ── Exchange Rates ─────────────────────────────────────────────────────────
$router->post('/settings/exchange-rates/refresh', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->refreshExchangeRates();
});

// ── Hiveage Import ─────────────────────────────────────────────────────────
$router->get('/settings/import/hiveage', function () use ($db) {
    (new CoyshCRM\Controllers\HiveageController($db))->index();
});
$router->post('/settings/import/hiveage/upload', function () use ($db) {
    (new CoyshCRM\Controllers\HiveageController($db))->upload();
});
$router->post('/settings/import/hiveage/confirm', function () use ($db) {
    (new CoyshCRM\Controllers\HiveageController($db))->confirm();
});
$router->post('/settings/import/hiveage/clear', function () use ($db) {
    (new CoyshCRM\Controllers\HiveageController($db))->clear();
});

// ── 404 ────────────────────────────────────────────────────────────────────
$router->set404(function () {
    http_response_code(404);
    render('errors.404', [], '404 Not Found');
});
