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
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;

$redirect_url = $specialty_id > 0 ? "/specialty_services_v3.php?id={$specialty_id}" : "/service_types_list.php";

if (empty($name)) {
    header("Location: {$redirect_url}&error=" . urlencode('Nome do serviço é obrigatório'));
    exit;
}

try {
    // Verificar se já existe um serviço com este nome
    $checkStmt = db()->prepare("SELECT id FROM service_types WHERE name = ?");
    $checkStmt->execute([$name]);
    
    if ($checkStmt->fetch()) {
        header("Location: {$redirect_url}&error=" . urlencode('Já existe um tipo de serviço com este nome'));
        exit;
    }

    // Inserir novo tipo de serviço
    $stmt = db()->prepare("
        INSERT INTO service_types (name, description, display_order, status)
        VALUES (?, ?, ?, 'active')
    ");
    $stmt->execute([$name, $description, $display_order]);

    header("Location: {$redirect_url}&success=" . urlencode('Tipo de serviço criado com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao criar tipo de serviço: " . $e->getMessage());
    header("Location: {$redirect_url}&error=" . urlencode('Erro ao criar tipo de serviço: ' . $e->getMessage()));
    exit;
}
