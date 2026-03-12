<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

auth_require_login();

$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($userId <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    exit;
}

try {
    // Buscar especialidade do profissional
    $stmt = db()->prepare('SELECT specialty FROM professional_applications WHERE created_user_id = :uid AND status = \'approved\' LIMIT 1');
    $stmt->execute(['uid' => $userId]);
    $profApp = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $specialty = $profApp ? (string)$profApp['specialty'] : '';
    
    // Buscar serviços do profissional
    $servicesStmt = db()->prepare('
        SELECT ps.id, ps.service_name, ps.value_per_session, ps.duration_minutes
        FROM professional_services ps
        WHERE ps.professional_user_id = :uid
        AND ps.status = \'active\'
        ORDER BY ps.service_name ASC
    ');
    $servicesStmt->execute(['uid' => $userId]);
    $services = $servicesStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'specialty' => $specialty,
        'services' => $services
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar serviços do profissional: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar dados']);
}
