<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Controllers\CartController;
use App\Core\Auth;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$controller = new CartController();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? 'get';

if ($method === 'POST') {
    Auth::validateCsrfFromRequest();
}

$user = Auth::requireApiAuth(['admin', 'manager', 'cashier']);
$limit = RateLimiter::hit('api:cart:' . $method . ':' . (int) $user['id'] . ':' . Auth::clientIp(), 240, 60);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests']);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    $payload = [];
}

try {
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => $controller->get()], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'add') {
        $productId = (int) ($payload['product_id'] ?? 0);
        $qty = (int) ($payload['qty'] ?? 1);
        $result = $controller->add($productId, $qty);
    } elseif ($action === 'remove') {
        $productId = (int) ($payload['product_id'] ?? 0);
        $result = $controller->remove($productId);
    } elseif ($action === 'clear') {
        $result = $controller->clear();
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Unknown action'], JSON_THROW_ON_ERROR);
        exit;
    }

    if (($result['ok'] ?? false) !== true) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Validation failed'], JSON_THROW_ON_ERROR);
        exit;
    }

    echo json_encode(['success' => true, 'message' => $result['message'] ?? 'OK', 'data' => $result['cart']], JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    error_log('cart API failure: ' . $throwable->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error']);
}
