<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /service_types_list.php');
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$id) {
    header('Location: /service_types_list.php?error=' . urlencode('ID inválido'));
    exit;
}

try {
    // Verificar se está em uso
    $checkStmt = db()->prepare("SELECT COUNT(*) as total FROM specialty_service_values WHERE service_type_id = ?");
    $checkStmt->execute([$id]);
    $result = $checkStmt->fetch();
    
    if ($result['total'] > 0) {
        header('Location: /service_types_list.php?error=' . urlencode('Não é possível excluir. Este tipo de serviço está sendo usado por ' . $result['total'] . ' especialidade(s).'));
        exit;
    }

    // Excluir
    $stmt = db()->prepare("DELETE FROM service_types WHERE id = ?");
    $stmt->execute([$id]);

    header('Location: /service_types_list.php?success=' . urlencode('Tipo de serviço excluído com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao excluir tipo de serviço: " . $e->getMessage());
    header('Location: /service_types_list.php?error=' . urlencode('Erro ao excluir: ' . $e->getMessage()));
    exit;
}
