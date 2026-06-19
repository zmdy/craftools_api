<?php
/**
 * auth.php — autenticação e sessão do PAINEL ADMINISTRATIVO (admin_users).
 *
 * Substitui a senha única hardcoded do projeto legado (api/index.php,
 * `$managerPassword = getenv('MANAGER_PASSWORD') ?: 'admin123'`) por contas
 * reais com hash de senha, bloqueio por tentativas e log de auditoria.
 */

const ADMIN_MAX_ATTEMPTS = 5;
const ADMIN_LOCK_MINUTES = 15;

function passwordHashNew(string $plain): string {
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($plain, PASSWORD_ARGON2ID);
    }
    return password_hash($plain, PASSWORD_BCRYPT, ['cost' => 12]);
}

function adminFindByEmail(string $email): ?array {
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function adminCountActive(): int {
    $row = db()->query('SELECT COUNT(*) AS c FROM admin_users WHERE active = 1')->fetch();
    return (int) ($row['c'] ?? 0);
}

/**
 * @return array{0:bool,1:string} [sucesso, mensagem de erro (se houver)]
 */
function adminAttemptLogin(string $email, string $password): array {
    $ip = clientIp();

    if (!rateLimitCheck('admin_login:' . $ip, 10, 300)) {
        logLoginAttempt($ip, $email, false);
        return [false, 'Muitas tentativas de login. Aguarde alguns minutos e tente novamente.'];
    }

    $admin = adminFindByEmail($email);

    if ($admin && !empty($admin['locked_until']) && strtotime($admin['locked_until']) > time()) {
        logLoginAttempt($ip, $email, false);
        return [false, 'Conta temporariamente bloqueada por excesso de tentativas. Tente novamente mais tarde.'];
    }

    $valid = $admin
        && (int) $admin['active'] === 1
        && password_verify($password, $admin['password_hash']);

    if (!$valid) {
        logLoginAttempt($ip, $email, false);
        if ($admin) {
            $attempts = (int) $admin['failed_attempts'] + 1;
            $lockedUntil = null;
            if ($attempts >= ADMIN_MAX_ATTEMPTS) {
                $lockedUntil = gmdate('Y-m-d H:i:s', time() + ADMIN_LOCK_MINUTES * 60);
                $attempts = 0;
            }
            db()->prepare('UPDATE admin_users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
                ->execute([$attempts, $lockedUntil, $admin['id']]);
        }
        return [false, 'E-mail ou senha inválidos.'];
    }

    logLoginAttempt($ip, $email, true);
    db()->prepare('UPDATE admin_users SET failed_attempts = 0, locked_until = NULL, last_login_at = ?, last_login_ip = ? WHERE id = ?')
        ->execute([nowSql(), $ip, $admin['id']]);

    // Regenera o ID de sessão no login — mitiga fixação de sessão.
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['_started_at'] = time();
    $_SESSION['_last_seen'] = time();

    auditLog((int) $admin['id'], 'login', 'admin_users', (string) $admin['id']);

    return [true, ''];
}

function logLoginAttempt(string $ip, string $email, bool $success): void {
    db()->prepare('INSERT INTO login_attempts (ip, email, success) VALUES (?, ?, ?)')
        ->execute([$ip, mb_substr($email, 0, 190), $success ? 1 : 0]);
}

function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_id']);
}

function requireAdminLogin(): void {
    if (!isAdminLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function currentAdmin(): ?array {
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    return repoFind('admin_users', (int) $_SESSION['admin_id']);
}

function adminLogout(): void {
    if (!empty($_SESSION['admin_id'])) {
        auditLog((int) $_SESSION['admin_id'], 'logout', 'admin_users', (string) $_SESSION['admin_id']);
    }
    $_SESSION = [];
    if (session_id()) {
        session_destroy();
    }
}

function auditLog(?int $adminId, string $action, ?string $entity = null, ?string $entityId = null, ?string $details = null): void {
    db()->prepare('INSERT INTO audit_log (admin_id, action, entity, entity_id, ip, details) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$adminId, $action, $entity, $entityId, clientIp(), $details]);
}
