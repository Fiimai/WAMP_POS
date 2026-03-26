<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Models\Product;
use App\Models\ShopSettings;
use App\Repositories\AuditLogRepository;
use App\Repositories\InventoryMovementRepository;
use App\Services\EmailService;
use PDO;
use Throwable;

final class CartController
{
    private static ?array $salesColumnMap = null;

    public function get(): array
    {
        return $this->withTotals($this->cartItems());
    }

    public function add(int $productId, int $qty = 1): array
    {
        if ($qty < 1) {
            return ['ok' => false, 'message' => 'Quantity must be at least 1'];
        }

        $product = Product::findById($productId);
        if ($product === null || (int) $product['is_active'] !== 1) {
            return ['ok' => false, 'message' => 'Product not available'];
        }

        if ((int) $product['stock_qty'] <= 0) {
            return ['ok' => false, 'message' => 'Product is out of stock'];
        }

        $cart = $this->cartItems();
        $key = (string) $productId;

        if (isset($cart[$key])) {
            $cart[$key]['qty'] += $qty;
        } else {
            $cart[$key] = [
                'product_id' => $productId,
                'name' => $product['name'],
                'price' => (float) $product['unit_price'],
                'qty' => $qty,
            ];
        }

        $maxStock = (int) $product['stock_qty'];
        if ($cart[$key]['qty'] > $maxStock) {
            $cart[$key]['qty'] = $maxStock;
        }

        $_SESSION['cart'] = $cart;

        return ['ok' => true, 'message' => 'Added to cart', 'cart' => $this->withTotals($cart)];
    }

    public function remove(int $productId): array
    {
        $cart = $this->cartItems();

        $key = (string) $productId;
        if (isset($cart[$key])) {
            $cart[$key]['qty'] = max(0, (int) ($cart[$key]['qty'] ?? 0) - 1);

            if ($cart[$key]['qty'] <= 0) {
                unset($cart[$key]);
            }
        }

        $_SESSION['cart'] = $cart;

        return ['ok' => true, 'message' => 'Cart item updated', 'cart' => $this->withTotals($cart)];
    }

    public function clear(): array
    {
        $_SESSION['cart'] = [];

        return ['ok' => true, 'message' => 'Cart cleared', 'cart' => $this->withTotals([])];
    }

    public function checkout(
        int $cashierUserId,
        string $paymentMethod = 'cash',
        float $discountAmount = 0,
        ?string $customerName = null,
        ?string $customerContact = null,
        ?string $deliveryNote = null,
        bool $customerConsent = false
    ): array
    {
        $allowedMethods = ['cash', 'card', 'mobile', 'mixed'];
        if (!in_array($paymentMethod, $allowedMethods, true)) {
            return ['ok' => false, 'message' => 'Unsupported payment method'];
        }

        $cart = $this->cartItems();
        if ($cart === []) {
            return ['ok' => false, 'message' => 'Cart is empty'];
        }

        $customerName = $customerName !== null ? trim($customerName) : '';
        $customerContact = $customerContact !== null ? trim($customerContact) : '';
        $deliveryNote = $deliveryNote !== null ? trim($deliveryNote) : '';

        if (strlen($customerName) > 80) {
            return ['ok' => false, 'message' => 'Customer name must be 80 characters or less.'];
        }

        if (strlen($customerContact) > 120) {
            return ['ok' => false, 'message' => 'Customer contact must be 120 characters or less.'];
        }

        if (strlen($deliveryNote) > 255) {
            return ['ok' => false, 'message' => 'Delivery note must be 255 characters or less.'];
        }

        $hasCustomerInfo = $customerName !== '' || $customerContact !== '' || $deliveryNote !== '';
        if ($hasCustomerInfo && !$customerConsent) {
            return ['ok' => false, 'message' => 'Customer consent is required to save contact details.'];
        }

        $cartWithTotals = $this->withTotals($cart);
        $items = $cartWithTotals['items'];
        $taxRatePercent = $this->taxRatePercent();
        $taxRate = $taxRatePercent / 100;

        $pdo = Database::connection();

        $auditSafetyMessage = $this->checkoutAuditSafetyMessage($pdo);
        if ($auditSafetyMessage !== null) {
            return ['ok' => false, 'message' => $auditSafetyMessage];
        }

        try {
            $pdo->beginTransaction();

            $validatedItems = [];
            foreach ($items as $item) {
                $productId = (int) $item['product_id'];
                $qty = (int) $item['qty'];

                $lockStmt = $pdo->prepare(
                    'SELECT id, name, unit_price, stock_qty, is_active
                     FROM products
                     WHERE id = :id
                     FOR UPDATE'
                );
                $lockStmt->execute([':id' => $productId]);
                $product = $lockStmt->fetch();

                if ($product === false || (int) $product['is_active'] !== 1) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Product is unavailable during checkout'];
                }

                if ((int) $product['stock_qty'] < $qty) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => 'Insufficient stock for ' . $product['name']];
                }

