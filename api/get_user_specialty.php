<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

auth_require_login();

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($userId === 0) {
    echo json_encode(['error' => 'user_id é obrigatório']);
    exit;
}

$stmt = db()->prepare('SELECT u.specialty, s.id as specialty_id FROM users u LEFT JOIN specialties s ON s.name = u.specialty WHERE u.id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    echo json_encode(['error' => 'Usuário não encontrado']);
    exit;
}

echo json_encode([
    'specialty_id' => $user['specialty_id'] ? (int)$user['specialty_id'] : null,
    'specialty_name' => (string)($user['specialty'] ?? '')
]);
