<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];

$stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = 0');
$stmt->execute(['uid' => $userId]);
$count = (int)$stmt->fetchColumn();

echo json_encode(['count' => $count]);
