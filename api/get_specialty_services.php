<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json');

auth_require_login();

$specialty_id = isset($_GET['specialty_id']) ? (int)$_GET['specialty_id'] : 0;

if (!$specialty_id) {
    echo json_encode(['error' => 'Specialty ID required']);
    exit;
}

try {
    $stmt = db()->prepare("
        SELECT id, service_name, description, base_value, status
        FROM specialty_services
        WHERE specialty_id = ? AND status = 'active'
        ORDER BY display_order, service_name
    ");
    $stmt->execute([$specialty_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['services' => $services]);
} catch (Exception $e) {
    error_log("Erro ao buscar serviços: " . $e->getMessage());
    echo json_encode(['error' => 'Erro ao buscar serviços']);
}
