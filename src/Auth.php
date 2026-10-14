<?php
declare(strict_types=1);

/**
 * Autenticazione della dashboard (sessione PHP).
 *
 * Le credenziali provengono da config.php ('dashboard_user' + 'dashboard_pass_hash').
 * Se l'hash e' vuoto, si puo' impostare la password col setup guidato in login.php,
 * che le salva in data/auth.json (che ha priorita' sul config).
 */
final class Auth
{
    public static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }

    /** Credenziali effettive: ['user' => string, 'hash' => string]. */
    public static function creds(): array
    {
        $cfg = config();
        $user = (string)($cfg['dashboard_user'] ?? 'admin');
        $hash = (string)($cfg['dashboard_pass_hash'] ?? '');

        $override = Store::readJson('auth.json', null);
        if (is_array($override) && !empty($override['hash'])) {
            $user = (string)($override['user'] ?? $user);
            $hash = (string)$override['hash'];
        }
        return ['user' => $user, 'hash' => $hash];
    }

    /** True se la password non e' ancora stata impostata (serve setup). */
    public static function needsSetup(): bool
    {
        return self::creds()['hash'] === '';
    }

    /** Salva user+password nel setup guidato. */
    public static function setupPassword(string $user, string $password): bool
    {
        $user = trim($user) !== '' ? trim($user) : 'admin';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        return Store::writeJson('auth.json', ['user' => $user, 'hash' => $hash]);
    }

    public static function attempt(string $user, string $password): bool
    {
        $c = self::creds();
        if ($c['hash'] === '') {
            return false;
        }
        // hash_equals per il confronto dell'utente (evita timing sull'username).
        $userOk = hash_equals($c['user'], $user);
        $passOk = password_verify($password, $c['hash']);
        if ($userOk && $passOk) {
            self::startSession();
            session_regenerate_id(true);
            $_SESSION['auth_user'] = $c['user'];
            $_SESSION['auth_at'] = time();
            return true;
        }
        return false;
    }

    public static function check(): bool
    {
        self::startSession();
        return !empty($_SESSION['auth_user']);
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            header('Location: login.php');
            exit;
        }
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    // ---- Protezione CSRF per i form della dashboard ----

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function csrfCheck(?string $token): bool
    {
        self::startSession();
        return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
    }
}
