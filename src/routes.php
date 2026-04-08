<?php

declare(strict_types=1);

use Bramus\Router\Router;

/** @var Router $router */
/** @var PDO $db */

// ── Dashboard ──────────────────────────────────────────────────────────────
$router->get('/', function () use ($db) {
    (new CoyshCRM\Controllers\DashboardController($db))->index();
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
$router->get('/clients/(\d+)/merge', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->merge((int)$id);
});
$router->post('/clients/(\d+)/merge', function ($id) use ($db) {
    (new CoyshCRM\Controllers\ClientController($db))->doMerge((int)$id);
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

// ── Service Packages (sub-resource of client) ─────────────────────────────
$router->get('/clients/(\d+)/packages/create', function ($clientId) use ($db) {
    (new CoyshCRM\Controllers\ServicePackageController($db))->create((int)$clientId);
});
$router->post('/clients/(\d+)/packages', function ($clientId) use ($db) {
    (new CoyshCRM\Controllers\ServicePackageController($db))->store((int)$clientId);
});
$router->get('/clients/(\d+)/packages/(\d+)/edit', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\ServicePackageController($db))->edit((int)$clientId, (int)$id);
});
$router->post('/clients/(\d+)/packages/(\d+)', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\ServicePackageController($db))->update((int)$clientId, (int)$id);
});
$router->post('/clients/(\d+)/packages/(\d+)/delete', function ($clientId, $id) use ($db) {
    (new CoyshCRM\Controllers\ServicePackageController($db))->destroy((int)$clientId, (int)$id);
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

// ── Settings ───────────────────────────────────────────────────────────────
$router->get('/settings', function () use ($db) {
    (new CoyshCRM\Controllers\SettingsController($db))->index();
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

// ── 404 ────────────────────────────────────────────────────────────────────
$router->set404(function () {
    http_response_code(404);
    render('errors.404', [], '404 Not Found');
});
