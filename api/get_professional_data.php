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
    // Buscar dados do profissional (specialty_service_id do users ou professional_applications)
    $stmt = db()->prepare('
        SELECT 
            u.specialty_service_id,
            pa.specialty_service_id as pa_specialty_service_id
        FROM users u
        LEFT JOIN professional_applications pa ON pa.created_user_id = u.id AND pa.status = \'approved\'
        WHERE u.id = :uid
        LIMIT 1
    ');
    $stmt->execute(['uid' => $userId]);
    $userData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$userData) {
        echo json_encode(['success' => false, 'error' => 'Profissional não encontrado']);
        exit;
    }
    
    // Pegar specialty_service_id (priorizar users, senão professional_applications)
    $specialtyServiceId = $userData['specialty_service_id'] ?? $userData['pa_specialty_service_id'] ?? null;
    
    if (!$specialtyServiceId) {
        echo json_encode(['success' => false, 'error' => 'Profissional sem especialidade/serviço cadastrado']);
        exit;
    }
    
    // Buscar dados do serviço e especialidade
    $stmt = db()->prepare('
        SELECT 
            ss.id as specialty_service_id,
            ss.specialty_id,
            ss.service_name,
            ss.base_value,
            s.name as specialty_name
        FROM specialty_services ss
        INNER JOIN specialties s ON s.id = ss.specialty_id
        WHERE ss.id = :id
    ');
    $stmt->execute(['id' => $specialtyServiceId]);
    $serviceData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$serviceData) {
        echo json_encode(['success' => false, 'error' => 'Serviço não encontrado']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'specialty_id' => (int)$serviceData['specialty_id'],
        'specialty_name' => $serviceData['specialty_name'],
        'specialty_service_id' => (int)$serviceData['specialty_service_id'],
        'service_name' => $serviceData['service_name'],
        'base_value' => (float)$serviceData['base_value']
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar dados do profissional: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar dados']);
}
