<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /specialties_list.php');
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$specialty_id = isset($_POST['specialty_id']) ? (int)$_POST['specialty_id'] : 0;
$service_name = trim($_POST['service_name'] ?? '');
$description = trim($_POST['description'] ?? '');
$base_value = isset($_POST['base_value']) ? (float)$_POST['base_value'] : 0.00;
$display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
$status = $_POST['status'] ?? 'active';

$redirect_url = "/specialty_services_final.php?id={$specialty_id}";

if (!$id || !$specialty_id || empty($service_name)) {
    header("Location: {$redirect_url}&error=" . urlencode('Dados inválidos'));
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

try {
    $stmt = db()->prepare("
        UPDATE specialty_services 
        SET service_name = ?, description = ?, base_value = ?, display_order = ?, status = ?, updated_at = NOW()
        WHERE id = ? AND specialty_id = ?
    ");
    $stmt->execute([$service_name, $description, $base_value, $display_order, $status, $id, $specialty_id]);

    header("Location: {$redirect_url}&success=" . urlencode('Serviço atualizado com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao atualizar serviço: " . $e->getMessage());
    header("Location: {$redirect_url}&error=" . urlencode('Erro ao atualizar serviço: ' . $e->getMessage()));
    exit;
}
