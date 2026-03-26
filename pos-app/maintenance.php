<?php

declare(strict_types=1);

/**
 * Maintenance Mode Handler
 * Shows maintenance page when site is under maintenance
 */

$maintenanceFile = __DIR__ . '/maintenance.flag';

if (file_exists($maintenanceFile)) {
    $message = file_get_contents($maintenanceFile) ?: 'Site is currently under maintenance. Please try again later.';

    http_response_code(503);

    // If this is an API request, return JSON
    if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'maintenance_mode',
            'message' => $message,
            'retry_after' => 300 // 5 minutes
        ]);
        exit;
    }

    // Otherwise show HTML page
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Maintenance Mode</title>
        <link rel="stylesheet" href="assets/css/y2k-global.css" />
  <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                margin: 0;
                padding: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .maintenance-container {
                text-align: center;
                max-width: 500px;
                padding: 2rem;
                background: rgba(255, 255, 255, 0.1);
                border-radius: 10px;
                backdrop-filter: blur(10px);
            }
            h1 {
                margin-bottom: 1rem;
                font-size: 2rem;
            }
            p {
                margin-bottom: 2rem;
                opacity: 0.9;
            }
            .spinner {
                border: 3px solid rgba(255, 255, 255, 0.3);
                border-top: 3px solid white;
                border-radius: 50%;
                width: 40px;
                height: 40px;
                animation: spin 1s linear infinite;
                margin: 0 auto 1rem;
            }
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
        </style>
    </head>
    <body>
        <div class="maintenance-container">
            <div class="spinner"></div>
            <h1>Under Maintenance</h1>
            <p><?php echo htmlspecialchars($message); ?></p>
            <p><small>We'll be back shortly!</small></p>
        </div>
      <script src="assets/js/y2k-global.js"></script>
  <script>
    window.NovaY2K.init();
  </script></body>
    </html>
    <?php
    exit;
}