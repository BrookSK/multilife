<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

$targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$currentUserId = auth_user_id();

if ($targetUserId === 0) {
    $_SESSION['error'] = 'ID de usuário inválido';
    header('Location: /users_list.php');
    exit;
}

// Não permitir login como si mesmo
if ($targetUserId === $currentUserId) {
    $_SESSION['error'] = 'Você não pode fazer login como você mesmo';
    header('Location: /users_list.php');
    exit;
}

// Buscar usuário alvo
$stmt = db()->prepare("SELECT id, name, email, status FROM users WHERE id = ?");
$stmt->execute([$targetUserId]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    $_SESSION['error'] = 'Usuário não encontrado';
    header('Location: /users_list.php');
    exit;
}

if ($targetUser['status'] !== 'active') {
    $_SESSION['error'] = 'Não é possível fazer login como usuário inativo';
    header('Location: /users_list.php');
    exit;
}

// Salvar informações do admin original para poder voltar
$_SESSION['original_admin_id'] = $currentUserId;
$_SESSION['original_admin_name'] = auth_user()['name'] ?? 'Admin';

// Fazer login como o usuário alvo
$_SESSION['user_id'] = $targetUserId;

// Log de auditoria
audit_log('login_as_user', 'users', (string)$targetUserId, 
    ['admin_id' => $currentUserId], 
    ['logged_in_as' => $targetUserId]
);

$_SESSION['success'] = 'Você está agora logado como: ' . $targetUser['name'];

// Redirecionar para dashboard
header('Location: /dashboard.php');
exit;
