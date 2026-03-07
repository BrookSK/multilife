<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /specialties_list.php');
    exit;
}

$specialty_id = isset($_POST['specialty_id']) ? (int)$_POST['specialty_id'] : 0;
$service_type_id = isset($_POST['service_type_id']) ? (int)$_POST['service_type_id'] : 0;
$base_value = isset($_POST['base_value']) ? (float)$_POST['base_value'] : 0.00;

$redirect_url = $specialty_id > 0 ? "/specialty_services_v3.php?id={$specialty_id}" : "/specialties_list.php";

if (!$specialty_id || !$service_type_id) {
    header("Location: {$redirect_url}&error=" . urlencode('Dados inválidos'));
    exit;
}

try {
    // Verificar se já existe
    $checkStmt = db()->prepare("
        SELECT id FROM specialty_service_values 
        WHERE specialty_id = ? AND service_type_id = ?
    ");
    $checkStmt->execute([$specialty_id, $service_type_id]);
    
    if ($checkStmt->fetch()) {
        header("Location: {$redirect_url}&error=" . urlencode('Este serviço já está configurado para esta especialidade'));
        exit;
    }

    // Inserir novo valor
    $stmt = db()->prepare("
        INSERT INTO specialty_service_values 
        (specialty_id, service_type_id, base_value, status)
        VALUES (?, ?, ?, 'active')
    ");
    $stmt->execute([$specialty_id, $service_type_id, $base_value]);

    header("Location: {$redirect_url}&success=" . urlencode('Serviço adicionado com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao adicionar serviço: " . $e->getMessage());
    header("Location: {$redirect_url}&error=" . urlencode('Erro ao adicionar serviço: ' . $e->getMessage()));
    exit;
}
