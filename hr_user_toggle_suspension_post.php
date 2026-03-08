<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage'); // Apenas admins podem suspender/desbloquear

$userId = (int)($_POST['user_id'] ?? 0);
$employeeId = (int)($_POST['employee_id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$suspensionReason = trim((string)($_POST['suspension_reason'] ?? ''));

if ($userId === 0 || $employeeId === 0 || !in_array($action, ['suspend', 'unsuspend'], true)) {
    flash_set('error', 'Parâmetros inválidos.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId);
    exit;
}

// Buscar usuário
$stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /hr_employee_profile.php?id=' . $employeeId);
    exit;
}

// Buscar funcionário
$stmt = db()->prepare('SELECT * FROM hr_employees WHERE id = :id');
$stmt->execute(['id' => $employeeId]);
$employee = $stmt->fetch();

if (!$employee) {
    flash_set('error', 'Funcionário não encontrado.');
    header('Location: /hr_dashboard.php');
    exit;
}

if ($action === 'suspend') {
    // Suspender usuário
    if (empty($suspensionReason)) {
        flash_set('error', 'Informe o motivo da suspensão.');
        header('Location: /hr_employee_profile.php?id=' . $employeeId);
        exit;
    }
    
    $sql = 'UPDATE users SET 
        is_suspended = 1, 
        suspended_at = NOW(), 
        suspended_by_user_id = :suspended_by, 
        suspension_reason = :reason 
    WHERE id = :id';
    
    $stmt = db()->prepare($sql);
    $stmt->execute([
        'id' => $userId,
        'suspended_by' => auth_user_id(),
        'reason' => $suspensionReason,
    ]);
    
    // Registrar no histórico do funcionário
    $historyStmt = db()->prepare('INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, created_by_user_id) VALUES (:employee_id, :change_type, NOW(), :description, :created_by_user_id)');
    $historyStmt->execute([
        'employee_id' => $employeeId,
        'change_type' => 'outro',
        'description' => 'Acesso ao sistema suspenso. Motivo: ' . $suspensionReason,
        'created_by_user_id' => auth_user_id(),
    ]);
    
    audit_log('suspend_user', 'users', (string)$userId, $user, ['suspended' => true, 'reason' => $suspensionReason]);
    
    flash_set('success', 'Usuário suspenso com sucesso! O acesso foi bloqueado imediatamente.');
    
} else {
    // Desbloquear usuário
    $sql = 'UPDATE users SET 
        is_suspended = 0, 
        suspended_at = NULL, 
        suspended_by_user_id = NULL, 
        suspension_reason = NULL 
    WHERE id = :id';
    
    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $userId]);
    
    // Registrar no histórico do funcionário
    $historyStmt = db()->prepare('INSERT INTO hr_employee_history (employee_id, change_type, change_date, description, created_by_user_id) VALUES (:employee_id, :change_type, NOW(), :description, :created_by_user_id)');
    $historyStmt->execute([
        'employee_id' => $employeeId,
        'change_type' => 'outro',
        'description' => 'Acesso ao sistema desbloqueado. Usuário pode fazer login novamente.',
        'created_by_user_id' => auth_user_id(),
    ]);
    
    audit_log('unsuspend_user', 'users', (string)$userId, $user, ['suspended' => false]);
    
    flash_set('success', 'Usuário desbloqueado com sucesso! O acesso foi restaurado.');
}

header('Location: /hr_employee_profile.php?id=' . $employeeId);
exit;