                $unitPrice = (float) $product['unit_price'];
                $lineTotal = round($unitPrice * $qty, 2);
                $stockBefore = (int) $product['stock_qty'];
                $stockAfter = $stockBefore - $qty;

                $validatedItems[] = [
                    'product_id' => $productId,
                    'name' => (string) $product['name'],
                    'qty' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                ];
            }

            $subtotal = array_reduce(
                $validatedItems,
                static fn (float $sum, array $row): float => $sum + (float) $row['line_total'],
                0.0
            );
            $subtotal = round($subtotal, 2);
            $discountedSubtotal = max(0, $subtotal - $discountAmount);
            $tax = round($discountedSubtotal * $taxRate, 2);
            $total = round($discountedSubtotal + $tax, 2);

            $receiptNo = 'RCP-' . date('YmdHis') . '-' . random_int(1000, 9999);

            $saleColumns = [
                'receipt_no',
                'cashier_user_id',
                'sold_at',
                'subtotal',
                'tax_amount',
                'discount_amount',
                'total_amount',
                'payment_method',
            ];

            $saleValues = [
                ':receipt_no',
                ':cashier_user_id',
                'NOW()',
                ':subtotal',
                ':tax_amount',
                ':discount_amount',
                ':total_amount',
                ':payment_method',
            ];

            $saleParams = [
                ':receipt_no' => $receiptNo,
                ':cashier_user_id' => $cashierUserId,
                ':subtotal' => $subtotal,
                ':tax_amount' => $tax,
                ':discount_amount' => $discountAmount,
                ':total_amount' => $total,
                ':payment_method' => $paymentMethod,
            ];

            if (self::hasSalesColumn($pdo, 'customer_name')) {
                $saleColumns[] = 'customer_name';
                $saleValues[] = ':customer_name';
                $saleParams[':customer_name'] = $customerName !== '' ? $customerName : null;
            }

            if (self::hasSalesColumn($pdo, 'customer_contact')) {
                $saleColumns[] = 'customer_contact';
                $saleValues[] = ':customer_contact';
                $saleParams[':customer_contact'] = $customerContact !== '' ? $customerContact : null;
            }

            if (self::hasSalesColumn($pdo, 'delivery_note')) {
                $saleColumns[] = 'delivery_note';
                $saleValues[] = ':delivery_note';
                $saleParams[':delivery_note'] = $deliveryNote !== '' ? $deliveryNote : null;
            }

            if (self::hasSalesColumn($pdo, 'customer_consent_at')) {
                $saleColumns[] = 'customer_consent_at';
                $saleValues[] = $hasCustomerInfo && $customerConsent ? 'NOW()' : 'NULL';
            }

            $saleStmt = $pdo->prepare(
                'INSERT INTO sales (' . implode(', ', $saleColumns) . ')
                 VALUES (' . implode(', ', $saleValues) . ')'
            );
            $saleStmt->execute($saleParams);

            $saleId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO sale_items (sale_id, product_id, qty, unit_price, discount_amount, line_total)
                 VALUES (:sale_id, :product_id, :qty, :unit_price, 0, :line_total)'
            );
            $stockStmt = $pdo->prepare(
                'UPDATE products
                 SET stock_qty = stock_qty - :qty
                 WHERE id = :product_id'
            );
            $movementRepo = new InventoryMovementRepository();

            foreach ($validatedItems as $row) {
                $itemStmt->execute([
                    ':sale_id' => $saleId,
                    ':product_id' => $row['product_id'],
                    ':qty' => $row['qty'],
                    ':unit_price' => $row['unit_price'],
                    ':line_total' => $row['line_total'],
                ]);

                $stockStmt->execute([
                    ':qty' => $row['qty'],
                    ':product_id' => $row['product_id'],
                ]);

                $movementRepo->record(
                    (int) $row['product_id'],
                    $cashierUserId,
                    'sale',
                    (int) $row['qty'] * -1,
                    (int) $row['stock_before'],
                    (int) $row['stock_after'],
                    'sale',
                    $saleId,
                    'Sale ' . $receiptNo,
                    $pdo
                );
            }

            $pdo->commit();

            $emailStatus = null;
            $customerEmail = $customerContact;
            if ($customerEmail !== '') {
                if (filter_var($customerEmail, FILTER_VALIDATE_EMAIL) === false) {
                    $emailStatus = [
                        'sent' => false,
                        'message' => 'Customer contact is not an email address. Sale completed without email.',
                    ];
                } else {
                    try {
                        $emailService = new EmailService();
                        $emailSent = $emailService->sendOrderConfirmation($customerEmail, [
                            'order_id' => $receiptNo,
                            'items' => array_map(
                                static fn (array $row): array => [
                                    'name' => (string) ($row['name'] ?? 'Item'),
                                    'quantity' => (int) ($row['qty'] ?? 0),
                                    'price' => number_format((float) ($row['unit_price'] ?? 0), 2, '.', ''),
                                    'total' => number_format((float) ($row['line_total'] ?? 0), 2, '.', ''),
                                ],
                                $validatedItems
                            ),
                            'total' => number_format($total, 2, '.', ''),
                        ]);

                        $emailStatus = [
                            'sent' => $emailSent,
                            'message' => $emailSent
                                ? 'Order confirmation email sent.'
                                : ('Order completed, but email failed: ' . ((string) ($emailService->getLastError() ?? 'Unknown error'))),
                        ];
                    } catch (Throwable $emailThrowable) {
                        error_log('checkout email failure: ' . $emailThrowable->getMessage());
                        $emailStatus = [
                            'sent' => false,
                            'message' => 'Order completed, but email failed unexpectedly.',
                        ];
                    }
                }
            }

            try {
                $auditRepo = new AuditLogRepository();
                $auditRepo->record(
                    $cashierUserId,
                    'checkout.completed',
                    'sale',
                    $saleId,
                    [
                        'receipt_no' => $receiptNo,
                        'total' => $total,
                        'payment_method' => $paymentMethod,
                        'customer_name' => $customerName !== '' ? $customerName : null,
                        'customer_contact' => $customerContact !== '' ? $customerContact : null,
                    ],
                    null,
                    null,
                    $pdo
                );
            } catch (Throwable $logThrowable) {
                error_log('audit log failure checkout.completed: ' . $logThrowable->getMessage());
            }

            $_SESSION['cart'] = [];

            return [
                'ok' => true,
                'message' => 'Checkout complete',
                'data' => [
                    'sale_id' => $saleId,
                    'receipt_no' => $receiptNo,
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'total' => $total,
                    'email_status' => $emailStatus,
                    'customer' => [
                        'name' => $customerName !== '' ? $customerName : null,
                        'contact' => $customerContact !== '' ? $customerContact : null,
                        'delivery_note' => $deliveryNote !== '' ? $deliveryNote : null,
                    ],
                ],
            ];
        } catch (Throwable $throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function cartItems(): array
    {
        return is_array($_SESSION['cart'] ?? null) ? $_SESSION['cart'] : [];
    }

    private function withTotals(array $cart): array
    {
        $items = array_values($cart);
        $subtotal = 0.0;
        $taxRatePercent = ShopSettings::taxRatePercent();
        $taxRate = $taxRatePercent / 100;

        foreach ($items as &$item) {
            $lineTotal = (float) $item['price'] * (int) $item['qty'];
            $item['line_total'] = round($lineTotal, 2);
            $subtotal += $lineTotal;
        }

        $subtotal = round($subtotal, 2);
        $tax = round($subtotal * $taxRate, 2);
        $total = round($subtotal + $tax, 2);

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'tax_rate_percent' => round($taxRatePercent, 2),
            'total' => $total,
        ];
    }

    private function taxRatePercent(): float
    {
        return ShopSettings::taxRatePercent();
    }

    private function checkoutAuditSafetyMessage(PDO $pdo): ?string
    {
        $statement = $pdo->query(
            'SELECT NOW() AS db_now, MAX(sold_at) AS latest_sale_at
             FROM sales'
        );

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $dbNowRaw = (string) ($row['db_now'] ?? '');
        $latestSaleRaw = (string) ($row['latest_sale_at'] ?? '');
        if ($dbNowRaw === '' || $latestSaleRaw === '') {
            return null;
        }

        $dbNow = strtotime($dbNowRaw);
        $latestSale = strtotime($latestSaleRaw);
        if ($dbNow === false || $latestSale === false) {
            return null;
        }

        if ($latestSale > ($dbNow + 300)) {
            return 'Checkout blocked: POS time check failed (sales are recorded in the future). Verify date/time before continuing.';
        }

        $dbYearMonth = date('Y-m', $dbNow);
        $latestYearMonth = date('Y-m', $latestSale);
        if ($latestYearMonth > $dbYearMonth) {
            return 'Checkout blocked: detected future-month sales data. Resolve period timing before new checkout to protect audit insights.';
        }

        return null;
    }

    private static function hasSalesColumn(PDO $pdo, string $column): bool
    {
        if (self::$salesColumnMap === null) {
            self::$salesColumnMap = [];

            try {
                $statement = $pdo->query('SHOW COLUMNS FROM sales');
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as $row) {
                    $name = (string) ($row['Field'] ?? '');
                    if ($name !== '') {
                        self::$salesColumnMap[$name] = true;
                    }
                }
            } catch (Throwable $throwable) {
                self::$salesColumnMap = [];
            }
        }

        return self::$salesColumnMap[$column] ?? false;
    }
}

