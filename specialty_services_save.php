<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('settings.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /specialties_list.php');
    exit;
}

$specialty_id = isset($_POST['specialty_id']) ? (int)$_POST['specialty_id'] : 0;
$service_type_ids = $_POST['service_type_ids'] ?? [];
$values = $_POST['values'] ?? [];
$statuses = $_POST['statuses'] ?? [];
$value_ids = $_POST['value_ids'] ?? [];

if (!$specialty_id || empty($service_type_ids)) {
    header('Location: /specialties_list.php?error=' . urlencode('Dados inválidos'));
    exit;
}

try {
    db()->beginTransaction();

    foreach ($service_type_ids as $service_type_id) {
        $service_type_id = (int)$service_type_id;
        $base_value = isset($values[$service_type_id]) ? (float)$values[$service_type_id] : 0.00;
        $status = isset($statuses[$service_type_id]) ? $statuses[$service_type_id] : 'active';
        $value_id = isset($value_ids[$service_type_id]) ? (int)$value_ids[$service_type_id] : 0;

        if ($value_id > 0) {
            // Atualizar valor existente
            $stmt = db()->prepare("
                UPDATE specialty_service_values 
                SET base_value = ?, status = ?, updated_at = NOW()
                WHERE id = ? AND specialty_id = ?
            ");
            $stmt->execute([$base_value, $status, $value_id, $specialty_id]);
        } else {
            // Inserir novo valor
            $stmt = db()->prepare("
                INSERT INTO specialty_service_values 
                (specialty_id, service_type_id, base_value, status)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    base_value = VALUES(base_value),
                    status = VALUES(status),
                    updated_at = NOW()
            ");
            $stmt->execute([$specialty_id, $service_type_id, $base_value, $status]);
        }
    }

    db()->commit();

    header('Location: /specialty_services.php?id=' . $specialty_id . '&success=' . urlencode('Valores salvos com sucesso!'));
    exit;

} catch (Exception $e) {
    db()->rollBack();
    error_log("Erro ao salvar valores de serviços: " . $e->getMessage());
    header('Location: /specialty_services.php?id=' . $specialty_id . '&error=' . urlencode('Erro ao salvar valores: ' . $e->getMessage()));
    exit;
}
