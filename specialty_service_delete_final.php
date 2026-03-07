<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$specialty_id = isset($_GET['specialty_id']) ? (int)$_GET['specialty_id'] : 0;

$redirect_url = "/specialty_services_final.php?id={$specialty_id}";

if (!$id || !$specialty_id) {
    header("Location: {$redirect_url}&error=" . urlencode('Dados inválidos'));
    exit;
}

try {
    $stmt = db()->prepare("DELETE FROM specialty_services WHERE id = ? AND specialty_id = ?");
    $stmt->execute([$id, $specialty_id]);

    header("Location: {$redirect_url}&success=" . urlencode('Serviço excluído com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao excluir serviço: " . $e->getMessage());
    header("Location: {$redirect_url}&error=" . urlencode('Erro ao excluir serviço: ' . $e->getMessage()));
    exit;
}
