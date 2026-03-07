<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$specialty_id = isset($_GET['specialty_id']) ? (int)$_GET['specialty_id'] : 0;
$service_id = isset($_GET['service_id']) ? (int)$_GET['service_id'] : 0;

$redirect_url = $specialty_id > 0 ? "/specialty_services_v3.php?id={$specialty_id}" : "/specialties_list.php";

if (!$specialty_id || !$service_id) {
    header("Location: {$redirect_url}&error=" . urlencode('Dados inválidos'));
    exit;
}

try {
    // Remover o serviço
    $stmt = db()->prepare("
        DELETE FROM specialty_service_values 
        WHERE id = ? AND specialty_id = ?
    ");
    $stmt->execute([$service_id, $specialty_id]);

    header("Location: {$redirect_url}&success=" . urlencode('Serviço removido com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao remover serviço: " . $e->getMessage());
    header("Location: {$redirect_url}&error=" . urlencode('Erro ao remover serviço: ' . $e->getMessage()));
    exit;
}
