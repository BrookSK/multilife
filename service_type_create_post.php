<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$name = trim($_POST['name'] ?? '');
$description = trim($_POST['description'] ?? '');
$display_order = isset($_POST['display_order']) ? (int)$_POST['display_order'] : 0;
$status = $_POST['status'] ?? 'active';

if (empty($name)) {
    header('Location: /service_type_create_form.php?error=' . urlencode('Nome é obrigatório'));
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'active';
}

try {
    // Verificar se já existe
    $checkStmt = db()->prepare("SELECT id FROM service_types WHERE name = ?");
    $checkStmt->execute([$name]);
    
    if ($checkStmt->fetch()) {
        header('Location: /service_type_create_form.php?error=' . urlencode('Já existe um tipo de serviço com este nome'));
        exit;
    }

    // Inserir
    $stmt = db()->prepare("
        INSERT INTO service_types (name, description, display_order, status)
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$name, $description, $display_order, $status]);

    header('Location: /service_types_list.php?success=' . urlencode('Tipo de serviço criado com sucesso!'));
    exit;

} catch (Exception $e) {
    error_log("Erro ao criar tipo de serviço: " . $e->getMessage());
    header('Location: /service_type_create_form.php?error=' . urlencode('Erro ao criar: ' . $e->getMessage()));
    exit;
}
