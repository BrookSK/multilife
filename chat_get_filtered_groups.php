<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

header('Content-Type: application/json');

$specialty = isset($_GET['specialty']) ? trim((string)$_GET['specialty']) : '';
$region = isset($_GET['region']) ? trim((string)$_GET['region']) : '';

try {
    $whereClauses = [];
    $params = [];
    
    if (!empty($specialty)) {
        $whereClauses[] = "specialty = ?";
        $params[] = $specialty;
    }
    
    if (!empty($region)) {
        $whereClauses[] = "region = ?";
        $params[] = $region;
    }
    
    $whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
    
    $stmt = db()->prepare("
        SELECT 
            group_jid,
            group_name,
            specialty,
            region
        FROM chat_groups
        $whereSQL
        ORDER BY group_name ASC
    ");
    
    $stmt->execute($params);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'groups' => $groups]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
