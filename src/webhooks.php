<?php
/**
 * webhooks.php — Serviço central de processamento de Webhooks de Pagamento.
 *
 * Suporta: Kiwify, Hotmart, e qualquer plataforma adaptada via o array
 * normalizado $params. Toda a lógica de negócio (idempotência, criação de
 * usuário, geração/renovação de token, histórico de assinatura, revogação de
 * acesso) fica aqui; os arquivos de endpoint em public/v1/webhooks/ são apenas
 * adaptadores que normalizam o payload de cada plataforma e chamam
 * processPaymentWebhook().
 *
 * Parâmetros aceitos por processPaymentWebhook():
 *   - provider       (string)  'kiwify' | 'hotmart' | 'eduzz' | 'monetizze' | ...
 *   - event_type     (string)  'approved' | 'refunded' | 'chargedback' | 'canceled'
 *                              | 'subscription_renewed' | 'ignored'
 *   - transaction_id (string)  ID único da transação/pedido na plataforma
 *   - email          (string)  E-mail do comprador
 *   - name           (string)  Nome completo do comprador
 *   - tier           (string)  'premium' | 'plus' (padrão: 'premium')
 *   - duration_days  (int)     Dias de vigência: 365 (anual) ou 30 (mensal)
 *   - product_name   (string)  Nome do produto (para histórico)
 *   - subscription_id(string)  ID da assinatura na plataforma (opcional)
 *   - raw_payload    (array)   Payload JSON original para auditoria
 *
 * Eventos de APROVAÇÃO: criam/atualizam o usuário como premium, geram ou
 *   renovam o api_token com expires_at correto.
 * Eventos de REEMBOLSO/CHARGEBACK/CANCELAMENTO: rebaixam o usuário para
 *   'free' e desativam todos os api_tokens vinculados.
 */

// ─────────────────────────────────────────────────────────────────────────────
// Listas de eventos por categoria (normalizadas internamente)
// ─────────────────────────────────────────────────────────────────────────────

const WEBHOOK_EVENTS_APPROVED = [
    'approved',
    'paid',
    'subscription_renewed',
    // mapeamentos plataforma → interno feitos nos adaptadores
];

const WEBHOOK_EVENTS_REVOKED = [
    'refunded',
    'chargedback',
    'canceled',
];

// ─────────────────────────────────────────────────────────────────────────────
// Ponto de entrada principal
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Processa a concessão ou revogação de acesso com base no webhook recebido.
 *
 * @param  array $params  Payload normalizado (ver cabeçalho do arquivo).
 * @return array{success:bool, action?:string, reason?:string, user_id?:int,
 *               email?:string, tier?:string, token?:string|null, expires_at?:string}
 */
function processPaymentWebhook(array $params): array
{
    $provider      = trim((string) ($params['provider']      ?? ''));
    $eventType     = trim((string) ($params['event_type']    ?? ''));
    $transactionId = trim((string) ($params['transaction_id'] ?? ''));
    $email         = strtolower(trim((string) ($params['email'] ?? '')));
    $name          = trim((string) ($params['name']          ?? 'Cliente Craftools'));
    $tier          = in_array($params['tier'] ?? 'premium', ['free', 'plus', 'premium'], true)
                         ? (string) $params['tier']
                         : 'premium';
    $durationDays  = max(1, (int) ($params['duration_days'] ?? 365));
    $productName   = trim((string) ($params['product_name'] ?? 'Craftools Studio PRO'));
    $subscriptionId = trim((string) ($params['subscription_id'] ?? ''));
    $rawPayload    = $params['raw_payload'] ?? [];

    // ── Validação mínima ──────────────────────────────────────────────────────
    if ($email === '' || $transactionId === '' || $provider === '') {
        _webhookLog('warn', $provider, $transactionId, 'missing_required_fields', $rawPayload);
        return ['success' => false, 'reason' => 'missing_required_fields'];
    }

    // ── Idempotência ──────────────────────────────────────────────────────────
    // Plataformas podem reenviar o mesmo evento (retry automático). Já existir
    // na webhook_events para (provider + transaction_id + event_type) = no-op.
    $stmt = db()->prepare(
        'SELECT id FROM webhook_events
         WHERE provider = ? AND transaction_id = ? AND event_type = ?
         LIMIT 1'
    );
    $stmt->execute([$provider, $transactionId, $eventType]);
    if ($stmt->fetch()) {
        return ['success' => true, 'reason' => 'already_processed'];
    }

    // ── Despacho por categoria de evento ─────────────────────────────────────
    if (in_array($eventType, WEBHOOK_EVENTS_APPROVED, true)) {
        return _webhookGrant($provider, $eventType, $transactionId, $email, $name,
                             $tier, $durationDays, $productName, $subscriptionId, $rawPayload);
    }

    if (in_array($eventType, WEBHOOK_EVENTS_REVOKED, true)) {
        return _webhookRevoke($provider, $eventType, $transactionId, $email,
                              $subscriptionId, $rawPayload);
    }

    // Evento desconhecido — registramos como 'ignored' e retornamos 200 para
    // a plataforma não ficar em retry infinito.
    _webhookEventSave($provider, $eventType, $transactionId, 'ignored', null, $rawPayload);
    return ['success' => true, 'reason' => 'event_ignored', 'event_type' => $eventType];
}

