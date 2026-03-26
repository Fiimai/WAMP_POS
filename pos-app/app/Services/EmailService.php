<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ShopSettings;

final class EmailService
{
    private ?string $lastError = null;

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function sendEmail(string $to, string $subject, string $body, ?string $fromName = null, ?string $fromEmail = null): bool
    {
        $this->lastError = null;
        $settings = ShopSettings::get();

        if (!ShopSettings::isFeatureEnabled('enable_email_notifications')) {
            $this->lastError = 'Email notifications are disabled in Settings.';
            return false;
        }

        $smtpHost = (string) (getenv('SMTP_HOST') ?: ($settings['smtp_host'] ?? ''));
        $smtpPort = (int) (getenv('SMTP_PORT') ?: ($settings['smtp_port'] ?? 587));
        $smtpUsername = (string) (getenv('SMTP_USERNAME') ?: ($settings['smtp_username'] ?? ''));
        $smtpPassword = (string) (getenv('SMTP_PASSWORD') ?: ($settings['smtp_password'] ?? ''));
        $smtpEncryption = strtolower(trim((string) (getenv('SMTP_ENCRYPTION') ?: ($settings['smtp_encryption'] ?? 'tls'))));

        if (empty($smtpHost) || empty($smtpUsername) || empty($smtpPassword)) {
            $this->lastError = 'SMTP settings are incomplete. Configure host, username, and password.';
            return false;
        }

        $fromEmail = $fromEmail ?? ((string) (getenv('SMTP_FROM_ADDRESS') ?: ($settings['email_from_address'] ?? '')));
        $fromName = $fromName ?? ((string) (getenv('SMTP_FROM_NAME') ?: ($settings['email_from_name'] ?? '')));

        if (empty($fromEmail)) {
            $this->lastError = 'From email address is not configured in Settings.';
            return false;
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Recipient email address is invalid.';
            return false;
        }

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'From email address is invalid.';
            return false;
        }

