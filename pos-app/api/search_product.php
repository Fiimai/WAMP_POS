<?php

declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\RateLimiter;

header('Content-Type: application/json; charset=utf-8');

$query = trim((string) ($_GET['query'] ?? ''));

$user = Auth::requireApiAuth(['admin', 'manager', 'cashier']);
$limit = RateLimiter::hit('api:search:' . (int) $user['id'] . ':' . Auth::clientIp(), 120, 60);
if (!$limit['allowed']) {
    header('Retry-After: ' . $limit['retry_after']);
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many requests']);
    exit;
}

try {
    $pdo = Database::connection();

    $sql = 'SELECT p.id, p.name, p.barcode, p.sku, p.unit_price, p.stock_qty, c.name AS category_name
            FROM products p
            INNER JOIN categories c ON c.id = p.category_id
            WHERE p.is_active = 1';

    if ($query !== '') {
        $sql .= ' AND (p.name LIKE :term OR p.barcode LIKE :term OR p.sku LIKE :term OR c.name LIKE :term)';
    }

    $sql .= ' ORDER BY p.name ASC LIMIT 60';

    $statement = $pdo->prepare($sql);

    if ($query !== '') {
        $term = '%' . $query . '%';
        $statement->bindValue(':term', $term, PDO::PARAM_STR);
    }

    $statement->execute();
    $products = $statement->fetchAll();

    echo json_encode([
        'success' => true,
        'query' => $query,
        'count' => count($products),
        'data' => $products,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $throwable) {
    error_log('search_product API failure: ' . $throwable->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Internal server error',
    ]);
}
