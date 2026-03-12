<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

auth_require_login();

$demandId = isset($_GET['demand_id']) ? (int)$_GET['demand_id'] : 0;

if ($demandId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT id, origin_email, specialty FROM demands WHERE id = :id');
    $stmt->execute(['id' => $demandId]);
    $demand = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$demand) {
        echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'origin_email' => $demand['origin_email'] ?? '',
        'specialty' => $demand['specialty'] ?? ''
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar dados da demanda: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar dados']);
}