        return $this->sendViaSmtp(
            $smtpHost,
            $smtpPort,
            $smtpEncryption,
            $smtpUsername,
            $smtpPassword,
            $fromEmail,
            $fromName ?? '',
            $to,
            $subject,
            $body
        );
    }

    private function sendViaSmtp(
        string $smtpHost,
        int $smtpPort,
        string $smtpEncryption,
        string $smtpUsername,
        string $smtpPassword,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $body
    ): bool {
        $attempts = 0;
        do {
            $attempts++;
            if ($this->sendViaSmtpOnce(
                $smtpHost,
                $smtpPort,
                $smtpEncryption,
                $smtpUsername,
                $smtpPassword,
                $fromEmail,
                $fromName,
                $to,
                $subject,
                $body
            )) {
                return true;
            }

            if (!$this->isTransientFailure($this->lastError)) {
                return false;
            }

            if ($attempts < 2) {
                usleep(400000);
            }
        } while ($attempts < 2);

        return false;
    }

    private function sendViaSmtpOnce(
        string $smtpHost,
        int $smtpPort,
        string $smtpEncryption,
        string $smtpUsername,
        string $smtpPassword,
        string $fromEmail,
        string $fromName,
        string $to,
        string $subject,
        string $body
    ): bool {
        if (!in_array($smtpEncryption, ['tls', 'ssl', 'none'], true)) {
            $this->lastError = 'SMTP encryption must be one of: tls, ssl, none.';
            return false;
        }

        if ($smtpPort < 1 || $smtpPort > 65535) {
            $this->lastError = 'SMTP port must be between 1 and 65535.';
            return false;
        }

        $transport = $smtpEncryption === 'ssl' ? 'ssl' : 'tcp';
        $remote = $transport . '://' . $smtpHost . ':' . $smtpPort;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            $this->lastError = 'SMTP connection failed: ' . $errstr . ' (' . $errno . ')';
            return false;
        }

        stream_set_timeout($socket, 25);

        try {
            if ($this->smtpRead($socket, [220]) === null) {
                return false;
            }

            $clientHost = gethostname() ?: 'localhost';
            $ehloResponse = $this->smtpWriteRead($socket, 'EHLO ' . $clientHost, [250]);
            if ($ehloResponse === null) {
                return false;
            }

            $supportsStartTls = stripos($ehloResponse, 'STARTTLS') !== false;
            $shouldUseStartTls = $smtpEncryption === 'tls'
                || ($smtpEncryption === 'none' && ($supportsStartTls || $smtpPort === 587));

            if ($shouldUseStartTls) {
                if ($this->smtpWriteRead($socket, 'STARTTLS', [220]) === null) {
                    if ($smtpEncryption === 'tls' || $smtpPort === 587) {
                        return false;
                    }
                } else {
                    $cryptoEnabled = @stream_socket_enable_crypto(
                        $socket,
                        true,
                        STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
                    );
                    if ($cryptoEnabled !== true) {
                        $this->lastError = 'Failed to negotiate STARTTLS with SMTP server.';
                        return false;
                    }

                    if ($this->smtpWriteRead($socket, 'EHLO ' . $clientHost, [250]) === null) {
                        return false;
                    }
                }
            }

            if ($this->smtpWriteRead($socket, 'AUTH LOGIN', [334]) === null) {
                return false;
            }

            if ($this->smtpWriteRead($socket, base64_encode($smtpUsername), [334]) === null) {
                return false;
            }

            if ($this->smtpWriteRead($socket, base64_encode($smtpPassword), [235]) === null) {
                return false;
            }

            if ($this->smtpWriteRead($socket, 'MAIL FROM:<' . $fromEmail . '>', [250]) === null) {
                return false;
            }

            if ($this->smtpWriteRead($socket, 'RCPT TO:<' . $to . '>', [250, 251]) === null) {
                return false;
            }

            if ($this->smtpWriteRead($socket, 'DATA', [354]) === null) {
                return false;
            }

            $safeFromName = str_replace(["\r", "\n"], '', trim($fromName));
            $subjectHeader = function_exists('mb_encode_mimeheader')
                ? mb_encode_mimeheader($subject, 'UTF-8')
                : $subject;

            $headers = [
                'Date: ' . date(DATE_RFC2822),
                'From: ' . ($safeFromName !== '' ? $safeFromName . ' <' . $fromEmail . '>' : $fromEmail),
                'To: ' . $to,
                'Subject: ' . $subjectHeader,
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: 8bit',
            ];

            $normalizedBody = str_replace(["\r\n", "\r"], "\n", $body);
            $normalizedBody = str_replace("\n", "\r\n", $normalizedBody);
            $normalizedBody = preg_replace('/^\./m', '..', $normalizedBody) ?? $normalizedBody;

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $normalizedBody;
            fwrite($socket, $message . "\r\n.\r\n");

            if ($this->smtpRead($socket, [250]) === null) {
                return false;
            }

            $this->smtpWriteRead($socket, 'QUIT', [221]);
            return true;
        } finally {
            fclose($socket);
        }
    }

    /**
     * @param resource $socket
     */
    private function smtpWriteRead($socket, string $command, array $expectedCodes): ?string
    {
        $bytes = @fwrite($socket, $command . "\r\n");
        if ($bytes === false || $bytes < 1) {
            $this->lastError = 'Failed to write SMTP command' . ($command !== '' ? ' (' . $command . ')' : '') . ' to server.';
            return null;
        }

        return $this->smtpRead($socket, $expectedCodes, $command);
    }

    /**
     * @param resource $socket
     */
    private function smtpRead($socket, array $expectedCodes, string $command = ''): ?string
    {
        $response = '';
        $code = null;

        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) >= 4 && ctype_digit(substr($line, 0, 3))) {
                $code = (int) substr($line, 0, 3);
                if ($line[3] === ' ') {
                    break;
                }
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($socket);
            $suffix = '';
            if (($meta['timed_out'] ?? false) === true) {
                $suffix = ' (socket timed out).';
            } elseif (($meta['eof'] ?? false) === true) {
                $suffix = ' (connection closed by server).';
            } else {
                $suffix = '.';
            }

            $this->lastError = 'No response from SMTP server' . ($command !== '' ? ' after ' . $command : '') . $suffix;
            return null;
        }

        if ($code === null || !in_array($code, $expectedCodes, true)) {
            $trimmed = trim($response);
            $this->lastError = 'SMTP command failed' . ($command !== '' ? ' (' . $command . ')' : '') . ': ' . $trimmed;
            return null;
        }

        return $response;
    }

    private function isTransientFailure(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }

        $normalized = strtolower($error);
        return str_contains($normalized, 'no response from smtp server')
            || str_contains($normalized, 'timed out')
            || str_contains($normalized, 'connection closed by server')
            || str_contains($normalized, 'smtp connection failed');
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
        $receiptFooter = $settings['receipt_footer'] ?? 'Thank you for your business!';

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
                <p>{$receiptFooter}</p>
            </div>
        </body>
        </html>
        ";
    }

    private function getOrderConfirmationTemplate(array $orderDetails, array $settings): string
    {
        $shopName = $settings['shop_name'] ?? 'Our Store';
        $currency = $settings['currency_symbol'] ?? '$';
        $orderId = $orderDetails['order_id'] ?? 'N/A';
        $orderTotal = $orderDetails['total'] ?? '0.00';
        $receiptFooter = $settings['receipt_footer'] ?? 'Thank you for your business!';

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
                <p>Order #{$orderId}</p>
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

                <p><strong>Total: {$currency}{$orderTotal}</strong></p>

                <p>Best regards,<br>The {$shopName} Team</p>
            </div>
            <div class='footer'>
                <p>{$receiptFooter}</p>
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