<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

// Verificar se há um admin original salvo
if (!isset($_SESSION['original_admin_id'])) {
    $_SESSION['error'] = 'Nenhuma sessão de admin original encontrada';
    header('Location: /dashboard.php');
    exit;
}

$originalAdminId = (int)$_SESSION['original_admin_id'];
$originalAdminName = $_SESSION['original_admin_name'] ?? 'Admin';

// Restaurar sessão do admin original (usar auth_user_id, não user_id)
$_SESSION['auth_user_id'] = $originalAdminId;

// Limpar informações de login temporário
unset($_SESSION['original_admin_id']);
unset($_SESSION['original_admin_name']);

// Log de auditoria
audit_log('logout_as_user', 'users', (string)$originalAdminId, 
    ['returned_to_admin' => true], 
    ['admin_id' => $originalAdminId]
);

$_SESSION['success'] = 'Você voltou para sua conta original: ' . $originalAdminName;

// Redirecionar para lista de usuários
header('Location: /users_list.php');
exit;
