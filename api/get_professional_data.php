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
    // Buscar dados do profissional (specialty texto e specialty_service_id)
    $stmt = db()->prepare('
        SELECT 
            u.specialty,
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
    
    $specialtyText = $userData['specialty'] ?? null;
    $specialtyServiceId = $userData['specialty_service_id'] ?? $userData['pa_specialty_service_id'] ?? null;
    
    // Buscar specialty_id baseado no texto da especialidade
    $specialtyId = null;
    $specialtyName = null;
    
    if ($specialtyText) {
        $stmt = db()->prepare('SELECT id, name FROM specialties WHERE name = :name LIMIT 1');
        $stmt->execute(['name' => $specialtyText]);
        $specialtyData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($specialtyData) {
            $specialtyId = (int)$specialtyData['id'];
            $specialtyName = $specialtyData['name'];
        }
    }
    
    // Se não encontrou especialidade, retornar erro
    if (!$specialtyId) {
        echo json_encode([
            'success' => false, 
            'error' => 'Especialidade "' . ($specialtyText ?? 'não definida') . '" não encontrada no sistema'
        ]);
        exit;
    }
    
    // Se tiver specialty_service_id, buscar dados do serviço
    $serviceData = null;
    if ($specialtyServiceId) {
        $stmt = db()->prepare('
            SELECT 
                id as specialty_service_id,
                service_name,
                base_value
            FROM specialty_services
            WHERE id = :id
        ');
        $stmt->execute(['id' => $specialtyServiceId]);
        $serviceData = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    echo json_encode([
        'success' => true,
        'specialty_id' => $specialtyId,
        'specialty_name' => $specialtyName,
        'specialty_service_id' => $serviceData ? (int)$serviceData['specialty_service_id'] : null,
        'service_name' => $serviceData ? $serviceData['service_name'] : null,
        'base_value' => $serviceData ? (float)$serviceData['base_value'] : null
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar dados do profissional: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar dados: ' . $e->getMessage()]);
}
