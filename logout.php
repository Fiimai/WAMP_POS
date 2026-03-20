<?php

declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

use App\Core\Auth;
use App\Repositories\AuditLogRepository;

$user = Auth::user();
if ($user !== null) {
	try {
		$auditRepo = new AuditLogRepository();
		$auditRepo->record(
			(int) $user['id'],
			'logout',
			'user',
			(int) $user['id'],
			['username' => (string) $user['username']],
			Auth::clientIp(),
			(string) ($_SERVER['HTTP_USER_AGENT'] ?? '')
		);
	} catch (Throwable $throwable) {
		error_log('audit log failure logout: ' . $throwable->getMessage());
	}
}

Auth::logout();

header('Location: login.php');
exit;
