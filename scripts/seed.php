<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);
$dbPath = $basePath . '/data/crm.db';

$db = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$db->exec('PRAGMA foreign_keys = ON');

// Wipe existing seed data (FK-safe order)
foreach ([
    'agreement_work_log', 'agreements',
    'expenses', 'projects',
    'recurring_cost_clients', 'recurring_costs',
    'freeagent_recurring_invoices',
    'client_sites', 'domains', 'servers', 'clients',
] as $t) {
    try {
        $db->exec("DELETE FROM $t");
        $db->exec("DELETE FROM sqlite_sequence WHERE name='$t'");
    } catch (Throwable $e) {
        echo "  (skipped $t: {$e->getMessage()})\n";
    }
}

echo "Seeding...\n";

// ── Clients ────────────────────────────────────────────────────────────────
$clientStmt = $db->prepare("INSERT INTO clients (name, status, contact_name, contact_email, notes, client_type) VALUES (?,?,?,?,?,?)");
$clients = [
    ['Apex Roofing Ltd',       'active',   'Dave Hutchinson',  'dave@apexroofing.co.uk',    'Longstanding client. Prefers phone calls.', 'managed'],
    ['Lune Valley Deli',       'active',   'Sarah Thornton',   'sarah@lunevalleydeli.co.uk', 'Small local deli, WordPress site + monthly retainer.', 'managed'],
    ['Morwick Architecture',   'active',   'James Morwick',    'james@morwick.co.uk',        'Architecture firm. Craft CMS rebuild completed 2024.', 'managed'],
    ['Pinnacle Lettings',      'active',   'Rachel Clarke',    'rachel@pinnaclelettings.com','Property lettings agency, needs quarterly updates.', 'managed'],
    ['Blue Fin Charters',      'active',   'Tom Reeve',        'tom@bluefincharters.co.uk',  'Seasonal business, site active April–October.', 'managed'],
    ['Hartley & Sons Joiners', 'active',   'Brian Hartley',    'brian@hartleyjoiners.co.uk', 'Static portfolio site. Very low maintenance.', 'support_only'],
    ['Greywood Studios',       'archived', 'Mia Greywood',     'mia@greywoods.co.uk',        'Archived — client went in-house.', 'managed'],
];
foreach ($clients as $c) {
    $clientStmt->execute($c);
}
echo "  clients\n";

// ── Servers ────────────────────────────────────────────────────────────────
$serverStmt = $db->prepare("INSERT INTO servers (name, provider, monthly_cost, notes) VALUES (?,?,0,?)");
$servers = [
    ['Hetzner CX21',      'Hetzner',    'Primary VPS. 2 vCPU, 4 GB RAM, 40 GB SSD. Cost tracked as a recurring cost.'],
    ['Homelab Proxmox',   'Homelab',    'Self-hosted Proxmox cluster. Electricity cost not tracked here.'],
    ['Shared Starter',    'SiteGround', 'Legacy shared hosting for older static sites. Cost tracked as a recurring cost.'],
];
foreach ($servers as $s) {
    $serverStmt->execute($s);
}
echo "  servers\n";

