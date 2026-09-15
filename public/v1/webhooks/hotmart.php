<?php
/**
 * public/v1/webhooks/hotmart.php
 *
 * Endpoint de recebimento de webhooks da Hotmart (Webhook API 2.0).
 *
 * ── Segurança ──────────────────────────────────────────────────────────────
 * A Hotmart envia o Hottok (segredo de validação) no header HTTP:
 *   X-Hotmart-Hottok: SEU_HOTTOK_AQUI
 *
 * Configure o valor esperado na variável HOTMART_HOTTOK_SECRET do .env.
 * Obtenha o Hottok em: Hotmart → Ferramentas → Webhooks → Configurações.
 *
 * ── URL a cadastrar na Hotmart ─────────────────────────────────────────────
 *   https://api.seudominio.com/v1/webhooks/hotmart.php
 *
 * ── Eventos tratados ───────────────────────────────────────────────────────
 *   Hotmart event                → evento interno
 *   PURCHASE_APPROVED            → "approved"
 *   PURCHASE_COMPLETE            → "approved"
 *   SWITCH_PLAN                  → "approved"   (upgrade de plano)
 *   PURCHASE_REFUNDED            → "refunded"
 *   PURCHASE_CHARGEBACK          → "chargedback"
 *   PURCHASE_CANCELED            → "canceled"
 *   SUBSCRIPTION_CANCELLATION    → "canceled"
 *
 * ── Vigência ───────────────────────────────────────────────────────────────
 * Lida a partir do nome do produto / nome do plano de assinatura:
 *   Contém "anual"  → 365 dias
 *   Contém "mensal" ou nada → 30 dias
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/webhooks.php';

header('Content-Type: application/json; charset=utf-8');

// ── 1. Validar Hottok ────────────────────────────────────────────────────
$expectedHottok = env('HOTMART_HOTTOK_SECRET', '');
$receivedHottok = (string) ($_SERVER['HTTP_X_HOTMART_HOTTOK'] ?? '');

if ($expectedHottok === '' || !hash_equals($expectedHottok, $receivedHottok)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'message' => 'Hottok inválido.']);
    exit;
}

// ── 2. Leitura e validação do payload ────────────────────────────────────
$raw  = (string) file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_payload', 'message' => 'JSON inválido ou vazio.']);
    exit;
}

// ── 3. Extração dos campos da Hotmart Webhook 2.0 ─────────────────────────
// Estrutura: https://developers.hotmart.com/docs/pt-BR/webhook/
$event    = (string) ($data['event']   ?? '');
$eventData = is_array($data['data']) ? $data['data'] : [];

$buyer    = is_array($eventData['buyer'])    ? $eventData['buyer']    : [];
$purchase = is_array($eventData['purchase']) ? $eventData['purchase'] : [];
$product  = is_array($eventData['product'])  ? $eventData['product']  : [];
$subscription = is_array($eventData['subscription']) ? $eventData['subscription'] : [];

$email         = (string) ($buyer['email']             ?? '');
$name          = (string) ($buyer['name']              ?? '');
$transactionId = (string) ($purchase['transaction']    ?? '');
$productName   = (string) ($product['name']            ?? 'Craftools Studio PRO');
$planName      = (string) ($subscription['plan']['name'] ?? '');
$subscriptionId = (string) ($subscription['subscriber']['code'] ?? '');

// ── 4. Determinar vigência ────────────────────────────────────────────────
$isAnnual     = _hotmartIsAnnual($productName, $planName);
$durationDays = $isAnnual ? 365 : 30;

// ── 5. Mapear evento Hotmart → evento interno ──────────────────────────────
$approvedEvents = ['PURCHASE_APPROVED', 'PURCHASE_COMPLETE', 'SWITCH_PLAN'];
$revokedMap     = [
    'PURCHASE_REFUNDED'         => 'refunded',
    'PURCHASE_CHARGEBACK'       => 'chargedback',
    'PURCHASE_CANCELED'         => 'canceled',
    'SUBSCRIPTION_CANCELLATION' => 'canceled',
];

if (in_array($event, $approvedEvents, true)) {
    $eventType = 'approved';
} elseif (array_key_exists($event, $revokedMap)) {
    $eventType = $revokedMap[$event];
} else {
    // Eventos não mapeados: respondemos 200 para evitar retry infinito.
    http_response_code(200);
    echo json_encode(['success' => true, 'reason' => 'event_ignored', 'event' => $event]);
    exit;
}

// ── 6. Processar ──────────────────────────────────────────────────────────
$result = processPaymentWebhook([
    'provider'       => 'hotmart',
    'event_type'     => $eventType,
    'transaction_id' => $transactionId,
    'email'          => $email,
    'name'           => $name,
    'tier'           => 'premium',
    'duration_days'  => $durationDays,
    'product_name'   => $productName,
    'subscription_id'=> $subscriptionId,
    'raw_payload'    => $data,
]);

http_response_code(200);
echo json_encode($result);

// ── Helpers ────────────────────────────────────────────────────────────────

function _hotmartIsAnnual(string $productName, string $planName): bool
{
    $haystack = strtolower($productName . ' ' . $planName);
    return strpos($haystack, 'anual') !== false || strpos($haystack, 'annual') !== false;
}
