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
$service_name = trim($_POST['service_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$base_value = isset($_POST['base_value']) ? (float)$_POST['base_value'] : 0.00;
$display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
$status = $_POST['status'] ?? 'active';

// Debug
error_log("Creating service - Specialty ID: {$specialty_id}, Name: {$service_name}, Value: {$base_value}, Status: {$status}");

$redirect_url = "/specialty_services_final.php?id={$specialty_id}";

if (!$specialty_id || empty($service_name)) {
    error_log("Validation failed - Specialty ID: {$specialty_id}, Name: '{$service_name}'");
    header("Location: {$redirect_url}&error=" . urlencode('Dados inválidos'));
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

try {
    $stmt = db()->prepare("
        INSERT INTO specialty_services 
        (specialty_id, service_name, description, base_value, display_order, status)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $result = $stmt->execute([$specialty_id, $service_name, $description, $base_value, $display_order, $status]);
    
    $insertedId = db()->lastInsertId();
    error_log("Service created successfully - ID: {$insertedId}");

    header("Location: {$redirect_url}&success=" . urlencode('Serviço criado com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao criar serviço: " . $e->getMessage());
    header("Location: {$redirect_url}&error=" . urlencode('Erro ao criar serviço: ' . $e->getMessage()));
    exit;
}