// ── Domains ────────────────────────────────────────────────────────────────
$domainStmt = $db->prepare("INSERT INTO domains (client_id, domain, registrar, cloudflare_proxied, renewal_date, annual_cost) VALUES (?,?,?,?,?,?)");
$domains = [
    [1, 'apexroofing.co.uk',       'Cloudflare',  1, '2027-03-12', 11.99],
    [2, 'lunevalleydeli.co.uk',    'Namecheap',   1, '2026-11-05', 10.49],
    [3, 'morwick.co.uk',           'Cloudflare',  1, '2027-01-22', 11.99],
    [4, 'pinnaclelettings.com',    '123-reg',     0, '2026-12-18', 13.99],
    [4, 'pinnaclelettings.co.uk',  '123-reg',     0, '2026-12-18', 10.99],
    [5, 'bluefincharters.co.uk',   'Namecheap',   1, '2027-06-30', 10.49],
    [6, 'hartleyjoiners.co.uk',    'Cloudflare',  0, '2027-05-14', 11.99],
    [7, 'greywoods.co.uk',         'Namecheap',   0, '2026-09-01', 10.49],
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

// ── Agreements & SLAs ──────────────────────────────────────────────────────
$agreementStmt = $db->prepare("
    INSERT INTO agreements
        (client_id, title, agreement_type, status, covers_hosting, covers_support, covers_maintenance,
         included_hours, hours_period, fee_amount, fee_currency, fee_billing_cycle,
         start_date, renewal_date, response_terms, notes)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
");
$agreements = [
    // Hours-based SLA (OSCAR/Youthscape style)
    [1, 'Support SLA 2026',            'support',       'active', 0, 1, 1, 12,   'annually', 540.00, 'GBP', 'annually',
     '2026-01-01', '2027-01-01', 'Critical issues within 4 working hours; routine requests within 2 working days.', 'Includes plugin/core updates.'],
    [6, 'Maintenance Hours 2026',      'support',       'active', 0, 1, 0, 6,    'annually', 240.00, 'GBP', 'annually',
     '2026-05-01', '2027-05-01', 'Best-effort response within 3 working days.', null],
    // Build agreements with bundled cover (Vineyard/Fusion style)
    [2, 'Website Build Agreement',     'build_bundled', 'active', 1, 1, 1, null, null,       null,   'GBP', null,
     '2024-05-01', '2026-11-01', null, 'Hosting, support and maintenance wrapped into the build agreement.'],
    [3, 'Craft Rebuild Agreement',     'build_bundled', 'active', 1, 1, 1, null, null,       null,   'GBP', null,
     '2024-06-01', '2026-09-30', null, 'Ongoing cover from the 2024 Craft CMS rebuild.'],
    // Expired example
    [5, 'Launch Support (expired)',    'support',       'expired', 0, 1, 0, 5,   'annually', 200.00, 'GBP', 'one_off',
     '2025-04-01', '2026-04-01', null, 'Post-launch support block, now lapsed.'],
];
foreach ($agreements as $a) {
    $agreementStmt->execute($a);
}
$workStmt = $db->prepare("INSERT INTO agreement_work_log (agreement_id, work_date, hours, description) VALUES (?,?,?,?)");
$work = [
    [1, '2026-02-10', 1.5,  'WordPress core + plugin updates'],
    [1, '2026-04-03', 0.75, 'Contact form spam filtering tweak'],
    [1, '2026-06-21', 2.0,  'Gallery template amends'],
    [2, '2026-06-02', 1.0,  'Content updates for summer season'],
];
foreach ($work as $w) {
    $workStmt->execute($w);
}
echo "  agreements + work log\n";

// ── Recurring costs (apportioned) ──────────────────────────────────────────
$catId = (int)$db->query("SELECT id FROM expense_categories WHERE name LIKE 'Hosting%' LIMIT 1")->fetchColumn();
if (!$catId) $catId = (int)$db->query("SELECT id FROM expense_categories LIMIT 1")->fetchColumn();
if (!$catId) {
    $db->exec("INSERT INTO expense_categories (name) VALUES ('Hosting Costs')");
    $catId = (int)$db->lastInsertId();
}
$rcStmt = $db->prepare("INSERT INTO recurring_costs (name, category_id, amount, billing_cycle, is_active, server_id, currency, provider) VALUES (?,?,?,?,1,?,?,?)");
$rcStmt->execute(['Hetzner CX21',       $catId,  4.50, 'monthly', 1,    'GBP', 'Hetzner']);
$rcStmt->execute(['SiteGround Shared',  $catId,  8.00, 'monthly', 3,    'GBP', 'SiteGround']);
$rcStmt->execute(['Mailgun Email API',  $catId, 35.00, 'annual',  null, 'GBP', 'Mailgun']);
$mailgunId = (int)$db->lastInsertId();
// Mailgun shared across the two clients that use it
$db->prepare("INSERT INTO recurring_cost_clients (recurring_cost_id, client_id) VALUES (?,?)")->execute([$mailgunId, 1]);
$db->prepare("INSERT INTO recurring_cost_clients (recurring_cost_id, client_id) VALUES (?,?)")->execute([$mailgunId, 3]);
echo "  recurring_costs\n";

// ── FreeAgent recurring invoices (revenue source for MRR/P&L) ─────────────
$friStmt = $db->prepare("
    INSERT INTO freeagent_recurring_invoices
        (freeagent_url, client_id, reference, frequency, recurring_status, net_value, sales_tax_value, total_value, currency, next_recurs_on, last_synced_at)
    VALUES (?,?,?,?,?,?,?,?, 'GBP', ?, datetime('now'))
");
$recurring = [
    ['seed://ri/1', 1, 'APEX-HOSTING',   'Monthly',   'Active', 45.00, 0, 45.00, date('Y-m-d', strtotime('+6 days'))],
    ['seed://ri/2', 2, 'LUNE-MANAGED',   'Monthly',   'Active', 55.00, 0, 55.00, date('Y-m-d', strtotime('+12 days'))],
    ['seed://ri/3', 3, 'MORWICK-HOST',   'Monthly',   'Active', 65.00, 0, 65.00, date('Y-m-d', strtotime('+3 days'))],
    ['seed://ri/4', 3, 'MORWICK-SLA',    'Annually',  'Active', 360.00, 0, 360.00, date('Y-m-d', strtotime('+80 days'))],
    ['seed://ri/5', 4, 'PINNACLE-HOST',  'Monthly',   'Active', 35.00, 0, 35.00, date('Y-m-d', strtotime('+20 days'))],
    ['seed://ri/6', 5, 'BLUEFIN-HOST',   'Monthly',   'Active', 30.00, 0, 30.00, date('Y-m-d', strtotime('+9 days'))],
    ['seed://ri/7', 6, 'HARTLEY-STATIC', 'Quarterly', 'Active', 60.00, 0, 60.00, date('Y-m-d', strtotime('+45 days'))],
    ['seed://ri/8', 2, 'LUNE-EMAIL',     'Monthly',   'Draft',  25.00, 0, 25.00, null],
];
foreach ($recurring as $r) {
    $friStmt->execute($r);
}
echo "  freeagent_recurring_invoices\n";

// ── Projects ───────────────────────────────────────────────────────────────
$projStmt = $db->prepare("INSERT INTO projects (client_id, name, income_category, income, income_target, income_invoiced, start_date, end_date, notes, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
$projects = [
    [1, 'Apex Roofing Website Rebuild',     'web_design',      2800.00, 2800.00, 2800.00, '2024-09-01', '2024-11-15', 'Full redesign, new Tailwind theme.',         'completed'],
    [3, 'Morwick Architecture Craft Build', 'web_development', 4500.00, 4500.00, 4500.00, '2024-06-01', '2024-09-30', 'Craft CMS 4 migration from WordPress.',      'completed'],
    [4, 'Pinnacle Lettings CRM Integration','web_development', 1200.00, 1200.00,  600.00, '2025-01-10', null,          'Propertybase integration, in progress.',     'active'],
    [2, 'Lune Valley Deli SEO Consultancy', 'consultancy',      650.00,  650.00,  650.00, '2025-03-01', '2025-03-31', 'One-off SEO audit and recommendations doc.', 'completed'],
    [5, 'Blue Fin Booking System',          'web_development', 1800.00, 1800.00,  900.00, '2025-04-01', null,          'Custom WP booking plugin build.',            'active'],
];
foreach ($projects as $p) {
    $projStmt->execute($p);
}
echo "  projects\n";

// ── Expenses ───────────────────────────────────────────────────────────────
$expStmt = $db->prepare("INSERT INTO expenses (name, category, amount, billing_cycle, client_id, server_id, project_id, date, notes) VALUES (?,?,?,?,?,?,?,?,?)");
$expenses = [
    ['ACF Pro License',          'plugin_licenses',   49.00, 'annual',  1,    null, null, '2026-03-15', 'Advanced Custom Fields Pro for Apex Roofing.'],
    ['Gravity Forms License',    'plugin_licenses',   59.00, 'annual',  1,    null, null, '2026-03-15', 'Gravity Forms Elite for Apex Roofing.'],
    ['WP Migrate Pro',           'plugin_licenses',   99.00, 'annual',  null, null, null, '2026-02-01', 'Shared WP Migrate Pro license.'],
    ['Craft Pro Licence',        'plugin_licenses',  279.00, 'one_off', 3,    null, null, '2024-06-15', 'Craft CMS Pro licence for Morwick.'],
];
foreach ($expenses as $e) {
    $expStmt->execute($e);
}
echo "  expenses\n";

echo "\nSeed complete.\n";
