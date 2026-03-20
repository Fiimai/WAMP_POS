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

$limit = RateLimiter::hit('api:add_to_cart:' . (int) $user['id'] . ':' . Auth::clientIp(), 240, 60);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests']);
    exit;
}

$productId = (int) ($_POST['product_id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? 1);

if ($productId < 1 || $quantity < 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Invalid product or quantity']);
    exit;
}

try {
    $controller = new CartController();
    $result = $controller->add($productId, $quantity);

    if (($result['ok'] ?? false) !== true) {
      http_response_code(422);
      echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Could not update cart']);
      exit;
    }

    $cart = $result['cart'] ?? ['items' => [], 'subtotal' => 0, 'tax' => 0, 'total' => 0];

    echo json_encode([
        'success' => true,
        'message' => $result['message'] ?? 'Cart updated',
        'cart' => array_values((array) ($cart['items'] ?? [])),
        'summary' => [
            'subtotal' => (float) ($cart['subtotal'] ?? 0),
            'tax' => (float) ($cart['tax'] ?? 0),
            'total' => (float) ($cart['total'] ?? 0),
            'tax_rate_percent' => (float) ($cart['tax_rate_percent'] ?? 0),
        ],
    ]);
} catch (Throwable $throwable) {
    error_log('add_to_cart API failure: ' . $throwable->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
