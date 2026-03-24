<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopSettings;

final class EmailService
{
    public function sendEmail(string $to, string $subject, string $body, ?string $fromName = null, ?string $fromEmail = null): bool
    {
        $settings = ShopSettings::get();

        if (!ShopSettings::isFeatureEnabled('enable_email_notifications')) {
            return false; // Email notifications are disabled
        }

        $smtpHost = $settings['smtp_host'] ?? '';
        $smtpPort = $settings['smtp_port'] ?? 587;
        $smtpUsername = $settings['smtp_username'] ?? '';
        $smtpPassword = $settings['smtp_password'] ?? '';
        $smtpEncryption = $settings['smtp_encryption'] ?? 'tls';

        if (empty($smtpHost) || empty($smtpUsername) || empty($smtpPassword)) {
            return false; // SMTP not configured
        }

        $fromEmail = $fromEmail ?? ($settings['email_from_address'] ?? '');
        $fromName = $fromName ?? ($settings['email_from_name'] ?? '');

        if (empty($fromEmail)) {
            return false; // From email not set
        }

        // For now, we'll use a simple mail() function implementation
        // In production, you should use PHPMailer or similar
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            'From: ' . ($fromName ? $fromName . ' <' . $fromEmail . '>' : $fromEmail),
            'Reply-To: ' . $fromEmail,
            'X-Mailer: PHP/' . phpversion()
        ];

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }

    public function sendWelcomeEmail(string $to, string $customerName): bool
    {
        $settings = ShopSettings::get();
        $subject = 'Welcome to ' . ($settings['shop_name'] ?? 'Our Store');

        $body = $this->getWelcomeEmailTemplate($customerName, $settings);

        return $this->sendEmail($to, $subject, $body);
    }

    public function sendOrderConfirmation(string $to, array $orderDetails): bool
    {
        $settings = ShopSettings::get();
        $subject = 'Order Confirmation - ' . ($settings['shop_name'] ?? 'Our Store');

        $body = $this->getOrderConfirmationTemplate($orderDetails, $settings);

        return $this->sendEmail($to, $subject, $body);
    }

    public function sendLowStockAlert(string $productName, int $currentStock): bool
    {
        $settings = ShopSettings::get();
        $to = $settings['email_from_address'] ?? '';

        if (empty($to)) {
            return false;
        }

        $subject = 'Low Stock Alert: ' . $productName;
        $body = $this->getLowStockAlertTemplate($productName, $currentStock, $settings);

        return $this->sendEmail($to, $subject, $body);
    }

    private function getWelcomeEmailTemplate(string $customerName, array $settings): string
    {
        $shopName = $settings['shop_name'] ?? 'Our Store';

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { background-color: " . ($settings['theme_accent_primary'] ?? '#06B6D4') . "; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .footer { background-color: #f5f5f5; padding: 10px; text-align: center; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Welcome to {$shopName}!</h1>
            </div>
            <div class='content'>
                <p>Dear {$customerName},</p>
                <p>Thank you for joining us! We're excited to have you as part of our community.</p>
                <p>You can now browse our products and make purchases through our system.</p>
                <p>Best regards,<br>The {$shopName} Team</p>
            </div>
            <div class='footer'>
                <p>{$settings['receipt_footer'] ?? 'Thank you for your business!'}</p>
            </div>
        </body>
        </html>
        ";
    }

    private function getOrderConfirmationTemplate(array $orderDetails, array $settings): string
    {
        $shopName = $settings['shop_name'] ?? 'Our Store';
        $currency = $settings['currency_symbol'] ?? '$';

        $itemsHtml = '';
        foreach ($orderDetails['items'] ?? [] as $item) {
            $itemsHtml .= "<tr>
                <td>{$item['name']}</td>
                <td>{$item['quantity']}</td>
                <td>{$currency}{$item['price']}</td>
                <td>{$currency}{$item['total']}</td>
            </tr>";
        }

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .header { background-color: " . ($settings['theme_accent_primary'] ?? '#06B6D4') . "; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; }
                .footer { background-color: #f5f5f5; padding: 10px; text-align: center; font-size: 12px; }
                table { width: 100%; border-collapse: collapse; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h1>Order Confirmation</h1>
                <p>Order #{$orderDetails['order_id'] ?? 'N/A'}</p>
            </div>
            <div class='content'>
                <p>Dear Customer,</p>
                <p>Thank you for your order! Here are the details:</p>

                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Quantity</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$itemsHtml}
                    </tbody>
                </table>

                <p><strong>Total: {$currency}{$orderDetails['total'] ?? '0.00'}</strong></p>

                <p>Best regards,<br>The {$shopName} Team</p>
            </div>
            <div class='footer'>
                <p>{$settings['receipt_footer'] ?? 'Thank you for your business!'}</p>
            </div>
        </body>
        </html>
        ";
    }

    private function getLowStockAlertTemplate(string $productName, int $currentStock, array $settings): string
    {
        $shopName = $settings['shop_name'] ?? 'Our Store';

        return "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .alert { background-color: #ffcccc; border: 1px solid #ff0000; padding: 10px; margin: 10px 0; }
            </style>
        </head>
        <body>
            <h2>Low Stock Alert</h2>
            <div class='alert'>
                <p><strong>Product:</strong> {$productName}</p>
                <p><strong>Current Stock:</strong> {$currentStock}</p>
                <p>This product is running low on stock. Please reorder soon.</p>
            </div>
            <p>Regards,<br>{$shopName} System</p>
        </body>
        </html>
        ";
    }
}