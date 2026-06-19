<?php
/**
 * api_auth.php — resolução de token e nível de acesso (tier) para as APIs
 * PÚBLICAS (/api legado e /v1 novo).
 *
 * Compatibilidade: o contrato legado (api/api/index.php) sempre exigia um
 * token. Aqui, a ausência de token é tratada como tier "free" (decisão de
 * produto: o tier gratuito do CraftTools+ não exige login/token, conforme
 * definido no plano de tiers). Tokens enviados continuam funcionando
 * exatamente como antes — apenas o *hash* SHA-256 é comparado, nunca o valor
 * em texto puro.
 */

const TIER_RANK = ['free' => 0, 'plus' => 1, 'premium' => 2];

function tierAtLeast(string $tier, string $minTier): bool {
    $a = TIER_RANK[$tier] ?? 0;
    $b = TIER_RANK[$minTier] ?? 0;
    return $a >= $b;
}

function extractBearerToken(): ?string {
    if (!empty($_GET['token'])) {
        return (string) $_GET['token'];
    }
    if (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
        return (string) $_SERVER['HTTP_X_API_TOKEN'];
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== '' && preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * @return array{present:bool, tier?:string, token_row?:array|null, error?:string}
 */
function resolveApiToken(): array {
    $raw = extractBearerToken();

    if ($raw === null || $raw === '') {
        return ['present' => false, 'tier' => 'free', 'token_row' => null];
    }

    if (!preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return ['present' => true, 'error' => 'invalid_format'];
    }

    $hash = hash('sha256', $raw);
    $stmt = db()->prepare('SELECT * FROM api_tokens WHERE token_hash = ? LIMIT 1');
    $stmt->execute([$hash]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['present' => true, 'error' => 'not_found'];
    }
    if ((int) $row['active'] !== 1) {
        return ['present' => true, 'error' => 'inactive'];
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
        return ['present' => true, 'error' => 'expired'];
    }

    db()->prepare('UPDATE api_tokens SET last_used_at = ? WHERE id = ?')->execute([nowSql(), $row['id']]);

    return ['present' => true, 'tier' => $row['tier'], 'token_row' => $row];
}

/** Gera um novo token de API (valor em texto puro, hash e prefixo de exibição). */
function generateApiTokenValue(): array {
    $raw = bin2hex(random_bytes(32)); // 64 caracteres hex — mesmo formato do projeto legado
    return [
        'raw' => $raw,
        'hash' => hash('sha256', $raw),
        'prefix' => substr($raw, 0, 8),
    ];
}
