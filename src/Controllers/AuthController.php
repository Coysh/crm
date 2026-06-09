<?php

declare(strict_types=1);

namespace CoyshCRM\Controllers;

use CoyshCRM\Services\Secrets;
use CoyshCRM\Services\Totp;
use PDO;

class AuthController
{
    private const MAX_ATTEMPTS    = 5;
    private const LOCKOUT_SECONDS  = 900; // 15 minutes
    private const ISSUER           = 'Coysh CRM';

    public function __construct(private PDO $db) {}

    // ── First-run setup ───────────────────────────────────────────────────

    public function showSetup(): void
    {
        if ($this->userCount() > 0) {
            redirect('/login');
        }
        if (empty($_SESSION['setup_totp_secret'])) {
            $_SESSION['setup_totp_secret'] = Totp::generateSecret();
        }
        $secret     = $_SESSION['setup_totp_secret'];
        $otpauthUri = Totp::provisioningUri($secret, 'admin', self::ISSUER);
        render('auth.setup', compact('secret', 'otpauthUri'), 'Set up', 'layouts/auth');
    }

    public function setup(): void
    {
        if ($this->userCount() > 0) {
            redirect('/login');
        }
        if (!csrfCheck()) {
            flash('error', 'Your session expired. Please try again.');
            redirect('/setup');
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $confirm  = (string)($_POST['password_confirm'] ?? '');
        $code     = (string)($_POST['code'] ?? '');
        $secret   = $_SESSION['setup_totp_secret'] ?? '';

        $errors = [];
        if ($username === '')              $errors[] = 'Username is required.';
        if (strlen($password) < 12)        $errors[] = 'Password must be at least 12 characters.';
        if ($password !== $confirm)        $errors[] = 'Passwords do not match.';
        if (!$secret)                      $errors[] = 'Setup session expired — reload the page.';
        if ($secret && !Totp::verify($secret, $code)) {
            $errors[] = 'That code is incorrect — check your authenticator and try again.';
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
            $_SESSION['_old_username'] = $username;
            redirect('/setup');
        }

        $stmt = $this->db->prepare(
            "INSERT INTO users (username, password_hash, totp_secret, totp_enabled, created_at)
             VALUES (?, ?, ?, 1, datetime('now'))"
        );
        $stmt->execute([
            $username,
            password_hash($password, PASSWORD_DEFAULT),
            Secrets::encrypt($secret),
        ]);

        unset($_SESSION['setup_totp_secret'], $_SESSION['_old_username']);
        $this->establishSession((int)$this->db->lastInsertId());
        flash('success', 'Account created. You are signed in.');
        redirect('/');
    }

    // ── Login (password step) ─────────────────────────────────────────────

    public function showLogin(): void
    {
        if ($this->userCount() === 0) {
            redirect('/setup');
        }
        if (isAuthenticated()) {
            redirect('/');
        }
        $username = $_SESSION['_old_username'] ?? '';
        unset($_SESSION['_old_username']);
        render('auth.login', compact('username'), 'Sign in', 'layouts/auth');
    }

    public function login(): void
    {
        if (!csrfCheck()) {
            flash('error', 'Your session expired. Please try again.');
            redirect('/login');
        }

        $username = trim($_POST['username'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        $user = $this->findByUsername($username);

        // Uniform failure messaging to avoid user enumeration. Run a real verify
        // against a dummy hash so timing is comparable to the found-user path.
        if (!$user) {
            password_verify($password, '$2y$12$S1spowuTpA7h5C3wk1jDTu6fPdjxgjvG5YRCf2TFdXUpYyMoB90sa');
            $this->failLogin($username);
            redirect('/login');
        }

        if ($this->isLocked($user)) {
            flash('error', 'Account temporarily locked due to failed attempts. Try again later.');
            $_SESSION['_old_username'] = $username;
            redirect('/login');
        }

        if (!password_verify($password, $user['password_hash'])) {
            $this->registerFailure($user);
            $this->failLogin($username);
            redirect('/login');
        }

        // Password OK — move to the 2FA step (2FA is mandatory).
        $_SESSION['2fa_pending'] = (int)$user['id'];
        $_SESSION['2fa_started'] = time();
        redirect('/login/2fa');
    }

    // ── Login (2FA step) ──────────────────────────────────────────────────

    public function show2fa(): void
    {
        if (empty($_SESSION['2fa_pending'])) {
            redirect('/login');
        }
        render('auth.2fa', [], 'Two-factor authentication', 'layouts/auth');
    }

    public function verify2fa(): void
    {
        if (!csrfCheck()) {
            flash('error', 'Your session expired. Please try again.');
            redirect('/login');
        }
        if (empty($_SESSION['2fa_pending'])) {
            redirect('/login');
        }
        // 2FA challenge must be completed within 5 minutes.
        if (time() - (int)($_SESSION['2fa_started'] ?? 0) > 300) {
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_started']);
            flash('error', 'Verification timed out. Please sign in again.');
            redirect('/login');
        }

        $user = $this->findById((int)$_SESSION['2fa_pending']);
        if (!$user) {
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_started']);
            redirect('/login');
        }

        if ($this->isLocked($user)) {
            unset($_SESSION['2fa_pending'], $_SESSION['2fa_started']);
            flash('error', 'Account temporarily locked due to failed attempts. Try again later.');
            redirect('/login');
        }

        $secret = Secrets::decrypt($user['totp_secret'] ?? '');
        if (!$secret || !Totp::verify($secret, (string)($_POST['code'] ?? ''))) {
            $this->registerFailure($user);
            flash('error', 'Incorrect code.');
            redirect('/login/2fa');
        }

        unset($_SESSION['2fa_pending'], $_SESSION['2fa_started']);
        $this->establishSession((int)$user['id']);
        $dest = $_SESSION['intended_url'] ?? '/';
        unset($_SESSION['intended_url']);
        redirect($dest);
    }

    // ── Logout ────────────────────────────────────────────────────────────

    public function logout(): void
    {
        // Best-effort CSRF check; logging out on a failed token is still safe.
        logoutSession();
        redirect('/login');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function establishSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']       = $userId;
        $_SESSION['login_time']    = time();
        $_SESSION['last_activity'] = time();
        $this->db->prepare("UPDATE users SET last_login_at = datetime('now'), failed_attempts = 0, locked_until = NULL WHERE id = ?")
            ->execute([$userId]);
    }

    private function failLogin(string $username): never
    {
        flash('error', 'Invalid username, password, or code.');
        $_SESSION['_old_username'] = $username;
        redirect('/login');
    }

    private function registerFailure(array $user): void
    {
        $attempts = (int)$user['failed_attempts'] + 1;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockUntil = date('Y-m-d H:i:s', time() + self::LOCKOUT_SECONDS);
            $this->db->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
                ->execute([$attempts, $lockUntil, $user['id']]);
        } else {
            $this->db->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")
                ->execute([$attempts, $user['id']]);
        }
    }

    private function isLocked(array $user): bool
    {
        return !empty($user['locked_until']) && strtotime($user['locked_until']) > time();
    }

    private function userCount(): int
    {
        return (int)$this->db->query("SELECT COUNT(*) FROM users")->fetchColumn();
    }

    private function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    private function findById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
