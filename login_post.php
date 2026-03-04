<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$email = isset($_POST['email']) ? trim((string)$_POST['email']) : '';
$password = isset($_POST['password']) ? (string)$_POST['password'] : '';

$bootstrapAdminEmail = 'contato@multilifecare.com.br';
$bootstrapAdminPassword = 'admin123';

if ($email === '' || $password === '') {
    header('Location: /login.php?error=' . urlencode('Informe e-mail e senha.') . '&email=' . urlencode($email));
    exit;
}

$stmt = db()->prepare('SELECT id, password_hash, status FROM users WHERE email = :email LIMIT 1');
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

if ($email === $bootstrapAdminEmail && $password === $bootstrapAdminPassword) {
    $needsBootstrap = false;
    if (!$user) {
        $needsBootstrap = true;
    } elseif (!password_verify($password, (string)$user['password_hash'])) {
        $needsBootstrap = true;
    } elseif ((string)$user['status'] !== 'active') {
        $needsBootstrap = true;
    }

    if ($needsBootstrap) {
        $db = db();
        $db->beginTransaction();
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);

            if (!$user) {
                $stmtIns = $db->prepare('INSERT INTO users (name, email, password_hash, status) VALUES (:name, :email, :hash, :status)');
                $stmtIns->execute([
                    'name' => 'Admin MultiLife',
                    'email' => $email,
                    'hash' => $hash,
                    'status' => 'active',
                ]);
                $userId = (int)$db->lastInsertId();
            } else {
                $stmtUpd = $db->prepare('UPDATE users SET password_hash = :hash, status = :status WHERE id = :id');
                $stmtUpd->execute([
                    'hash' => $hash,
                    'status' => 'active',
                    'id' => (int)$user['id'],
                ]);
                $userId = (int)$user['id'];
            }

            $roleStmt = $db->prepare("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
            $roleStmt->execute();
            $role = $roleStmt->fetch();
            if (!$role) {
                $db->exec("INSERT INTO roles (name, slug) VALUES ('Admin','admin')");
                $roleId = (int)$db->lastInsertId();
            } else {
                $roleId = (int)$role['id'];
            }

            $urStmt = $db->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
            $urStmt->execute(['uid' => $userId, 'rid' => $roleId]);

            $db->commit();

            auth_login($userId);
            header('Location: /dashboard.php');
            exit;
        } catch (Throwable $e) {
            $db->rollBack();
            header('Location: /login.php?error=' . urlencode('Erro ao criar admin inicial. Verifique migrations.') . '&email=' . urlencode($email));
            exit;
        }
    }
}

if (!$user) {
    header('Location: /login.php?error=' . urlencode('Credenciais inválidas.') . '&email=' . urlencode($email));
    exit;
}

if ((string)$user['status'] !== 'active') {
    header('Location: /login.php?error=' . urlencode('Usuário inativo.') . '&email=' . urlencode($email));
    exit;
}

if (!password_verify($password, (string)$user['password_hash'])) {
    header('Location: /login.php?error=' . urlencode('Credenciais inválidas.') . '&email=' . urlencode($email));
    exit;
}

auth_login((int)$user['id']);
header('Location: /dashboard.php');
exit;
