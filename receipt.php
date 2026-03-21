<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Models\ShopSettings;

Auth::requirePageAuth(['admin', 'manager', 'cashier']);

$saleId = (int) ($_GET['sale_id'] ?? 0);
$autoPrint = ((string) ($_GET['print'] ?? '0')) === '1';

if ($saleId < 1) {
    http_response_code(400);
    exit('Invalid sale id');
}

$shop = ShopSettings::get();

/**
 * @param array<string, mixed> $sale
 * @param array<int, array<string, mixed>> $items
 * @param array<string, mixed> $shop
 */
function renderReceiptHtml(array $sale, array $items, array $shop): string
{
    $currency = (string) ($shop['currency_symbol'] ?? '$');
    $shopName = (string) ($shop['shop_name'] ?? 'My Shop');
  $shopBrand = strtoupper($shopName) . ' POS';
  $logoUrl = trim((string) ($shop['shop_logo_url'] ?? ''));
  $tagline = trim((string) ($shop['business_tagline'] ?? ''));
    $address = trim((string) ($shop['shop_address'] ?? ''));
    $phone = trim((string) ($shop['shop_phone'] ?? ''));
    $taxId = trim((string) ($shop['shop_tax_id'] ?? ''));
    $header = trim((string) ($shop['receipt_header'] ?? ''));
    $footer = trim((string) ($shop['receipt_footer'] ?? ''));

    ob_start();
    ?>
    <div class="receipt">
      <div class="center">
        <?php if ($logoUrl !== ''): ?><p><img src="<?= e($logoUrl) ?>" alt="Shop logo" style="max-height:42px; max-width:100%;" /></p><?php endif; ?>
        <h1><?= e($shopName) ?></h1>
        <?php if ($tagline !== ''): ?><p><?= e($tagline) ?></p><?php endif; ?>
        <?php if ($address !== ''): ?><p><?= e($address) ?></p><?php endif; ?>
        <?php if ($phone !== ''): ?><p>Tel: <?= e($phone) ?></p><?php endif; ?>
        <?php if ($taxId !== ''): ?><p>Tax ID: <?= e($taxId) ?></p><?php endif; ?>
        <?php if ($header !== ''): ?><p><?= e($header) ?></p><?php endif; ?>
      </div>

      <div class="dash"></div>

      <div class="meta">
        <p><strong>Receipt:</strong> <?= e((string) $sale['receipt_no']) ?></p>
        <p><strong>Sale ID:</strong> <?= (int) $sale['id'] ?></p>
        <p><strong>Date:</strong> <?= e((string) $sale['sold_at']) ?></p>
        <p><strong>Cashier:</strong> <?= e((string) $sale['cashier_name']) ?></p>
        <p><strong>Pay:</strong> <?= e((string) $sale['payment_method']) ?></p>
      </div>

      <div class="dash"></div>

      <table>
        <thead>
          <tr>
            <th>Item</th>
            <th class="num">Qty</th>
            <th class="num">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $item): ?>
            <tr>
              <td>
                <?= e((string) $item['product_name']) ?><br />
                <span class="muted"><?= $currency ?><?= number_format((float) $item['unit_price'], 2) ?></span>
              </td>
              <td class="num"><?= (int) $item['qty'] ?></td>
              <td class="num"><?= $currency ?><?= number_format((float) $item['line_total'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div class="dash"></div>

      <div class="totals">
        <p><span>Subtotal</span><span><?= $currency ?><?= number_format((float) $sale['subtotal'], 2) ?></span></p>
        <p><span>Tax</span><span><?= $currency ?><?= number_format((float) $sale['tax_amount'], 2) ?></span></p>
        <?php if ((float) $sale['discount_amount'] > 0): ?>
          <p><span>Discount</span><span>-<?= $currency ?><?= number_format((float) $sale['discount_amount'], 2) ?></span></p>
        <?php endif; ?>
        <p class="grand"><span>Total</span><span><?= $currency ?><?= number_format((float) $sale['total_amount'], 2) ?></span></p>
      </div>

      <div class="dash"></div>

      <div class="center">
        <?php if ($footer !== ''): ?><p><?= e($footer) ?></p><?php endif; ?>
        <p>Powered by <?= e($shopBrand) ?></p>
      </div>
    </div>
    <?php

    return (string) ob_get_clean();
}

try {
    $pdo = Database::connection();

    $saleStmt = $pdo->prepare(
        'SELECT s.id, s.receipt_no, s.sold_at, s.subtotal, s.tax_amount, s.discount_amount, s.total_amount,
                s.payment_method, u.full_name AS cashier_name
         FROM sales s
         INNER JOIN users u ON u.id = s.cashier_user_id
         WHERE s.id = :sale_id
         LIMIT 1'
    );
    $saleStmt->execute([':sale_id' => $saleId]);
    $sale = $saleStmt->fetch(PDO::FETCH_ASSOC);

    if ($sale === false) {
        http_response_code(404);
        exit('Sale not found');
    }

    $itemsStmt = $pdo->prepare(
        'SELECT si.qty, si.unit_price, si.line_total, p.name AS product_name
         FROM sale_items si
         INNER JOIN products p ON p.id = si.product_id
         WHERE si.sale_id = :sale_id
         ORDER BY si.id ASC'
    );
    $itemsStmt->execute([':sale_id' => $saleId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $throwable) {
    error_log('receipt rendering failure: ' . $throwable->getMessage());
    http_response_code(500);
    exit('Unable to load receipt');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Receipt #<?= (int) $sale['id'] ?></title>
  <style>
    :root {
      color-scheme: light;
    }

    body {
      margin: 0;
      background: #eef1f6;
      font-family: "Courier New", Courier, monospace;
      display: flex;
      justify-content: center;
      padding: 14px;
    }

    .receipt {
      width: 80mm;
      background: #fff;
      color: #000;
      border: 1px dashed #222;
      padding: 10px;
      box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
      font-size: 12px;
      line-height: 1.35;
    }

    .center {
      text-align: center;
    }

    h1 {
      font-size: 16px;
      margin: 0 0 4px;
      letter-spacing: 0.02em;
    }

    p {
      margin: 1px 0;
    }

    .dash {
      border-top: 1px dashed #111;
      margin: 8px 0;
    }

    .meta p {
      display: flex;
      justify-content: space-between;
      gap: 8px;
    }

    table {
      width: 100%;
      border-collapse: collapse;
    }

    th,
    td {
      padding: 3px 0;
      vertical-align: top;
    }

    th {
      text-align: left;
      border-bottom: 1px dashed #111;
      font-weight: 700;
    }

    td {
      border-bottom: 1px dotted #bbb;
    }

    .num {
      text-align: right;
      white-space: nowrap;
      padding-left: 8px;
    }

    .muted {
      color: #444;
      font-size: 11px;
    }

    .totals p {
      display: flex;
      justify-content: space-between;
      margin: 2px 0;
    }

    .totals .grand {
      margin-top: 4px;
      font-weight: 700;
      font-size: 14px;
    }

    .actions {
      margin-top: 10px;
      display: flex;
      justify-content: center;
      gap: 8px;
    }

    .actions button {
      border: 1px solid #111;
      background: #fff;
      padding: 6px 8px;
      cursor: pointer;
      font: inherit;
    }

    @media print {
      body {
        background: #fff;
        padding: 0;
      }

      .receipt {
        box-shadow: none;
        border: 0;
        width: 80mm;
      }

      .actions {
        display: none;
      }
    }
  </style>
</head>
<body>
  <div>
    <?= renderReceiptHtml($sale, $items, $shop) ?>
    <div class="actions">
      <button type="button" onclick="window.print()">Print</button>
      <button type="button" onclick="window.close()">Close</button>
    </div>
  </div>

  <script>
    const autoPrint = <?= $autoPrint ? 'true' : 'false' ?>;
    if (autoPrint) {
      setTimeout(() => {
        window.print();
      }, 180);
    }
  </script>
</body>
</html>

