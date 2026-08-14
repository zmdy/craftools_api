<?php
/**
 * public/v1/webhooks/kiwify.php
 *
 * Endpoint de recebimento de webhooks da Kiwify.
 *
 * ── Segurança ──────────────────────────────────────────────────────────────
 * A Kiwify envia o token de segurança que você configurou como parâmetro GET
 * `token`. Configure no painel da Kiwify a URL:
 *
 *   https://api.seudominio.com/v1/webhooks/kiwify.php?token=SEU_TOKEN_SECRETO
 *
 * O valor esperado fica na variável KIWIFY_WEBHOOK_SECRET do .env.
 *
 * ── Eventos tratados ───────────────────────────────────────────────────────
 *   Kiwify order_status    → evento interno
 *   "paid"                 → "approved"   (Concessão de acesso PRO)
 *   "refunded"             → "refunded"   (Revogação de acesso)
 *   "chargedback"          → "chargedback"(Revogação de acesso)
 *
 * Para assinaturas, o campo subscription.status é avaliado adicionalmente.
 *
 * ── Vigência ───────────────────────────────────────────────────────────────
 * O número de dias de acesso é lido do nome do produto / nome do plano:
 *   Contém "anual"  → 365 dias
 *   Contém "mensal" ou nada → 30 dias
 * Você pode ajustar isso ou criar uma tabela de produtos no admin.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../src/bootstrap.php';
require_once __DIR__ . '/../../../src/webhooks.php';

header('Content-Type: application/json; charset=utf-8');

// ── 1. Autenticação por token de segurança ────────────────────────────────
$expectedToken = env('KIWIFY_WEBHOOK_SECRET', '');
$receivedToken = (string) ($_GET['token'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $receivedToken)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized', 'message' => 'Token de webhook inválido.']);
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

// ── 3. Extração dos campos da Kiwify ──────────────────────────────────────
// Estrutura Kiwify: https://docs.kiwify.com.br/webhooks
$orderStatus    = (string) ($data['order_status'] ?? '');
$orderId        = (string) ($data['order_id']     ?? '');
$subscriptionId = (string) ($data['Subscription']['id'] ?? '');

// Prioridade de ID de transação: order_id > subscription.id
$transactionId  = $orderId !== '' ? $orderId : $subscriptionId;

$customer    = is_array($data['Customer']) ? $data['Customer'] : [];
$email       = (string) ($customer['email']     ?? '');
$name        = (string) ($customer['full_name'] ?? '');

$product     = is_array($data['Product']) ? $data['Product'] : [];
$productName = (string) ($product['product_name'] ?? 'Craftools Studio PRO');

$planName = (string) ($data['Subscription']['plan']['name'] ?? '');

// ── 4. Determinar vigência pelo nome do produto / plano ─────────────────
$isAnnual     = _kiwifyIsAnnual($productName, $planName);
$durationDays = $isAnnual ? 365 : 30;

// ── 5. Mapear status da Kiwify para o evento interno ──────────────────────
switch ($orderStatus) {
    case 'paid':
        $eventType = 'approved';
        break;
    case 'refunded':
        $eventType = 'refunded';
        break;
    case 'chargedback':
        $eventType = 'chargedback';
        break;
    default:
        // Eventos não mapeados (ex: 'waiting_payment'): respondemos 200 sem
        // processar para não ficar em retry na Kiwify.
        http_response_code(200);
        echo json_encode(['success' => true, 'reason' => 'event_ignored', 'order_status' => $orderStatus]);
        exit;
}

// ── 6. Processar ──────────────────────────────────────────────────────────
$result = processPaymentWebhook([
    'provider'       => 'kiwify',
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

function _kiwifyIsAnnual(string $productName, string $planName): bool
{
    $haystack = strtolower($productName . ' ' . $planName);
    return strpos($haystack, 'anual') !== false || strpos($haystack, 'annual') !== false;
}
