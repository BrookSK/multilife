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
    $stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
    $stmt->execute(['id' => $demandId]);
    $demand = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$demand) {
        echo json_encode(['success' => false, 'error' => 'Demanda não encontrada']);
        exit;
    }
    
    // Buscar sub-solicitações (se existirem)
    $subRequests = [];
    try {
        $stmtSub = db()->prepare("SELECT id, specialty, frequency, description, location_city, location_state, status FROM demand_sub_requests WHERE demand_id = ? ORDER BY id ASC");
        $stmtSub->execute([$demandId]);
        $subRequests = $stmtSub->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Tabela pode não existir
    }
    
    // Buscar especialidade ID se o nome for informado
    $specialtyId = null;
    $specialtyName = (string)($demand['specialty'] ?? '');
    if ($specialtyName !== '') {
        try {
            $stmtSpec = db()->prepare("SELECT id FROM specialties WHERE name = ? AND status = 'active' LIMIT 1");
            $stmtSpec->execute([$specialtyName]);
            $specRow = $stmtSpec->fetch();
            if ($specRow) $specialtyId = (int)$specRow['id'];
        } catch (Exception $e) {}
    }
    
    // Verificar quais especialidades já tiveram proposta enviada
    $usedSpecialties = [];
    try {
        $stmtUsed = db()->prepare("
            SELECT DISTINCT ar.specialty_name 
            FROM authorization_requests ar
            INNER JOIN specialties s ON s.id = ar.specialty_id OR s.name = ar.specialty_name
            WHERE ar.demand_id = ? AND ar.status != 'cancelada'
        ");
        $stmtUsed->execute([$demandId]);
        $usedSpecialties = $stmtUsed->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // Pode não ter as colunas
    }
    
    // Detectar operadora pelo domínio do e-mail de origem
    $insurerData = null;
    $originEmail = (string)($demand['origin_email'] ?? '');
    if (!empty($originEmail) && strpos($originEmail, '@') !== false) {
        $emailDomain = strtolower(substr($originEmail, strpos($originEmail, '@') + 1));
        try {
            // Buscar por domínio
            $stmtIns = db()->prepare("SELECT id, name FROM health_insurers WHERE status = 'active' AND email_domain = ? LIMIT 1");
            $stmtIns->execute([$emailDomain]);
            $insurerRow = $stmtIns->fetch(PDO::FETCH_ASSOC);
            
            if (!$insurerRow) {
                // Fallback: buscar por nome baseado no domínio
                $domainBase = explode('.', $emailDomain)[0];
                if (strlen($domainBase) >= 3) {
                    $stmtIns2 = db()->prepare("SELECT id, name FROM health_insurers WHERE status = 'active' AND LOWER(name) LIKE ? LIMIT 1");
                    $stmtIns2->execute(['%' . $domainBase . '%']);
                    $insurerRow = $stmtIns2->fetch(PDO::FETCH_ASSOC);
                }
            }
            
            if ($insurerRow) {
                $insurerData = ['id' => (int)$insurerRow['id'], 'name' => $insurerRow['name']];
            }
        } catch (Exception $e) {}
    }
    
    echo json_encode([
        'success' => true,
        'id' => (int)$demand['id'],
        'title' => $demand['title'] ?? '',
        'origin_email' => $originEmail,
        'specialty' => $specialtyName,
        'specialty_id' => $specialtyId,
        'frequency' => $demand['frequency'] ?? '',
        'location_city' => $demand['location_city'] ?? '',
        'location_state' => $demand['location_state'] ?? '',
        'description' => $demand['description'] ?? '',
        'procedure_value' => $demand['procedure_value'] ?? null,
        'sub_requests' => $subRequests,
        'used_specialties' => $usedSpecialties,
        'insurer' => $insurerData,
        'email_domain' => $emailDomain ?? null,
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar dados da demanda: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Erro ao buscar dados']);
}