// ─────────────────────────────────────────────────────────────────────────────
// Lógica interna — Concessão de Acesso (APROVAÇÃO / RENOVAÇÃO)
// ─────────────────────────────────────────────────────────────────────────────

function _webhookGrant(
    string $provider,
    string $eventType,
    string $transactionId,
    string $email,
    string $name,
    string $tier,
    int    $durationDays,
    string $productName,
    string $subscriptionId,
    array  $rawPayload
): array {
    $expiresAt = gmdate('Y-m-d H:i:s', strtotime("+{$durationDays} days"));
    $plainToken = null;

    db()->beginTransaction();
    try {
        // 1. Buscar ou criar usuário em app_users
        $user = appUserFindByEmail($email);
        if (!$user) {
            $userId = _appUserCreateForWebhook($email, $name, $tier, $provider, $expiresAt, $transactionId);
            $user   = appUserFind($userId);
        } else {
            _appUserUpgradeForWebhook($user['id'], $tier, $provider, $expiresAt);
            $user['tier']   = $tier;
            $user['status'] = 'active';
        }

        // 2. Criar ou renovar api_token vinculado ao usuário
        $stmtTok = db()->prepare(
            'SELECT id FROM api_tokens WHERE user_id = ? AND active = 1 LIMIT 1'
        );
        $stmtTok->execute([$user['id']]);
        $existingToken = $stmtTok->fetch();

        if (!$existingToken) {
            $label    = "Token {$provider} {$tier}";
            $tokenRes = apiTokenCreate($user['id'], $label, $tier, $expiresAt);
            $plainToken = $tokenRes['raw_token'];
        } else {
            // Renovar tier e expiração do token existente
            db()->prepare(
                'UPDATE api_tokens SET tier = ?, expires_at = ?, active = 1 WHERE id = ?'
            )->execute([$tier, $expiresAt, $existingToken['id']]);
        }

        // 3. Registrar assinatura em user_subscriptions
        _subscriptionSave($user['id'], $provider, $subscriptionId, $transactionId,
                          $productName, $tier, $expiresAt);

        // 4. Registrar evento de webhook (idempotência + auditoria)
        _webhookEventSave($provider, $eventType, $transactionId, 'processed', null, $rawPayload);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        _webhookLog('error', $provider, $transactionId, $e->getMessage(), $rawPayload);
        _webhookEventSave($provider, $eventType, $transactionId, 'failed', $e->getMessage(), $rawPayload);
        return ['success' => false, 'reason' => 'internal_error', 'message' => $e->getMessage()];
    }

    return [
        'success'    => true,
        'action'     => 'granted',
        'user_id'    => (int) $user['id'],
        'email'      => $email,
        'tier'       => $tier,
        'token'      => $plainToken, // não-nulo apenas na criação inicial do token
        'expires_at' => $expiresAt,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// Lógica interna — Revogação de Acesso (REEMBOLSO / CHARGEBACK / CANCELAMENTO)
// ─────────────────────────────────────────────────────────────────────────────

function _webhookRevoke(
    string $provider,
    string $eventType,
    string $transactionId,
    string $email,
    string $subscriptionId,
    array  $rawPayload
): array {
    db()->beginTransaction();
    try {
        $user = appUserFindByEmail($email);
        if ($user) {
            // Rebaixar usuário para Free e desativar todos os tokens
            db()->prepare(
                'UPDATE app_users SET tier = "free", status = "active", updated_at = ? WHERE id = ?'
            )->execute([nowSql(), $user['id']]);

            db()->prepare(
                'UPDATE api_tokens SET active = 0 WHERE user_id = ?'
            )->execute([$user['id']]);

            // Marcar assinaturas como 'refunded'/'canceled'
            $subStatus = ($eventType === 'canceled') ? 'canceled' : 'refunded';
            db()->prepare(
                'UPDATE user_subscriptions SET status = ?, updated_at = ? WHERE user_id = ? AND status = "active"'
            )->execute([$subStatus, nowSql(), $user['id']]);
        }

        _webhookEventSave($provider, $eventType, $transactionId, 'processed', null, $rawPayload);
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        _webhookLog('error', $provider, $transactionId, $e->getMessage(), $rawPayload);
        _webhookEventSave($provider, $eventType, $transactionId, 'failed', $e->getMessage(), $rawPayload);
        return ['success' => false, 'reason' => 'internal_error', 'message' => $e->getMessage()];
    }

    return ['success' => true, 'action' => 'revoked', 'email' => $email];
}

// ─────────────────────────────────────────────────────────────────────────────
// Helpers internos
// ─────────────────────────────────────────────────────────────────────────────

function _appUserCreateForWebhook(
    string $email,
    string $name,
    string $tier,
    string $provider,
    string $expiresAt,
    string $transactionId
): int {
    return repoInsert('app_users', [
        'uuid'                  => uuidv4(),
        'name'                  => $name !== '' ? $name : 'Cliente Craftools',
        'email'                 => $email,
        'tier'                  => $tier,
        'status'                => 'active',
        'notes'                 => "Criado via webhook {$provider} (tx: {$transactionId})",
        'subscription_provider' => $provider,
        'expires_at'            => $expiresAt,
        'created_at'            => nowSql(),
        'updated_at'            => nowSql(),
    ]);
}

function _appUserUpgradeForWebhook(
    int    $userId,
    string $tier,
    string $provider,
    string $expiresAt
): void {
    db()->prepare(
        'UPDATE app_users
         SET tier = ?, status = "active", subscription_provider = ?, expires_at = ?, updated_at = ?
         WHERE id = ?'
    )->execute([$tier, $provider, $expiresAt, nowSql(), $userId]);
}

function _subscriptionSave(
    int    $userId,
    string $provider,
    string $subscriptionId,
    string $transactionId,
    string $productName,
    string $tier,
    string $expiresAt
): void {
    repoInsert('user_subscriptions', [
        'uuid'                      => uuidv4(),
        'user_id'                   => $userId,
        'provider'                  => $provider,
        'external_subscription_id'  => $subscriptionId !== '' ? $subscriptionId : null,
        'external_transaction_id'   => $transactionId,
        'product_name'              => $productName,
        'tier'                      => $tier,
        'status'                    => 'active',
        'starts_at'                 => nowSql(),
        'expires_at'                => $expiresAt,
        'created_at'                => nowSql(),
        'updated_at'                => nowSql(),
    ]);
}

function _webhookEventSave(
    string  $provider,
    string  $eventType,
    string  $transactionId,
    string  $status,
    ?string $errorMessage,
    array   $rawPayload
): void {
    try {
        repoInsert('webhook_events', [
            'uuid'           => uuidv4(),
            'provider'       => $provider,
            'event_type'     => $eventType,
            'transaction_id' => $transactionId,
            'payload_json'   => json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status'         => $status,
            'error_message'  => $errorMessage,
            'created_at'     => nowSql(),
        ]);
    } catch (Throwable $e) {
        // Se o log do evento falhar (ex: transação já em rollback), apenas
        // escrevemos no error_log para não mascarar o erro original.
        error_log("[webhooks] _webhookEventSave failed: " . $e->getMessage());
    }
}

function _webhookLog(string $level, string $provider, string $txId, string $msg, array $payload): void
{
    $entry = json_encode([
        'ts'          => date('c'),
        'level'       => $level,
        'provider'    => $provider,
        'transaction' => $txId,
        'msg'         => $msg,
        'payload'     => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $dir = CRAFTOOLS_API_STORAGE . '/logs/webhooks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($dir . '/' . gmdate('Y-m-d') . '.jsonl', $entry . "\n", FILE_APPEND | LOCK_EX);
}
