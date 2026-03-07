<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
$status = $_POST['status'] ?? 'active';

if (!$id || empty($name)) {
    header('Location: /service_types_list.php?error=' . urlencode('Dados inválidos'));
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

try {
    // Verificar se existe outro com o mesmo nome
    $checkStmt = db()->prepare("SELECT id FROM service_types WHERE name = ? AND id != ?");
    $checkStmt->execute([$name, $id]);
    
    if ($checkStmt->fetch()) {
        header('Location: /service_type_edit.php?id=' . $id . '&error=' . urlencode('Já existe outro tipo de serviço com este nome'));
        exit;
    }

    // Atualizar
    $stmt = db()->prepare("
        UPDATE service_types 
        SET name = ?, description = ?, display_order = ?, status = ?, updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$name, $description, $display_order, $status, $id]);

    header('Location: /service_types_list.php?success=' . urlencode('Tipo de serviço atualizado com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao atualizar tipo de serviço: " . $e->getMessage());
    header('Location: /service_type_edit.php?id=' . $id . '&error=' . urlencode('Erro ao atualizar: ' . $e->getMessage()));
    exit;
}
