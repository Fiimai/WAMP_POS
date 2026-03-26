<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\CartController;
use App\Core\Auth;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

Auth::validateCsrfFromRequest();
$user = Auth::requireApiAuth(['admin', 'manager', 'cashier']);

$limit = RateLimiter::hit('api:checkout:' . (int) $user['id'] . ':' . Auth::clientIp(), 20, 300);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many checkout attempts']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

$paymentMethod = (string) ($payload['payment_method'] ?? 'cash');
$discountAmount = (float) ($payload['discount_amount'] ?? 0);
$customerName = trim((string) ($payload['customer_name'] ?? ''));
$customerContact = trim((string) ($payload['customer_contact'] ?? ''));
$deliveryNote = trim((string) ($payload['delivery_note'] ?? ''));
$customerConsent = (bool) ($payload['customer_consent'] ?? false);

try {
    $controller = new CartController();
    $result = $controller->checkout(
        (int) $user['id'],
        $paymentMethod,
        $discountAmount,
        $customerName !== '' ? $customerName : null,
        $customerContact !== '' ? $customerContact : null,
        $deliveryNote !== '' ? $deliveryNote : null,
        $customerConsent
    );

    if (($result['ok'] ?? false) !== true) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Checkout failed']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => $result['message'] ?? 'Checkout complete', 'data' => $result['data'] ?? null]);
} catch (Throwable $throwable) {
    error_log('checkout API failure: ' . $throwable->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
