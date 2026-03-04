<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$existing = (int)db()->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];

if ($existing > 0 && auth_user_id() === null) {
    header('Location: /login.php');
    exit;
}

if ($existing > 0 && auth_user_id() !== null) {
    header('Location: /dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Preencha nome, e-mail e senha.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'E-mail inválido.';
    } elseif (mb_strlen($password) < 8) {
        $error = 'Senha deve ter no mínimo 8 caracteres.';
    } else {
        $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        if ($stmt->fetch()) {
            $error = 'Já existe um usuário com esse e-mail.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = db()->prepare('INSERT INTO users (name, email, password_hash, status) VALUES (:name, :email, :hash, :status)');
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'hash' => $hash,
                'status' => 'active',
            ]);

            $userId = (int)db()->lastInsertId();

            $roleIdStmt = db()->prepare("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
            $roleIdStmt->execute();
            $role = $roleIdStmt->fetch();
            if (!$role) {
                db()->exec("INSERT INTO roles (name, slug) VALUES ('Admin','admin')");
                $roleId = (int)db()->lastInsertId();
            } else {
                $roleId = (int)$role['id'];
            }

            $stmt = db()->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:uid, :rid)');
            $stmt->execute(['uid' => $userId, 'rid' => $roleId]);

            auth_login($userId);
            header('Location: /dashboard.php');
            exit;
        }
    }
}

view_header('Setup');

echo '<div class="card">';
echo '<div style="font-size:18px;font-weight:800;margin-bottom:6px">Setup inicial</div>';
echo '<div style="color:rgba(234,240,255,.72);font-size:14px;line-height:1.6;margin-bottom:14px">Crie o primeiro usuário Admin do sistema.</div>';

if ($error !== '') {
    echo '<div class="alert alertError" role="alert">' . h($error) . '</div>';
}

echo '<form method="post" action="/setup.php" style="display:grid;gap:12px;max-width:520px">';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Nome<input name="name" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">E-mail<input type="email" name="email" required style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<label style="display:grid;gap:7px;font-size:13px;color:rgba(234,240,255,.85)">Senha<input type="password" name="password" required minlength="8" style="width:100%;border-radius:14px;border:1px solid rgba(255,255,255,.18);background:rgba(10,14,28,.55);color:var(--text);padding:12px 12px;outline:none;font-size:14px"></label>';
echo '<button class="btn btnPrimary" type="submit">Criar Admin</button>';
echo '</form>';

echo '<div style="margin-top:14px;color:rgba(234,240,255,.72);font-size:13px;line-height:1.6">Depois do primeiro Admin criado, este setup não será mais acessível sem login.</div>';
echo '</div>';

view_footer();
