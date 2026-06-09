<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
define('DATA_PATH', BASE_PATH . '/data');
define('VIEW_PATH', BASE_PATH . '/src/Views');

// Autoload
require BASE_PATH . '/vendor/autoload.php';

// Error handling
$isProduction = ($_ENV['APP_ENV'] ?? getenv('APP_ENV')) === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
error_reporting(E_ALL);
ini_set('max_execution_time', '120'); // Allow longer execution for API syncs

// Detect HTTPS (directly or via reverse proxy) for cookie/header decisions.
$isHttps = (($_SERVER['HTTPS'] ?? '') === 'on')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443')
    || $isProduction;

// Baseline security headers (skip on CLI).
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "img-src 'self' data:; "
        . "style-src 'self' 'unsafe-inline'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "object-src 'none'; base-uri 'self'; "
        . "frame-ancestors 'none'; form-action 'self'"
    );
    if ($isHttps) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Hardened, authenticated sessions.
define('SESSION_IDLE_TIMEOUT', 7200);      // 2 hours
define('SESSION_ABSOLUTE_TIMEOUT', 43200); // 12 hours
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
    ]);
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
function render(string $view, array $data = [], string $title = '', string $layout = 'layouts/main'): void
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

    include VIEW_PATH . '/' . str_replace('.', '/', $layout) . '.php';
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
 * Is there a fully authenticated user this request? Enforces idle and absolute
 * session timeouts, clearing the session if either is exceeded.
 */
function isAuthenticated(): bool
{
    if (empty($_SESSION['user_id'])) {
        return false;
    }
    $now = time();
    if (isset($_SESSION['login_time']) && $now - $_SESSION['login_time'] > SESSION_ABSOLUTE_TIMEOUT) {
        logoutSession();
        return false;
    }
    if (isset($_SESSION['last_activity']) && $now - $_SESSION['last_activity'] > SESSION_IDLE_TIMEOUT) {
        logoutSession();
        return false;
    }
    $_SESSION['last_activity'] = $now;
    return true;
}

/**
 * The signed-in user row, or null. Cached per request.
 */
function currentUser(): ?array
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['user_id'])) {
        return $user = null;
    }
    global $db;
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $user = ($stmt->fetch() ?: null);
}

/**
 * Clear all authentication/session state.
 */
function logoutSession(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/**
 * Current CSRF token (created on first use).
 */
function csrfToken(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Hidden CSRF input for inclusion in forms.
 */
function csrfField(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrfToken()) . '">';
}

/**
 * Validate the CSRF token from a submitted form.
 */
function csrfCheck(): bool
{
    $sent = $_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return !empty($_SESSION['_csrf']) && is_string($sent) && hash_equals($_SESSION['_csrf'], $sent);
}

/**
 * Render stored HTML safely, allowing only whitelisted tags.
 */
function safeHtml(mixed $value): string
{
    $allowed = '<p><br><strong><em><u><s><ul><ol><li><a><h2><h3><blockquote>';
    return strip_tags((string)($value ?? ''), $allowed);
}

/**
 * Format a money value with £ prefix.
 */
function money(mixed $value): string
{
    return '£' . number_format((float)($value ?? 0), 2, '.', ',');
}

/**
 * Format a money value in the given currency.
 * formatCurrency(1200, 'USD') → "$1,200.00"
 */
function formatCurrency(mixed $amount, string $currency = 'GBP'): string
{
    $symbols = ['GBP' => '£', 'USD' => '$', 'EUR' => '€'];
    $sym     = $symbols[$currency] ?? $currency . ' ';
    return $sym . number_format((float)($amount ?? 0), 2, '.', ',');
}

/**
 * Format a non-GBP amount with its GBP equivalent.
 * For GBP amounts just returns the formatted value.
 * formatWithGBPEquivalent(29, 'USD') → "$29.00 (≈ £22.83)"
 */
function formatWithGBPEquivalent(mixed $amount, string $currency, ?\CoyshCRM\Services\ExchangeRateService $fx = null): string
{
    if ($currency === 'GBP' || $currency === null) {
        return money($amount);
    }
    $native = formatCurrency($amount, $currency);
    if ($fx === null) return $native;
    $gbp = $fx->convertToGBP((float)$amount, $currency);
    return $native . ' <span class="text-slate-400">(≈ ' . money($gbp) . ')</span>';
}

/**
 * Convert a FreeAgent API URL to its web UI URL.
 * e.g. https://api.freeagent.com/v2/invoices/123 → https://coyshdigital.freeagent.com/invoices/123
 */
function freeagentWebUrl(?string $apiUrl): ?string
{
    if (!$apiUrl) return null;
    if (!preg_match('|/v2/([\w_]+)/(\d+)|', $apiUrl, $m)) return null;
    $base = 'https://coyshdigital.freeagent.com';
    return match($m[1]) {
        'contacts'                      => "$base/contacts/{$m[2]}",
        'invoices'                      => "$base/invoices/{$m[2]}",
        'recurring_invoices'            => "$base/invoices/recurring/{$m[2]}",
        'bank_transaction_explanations' => "$base/bank_transactions/{$m[2]}",
        'bills'                         => "$base/bills/{$m[2]}",
        default                         => null,
    };
}

/**
 * Render a FreeAgent web link (opens in new tab), or plain text if no URL can be derived.
 */
function freeagentLink(?string $apiUrl, string $text, string $extraClass = ''): string
{
    $webUrl = freeagentWebUrl($apiUrl);
    if (!$webUrl) return e($text) ?: '—';
    $cls = 'hover:text-accent-600' . ($extraClass ? ' ' . $extraClass : '');
    return sprintf(
        '<a href="%s" target="_blank" rel="noopener noreferrer" class="%s">%s <span class="text-xs opacity-40">↗</span></a>',
        e($webUrl), e($cls), e($text ?: '—')
    );
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
