<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$dbPath = $basePath . '/data/crm.db';

$db = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

// Wipe existing seed data
foreach (['expenses','projects','service_packages','client_sites','domains','servers','clients'] as $t) {
    $db->exec("DELETE FROM $t");
    $db->exec("DELETE FROM sqlite_sequence WHERE name='$t'");
}

echo "Seeding...\n";

// ── Clients ────────────────────────────────────────────────────────────────
$clientStmt = $db->prepare("INSERT INTO clients (name, status, contact_name, contact_email, notes) VALUES (?,?,?,?,?)");
$clients = [
    ['Apex Roofing Ltd',       'active',   'Dave Hutchinson',  'dave@apexroofing.co.uk',    'Longstanding client. Prefers phone calls.'],
    ['Lune Valley Deli',       'active',   'Sarah Thornton',   'sarah@lunevalleydeli.co.uk', 'Small local deli, WordPress site + monthly retainer.'],
    ['Morwick Architecture',   'active',   'James Morwick',    'james@morwick.co.uk',        'Architecture firm. Craft CMS rebuild completed 2024.'],
    ['Pinnacle Lettings',      'active',   'Rachel Clarke',    'rachel@pinnaclelettings.com','Property lettings agency, needs quarterly updates.'],
    ['Blue Fin Charters',      'active',   'Tom Reeve',        'tom@bluefincharters.co.uk',  'Seasonal business, site active April–October.'],
    ['Hartley & Sons Joiners', 'active',   'Brian Hartley',    'brian@hartleyjoiners.co.uk', 'Static portfolio site. Very low maintenance.'],
    ['Greywood Studios',       'archived', 'Mia Greywood',     'mia@greywoods.co.uk',        'Archived — client went in-house.'],
];
foreach ($clients as $c) {
    $clientStmt->execute($c);
}
echo "  clients\n";

// ── Servers ────────────────────────────────────────────────────────────────
$serverStmt = $db->prepare("INSERT INTO servers (name, provider, monthly_cost, notes) VALUES (?,?,?,?)");
$servers = [
    ['Hetzner CX21',      'Hetzner',  4.50, 'Primary VPS. 2 vCPU, 4 GB RAM, 40 GB SSD.'],
    ['Homelab Proxmox',   'Homelab',  0.00, 'Self-hosted Proxmox cluster. Electricity cost not tracked here.'],
    ['Shared Starter',    'SiteGround', 8.00, 'Legacy shared hosting for older static sites.'],
];
foreach ($servers as $s) {
    $serverStmt->execute($s);
}
echo "  servers\n";

// ── Domains ────────────────────────────────────────────────────────────────
$domainStmt = $db->prepare("INSERT INTO domains (client_id, domain, registrar, cloudflare_proxied, renewal_date, annual_cost) VALUES (?,?,?,?,?,?)");
$domains = [
    [1, 'apexroofing.co.uk',       'Cloudflare',  1, '2026-03-12', 11.99],
    [2, 'lunevalleydeli.co.uk',    'Namecheap',   1, '2025-11-05', 10.49],
    [3, 'morwick.co.uk',           'Cloudflare',  1, '2026-01-22', 11.99],
    [4, 'pinnaclelettings.com',    '123-reg',     0, '2025-12-18', 13.99],
    [4, 'pinnaclelettings.co.uk',  '123-reg',     0, '2025-12-18', 10.99],
    [5, 'bluefincharters.co.uk',   'Namecheap',   1, '2026-06-30', 10.49],
    [6, 'hartleyjoiners.co.uk',    'Cloudflare',  0, '2026-05-14', 11.99],
    [7, 'greywoods.co.uk',         'Namecheap',   0, '2025-09-01', 10.49],
];
foreach ($domains as $d) {
    $domainStmt->execute($d);
}
echo "  domains\n";

// ── Client Sites ───────────────────────────────────────────────────────────
$siteStmt = $db->prepare("INSERT INTO client_sites (client_id, domain_id, server_id, website_stack, css_framework, smtp_service, git_repo, has_deployment_pipeline, notes) VALUES (?,?,?,?,?,?,?,?,?)");
$sites = [
    [1, 1, 1, 'WordPress',  'Tailwind',   'Mailgun',  'https://github.com/coysh/apex-roofing',     1, 'WP 6.5, ACF Pro, Gravity Forms.'],
    [2, 2, 1, 'WordPress',  'Tailwind',   'Brevo',    'https://github.com/coysh/lune-deli',        1, 'WooCommerce for online orders.'],
    [3, 3, 2, 'Craft CMS',  'Tailwind',   'Mailgun',  'https://github.com/coysh/morwick-arch',     1, 'Craft 4, custom project gallery.'],
    [4, 4, 1, 'WordPress',  'Bootstrap',  'Brevo',    'https://github.com/coysh/pinnacle',         0, 'Propertybase plugin integration.'],
    [5, 6, 3, 'WordPress',  'Bootstrap',  'Postmark', 'https://github.com/coysh/bluefin',          0, 'Seasonal booking plugin.'],
    [6, 7, 3, 'Static',     'None',       'None',     'https://github.com/coysh/hartley-joiners',  0, 'HTML/CSS static site, no CMS.'],
];
foreach ($sites as $s) {
    $siteStmt->execute($s);
}
echo "  client_sites\n";

