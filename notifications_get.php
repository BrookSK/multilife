<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$userId = (int)$_SESSION['user_id'];

$stmt = db()->prepare('
    SELECT id, type, title, message, link, is_read, created_at
    FROM notifications
    WHERE user_id = :uid
    ORDER BY created_at DESC
    LIMIT 50
');
$stmt->execute(['uid' => $userId]);
$notifications = $stmt->fetchAll();

// Formatar datas
foreach ($notifications as &$n) {
    $timestamp = strtotime((string)$n['created_at']);
    $diff = time() - $timestamp;
    
    if ($diff < 60) {
        $n['created_at'] = 'Agora';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        $n['created_at'] = $mins . ' min atrás';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        $n['created_at'] = $hours . ' hora' . ($hours > 1 ? 's' : '') . ' atrás';
    } else {
        $days = floor($diff / 86400);
        $n['created_at'] = $days . ' dia' . ($days > 1 ? 's' : '') . ' atrás';
    }
    
    $n['is_read'] = (int)$n['is_read'];
}

echo json_encode(['notifications' => $notifications], JSON_UNESCAPED_UNICODE);
