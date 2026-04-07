<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
define('VIEW_PATH', BASE_PATH . '/src/Views');

// Autoload
require BASE_PATH . '/vendor/autoload.php';

// Error handling
ini_set('display_errors', '1');
error_reporting(E_ALL);

// Session for flash messages
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database
$dbPath = DATA_PATH . '/crm.db';
try {
    $db = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $db->exec('PRAGMA foreign_keys = ON');
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}

/**
 * Render a view inside the main layout.
 *
 * @param string $view   Dot-notation path relative to Views/, e.g. 'clients.index'
 * @param array  $data   Variables to extract into the view
 * @param string $layout Layout file (relative to Views/layouts/)
 */
function render(string $view, array $data = [], string $title = ''): void
{
    $viewFile = VIEW_PATH . '/' . str_replace('.', '/', $view) . '.php';

    if (!file_exists($viewFile)) {
        http_response_code(500);
        die("View not found: $viewFile");
    }

    extract($data, EXTR_SKIP);

    ob_start();
    include $viewFile;
    $content = ob_get_clean();

    $pageTitle = $title ?: ucfirst(str_replace(['.', '_', '/'], [' ', ' ', ' → '], $view));

    include VIEW_PATH . '/layouts/main.php';
}

/**
 * Flash a message into the session.
 */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Redirect to a URL.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Get and clear flash messages.
 */
function getFlash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/**
 * Sanitise a string for HTML output.
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Format a money value with £ prefix.
 */
function money(mixed $value): string
{
    return '£' . number_format((float)($value ?? 0), 2);
}

/**
 * Format a date string for display.
 */
function formatDate(?string $date): string
{
    if (!$date) return '—';
    return date('j M Y', strtotime($date));
}

/**
 * Return CSS classes for a status badge.
 */
function statusBadge(string $status): string
{
    return match($status) {
        'active'    => 'bg-green-100 text-green-800',
        'archived'  => 'bg-slate-100 text-slate-600',
        'completed' => 'bg-blue-100 text-blue-800',
        'cancelled' => 'bg-red-100 text-red-800',
        default     => 'bg-slate-100 text-slate-600',
    };
}