// ── Service Packages ───────────────────────────────────────────────────────
$pkgStmt = $db->prepare("INSERT INTO service_packages (client_id, name, fee, billing_cycle, renewal_date, notes, is_active) VALUES (?,?,?,?,?,?,?)");
$packages = [
    [1, 'Hosting & Support',       45.00, 'monthly',  null,         'Managed WordPress hosting + 1hr support/mo.',  1],
    [1, 'Annual Maintenance',     480.00, 'annual',   '2025-07-01', 'Annual plugin/core update round.',              1],
    [2, 'Managed WordPress',       55.00, 'monthly',  null,         'Hosting, backups, updates, WooCommerce support.',1],
    [3, 'Craft CMS Hosting',       65.00, 'monthly',  null,         'VPS slice + Redis cache.',                      1],
    [3, 'Priority Support',       360.00, 'annual',   '2026-02-01', '4hr/mo support block, billed annually.',        1],
    [4, 'Managed WordPress',       35.00, 'monthly',  null,         'Hosting + basic updates.',                      1],
    [5, 'Basic Hosting',           30.00, 'monthly',  null,         'Shared hosting + annual update.',               1],
    [6, 'Static Hosting',          20.00, 'monthly',  null,         'Shared hosting only.',                          1],
    [6, 'Annual Check-up',        120.00, 'annual',   '2026-01-15', 'Annual review and content update.',             1],
    [2, 'Legacy Email Package',    25.00, 'monthly',  null,         'Old G Suite reseller package.',                 0],
];
foreach ($packages as $p) {
    $pkgStmt->execute($p);
}
echo "  service_packages\n";

// ── Projects ───────────────────────────────────────────────────────────────
$projStmt = $db->prepare("INSERT INTO projects (client_id, name, income_category, income, start_date, end_date, notes, status) VALUES (?,?,?,?,?,?,?,?)");
$projects = [
    [1, 'Apex Roofing Website Rebuild',     'web_design',      2800.00, '2024-09-01', '2024-11-15', 'Full redesign, new Tailwind theme.',                   'completed'],
    [3, 'Morwick Architecture Craft Build', 'web_development', 4500.00, '2024-06-01', '2024-09-30', 'Craft CMS 4 migration from WordPress.',                'completed'],
    [4, 'Pinnacle Lettings CRM Integration','web_development', 1200.00, '2025-01-10', null,          'Propertybase integration, in progress.',               'active'],
    [2, 'Lune Valley Deli SEO Consultancy', 'consultancy',      650.00, '2025-03-01', '2025-03-31', 'One-off SEO audit and recommendations doc.',           'completed'],
    [5, 'Blue Fin Booking System',          'web_development', 1800.00, '2025-04-01', null,          'Custom WP booking plugin build.',                     'active'],
];
foreach ($projects as $p) {
    $projStmt->execute($p);
}
echo "  projects\n";

// ── Expenses ───────────────────────────────────────────────────────────────
$expStmt = $db->prepare("INSERT INTO expenses (name, category, amount, billing_cycle, client_id, server_id, project_id, date, notes) VALUES (?,?,?,?,?,?,?,?,?)");
$expenses = [
    ['Hetzner CX21 Server',     'hosting_costs',      4.50, 'monthly', null, 1, null, '2025-04-01', 'Monthly VPS cost.'],
    ['SiteGround Shared',       'hosting_costs',      8.00, 'monthly', null, 3, null, '2025-04-01', 'Shared hosting plan.'],
    ['Mailgun Email API',        'email_hosting',     35.00, 'annual',  null, null, null, '2025-01-01', 'Transactional email, shared across clients.'],
    ['ACF Pro License',          'plugin_licenses',   49.00, 'annual',  1,    null, null, '2025-03-15', 'Advanced Custom Fields Pro for Apex Roofing.'],
    ['Gravity Forms License',    'plugin_licenses',   59.00, 'annual',  1,    null, null, '2025-03-15', 'Gravity Forms Elite for Apex Roofing.'],
    ['apexroofing.co.uk domain', 'domain_registration', 11.99, 'annual', 1,  null, null, '2026-03-12', 'Domain renewal via Cloudflare.'],
    ['Namecheap domains renewal','domain_registration', 20.98, 'annual', null,null, null, '2025-11-05', 'Lune Valley + Blue Fin domain renewals.'],
    ['WP Migrate Pro',           'plugin_licenses',   99.00, 'annual',  null, null, null, '2025-02-01', 'Shared WP Migrate Pro license.'],
];
foreach ($expenses as $e) {
    $expStmt->execute($e);
}
echo "  expenses\n";

echo "\nSeed complete.\n";
