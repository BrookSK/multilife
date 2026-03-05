<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];

$stmt = db()->prepare('
    UPDATE notifications
    SET is_read = 1, read_at = NOW()
    WHERE user_id = :uid AND is_read = 0
');
$stmt->execute(['uid' => $userId]);

echo json_encode(['success' => true]);
