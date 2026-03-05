<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];

$input = json_decode(file_get_contents('php://input'), true);
$notifId = isset($input['id']) ? (int)$input['id'] : 0;

if ($notifId > 0) {
    $stmt = db()->prepare('
        UPDATE notifications
        SET is_read = 1, read_at = NOW()
        WHERE id = :id AND user_id = :uid
    ');
    $stmt->execute(['id' => $notifId, 'uid' => $userId]);
}

echo json_encode(['success' => true]);
