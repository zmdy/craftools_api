<?php
/**
 * security.php — cabeçalhos de segurança, CSRF, escape de saída e rate limiting.
 */

function applySecurityHeaders(bool $isJsonApi = false): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    if (!$isJsonApi) {
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; "
            . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
            . "font-src 'self' https://fonts.gstatic.com data:; "
            . "script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
    }

    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/** Escapa para uso seguro em HTML. */
function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ---------------------------------------------------------------------------
// CSRF — token por sessão, comparado com hash_equals (resistente a timing).
// ---------------------------------------------------------------------------

function csrfToken(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrfToken()) . '">';
}

function verifyCsrf(): bool {
    $sent = isset($_POST['_csrf']) ? (string) $_POST['_csrf'] : '';
    $real = isset($_SESSION['_csrf']) ? (string) $_SESSION['_csrf'] : '';
    return $sent !== '' && $real !== '' && hash_equals($real, $sent);
}

function requireCsrf(): void {
    if (!verifyCsrf()) {
        http_response_code(419);
        die('Sessão expirada ou requisição inválida (CSRF). Atualize a página e tente novamente.');
    }
}

// ---------------------------------------------------------------------------
// Rate limiting — janela fixa baseada em SQLite (substitui os arquivos
// .ratelimit/*.json do projeto legado, evitando I/O de arquivo por requisição
// e contention entre processos concorrentes).
// ---------------------------------------------------------------------------

function rateLimitCheck(string $bucketKey, int $maxRequests, int $windowSeconds): bool {
    $pdo = db();
    $now = time();
    $windowStart = intdiv($now, $windowSeconds) * $windowSeconds;
    $key = $bucketKey . ':' . $windowStart;

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT count FROM rate_limits WHERE bucket_key = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();

        if (!$row) {
            $pdo->prepare('INSERT INTO rate_limits (bucket_key, count, window_start) VALUES (?, 1, ?)')
                ->execute([$key, $windowStart]);
            $pdo->commit();

            // Limpeza oportunista de janelas antigas (evita crescimento indefinido da tabela).
            if (random_int(1, 200) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')
                    ->execute([$now - ($windowSeconds * 10)]);
            }
            return true;
        }

        if ((int) $row['count'] >= $maxRequests) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare('UPDATE rate_limits SET count = count + 1 WHERE bucket_key = ?')->execute([$key]);
        $pdo->commit();
        return true;
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rateLimitCheck: ' . $ex->getMessage());
        // Falha "aberta" em caso de erro de infraestrutura, para não tirar a API do ar.
        return true;
    }
}

function clientIp(): string {
    // Sem leitura de X-Forwarded-For por padrão: só é confiável se o deploy
    // estiver atrás de um proxy/load balancer conhecido (ajustar aqui nesse caso).
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** Lê um inteiro de $_GET/$_POST com limites min/max e valor padrão. */
function intInput(array $source, string $key, int $default, int $min, int $max): int {
    if (!isset($source[$key]) || !is_numeric($source[$key])) {
        return $default;
    }
    $val = (int) $source[$key];
    return max($min, min($max, $val));
}
