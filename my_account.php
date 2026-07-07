<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$userId = auth_user_id();
$stmt = db()->prepare('SELECT id, name, email, phone, specialty, created_at FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /login.php');
    exit;
}

view_header('Minha Conta');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Minha Conta</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px">Gerencie seus dados pessoais e senha de acesso.</div>';
echo '</div>';
echo '</div>';
echo '</section>';

// Dados pessoais
echo '<section class="card col12">';
echo '<div style="font-weight:900;font-size:16px;margin-bottom:16px">Dados Pessoais</div>';

echo '<form method="post" action="/my_account_post.php" style="display:grid;gap:16px;max-width:600px">';
echo '<input type="hidden" name="action" value="update_profile">';

echo '<label>Nome completo<input name="name" value="' . h((string)$user['name']) . '" required></label>';
echo '<label>E-mail<input type="email" name="email" value="' . h((string)$user['email']) . '" required></label>';
echo '<label>Telefone<input name="phone" value="' . h((string)$user['phone']) . '" placeholder="(00) 00000-0000"></label>';
echo '<label>Especialidade<select name="specialty">';
echo '<option value="">Selecione...</option>';
$specStmt = db()->query('SELECT id, name FROM specialties WHERE status = \'active\' ORDER BY name ASC');
$specialties = $specStmt->fetchAll();
foreach ($specialties as $spec) {
    $selected = ((string)$user['specialty'] === (string)$spec['name']) ? ' selected' : '';
    echo '<option value="' . h((string)$spec['name']) . '"' . $selected . '>' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';

echo '<div style="display:flex;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Salvar Alterações</button>';
echo '</div>';
echo '</form>';
echo '</section>';

// Alterar senha
echo '<section class="card col12">';
echo '<div style="font-weight:900;font-size:16px;margin-bottom:16px">Alterar Senha</div>';

echo '<form method="post" action="/my_account_post.php" style="display:grid;gap:16px;max-width:600px">';
echo '<input type="hidden" name="action" value="change_password">';

echo '<label>Senha atual<input type="password" name="current_password" required placeholder="Digite sua senha atual"></label>';
echo '<label>Nova senha<input type="password" name="new_password" required minlength="6" placeholder="Mínimo 6 caracteres"></label>';
echo '<label>Confirmar nova senha<input type="password" name="confirm_password" required minlength="6" placeholder="Repita a nova senha"></label>';

echo '<div style="display:flex;justify-content:flex-end">';
echo '<button class="btn btnPrimary" type="submit">Alterar Senha</button>';
echo '</div>';
echo '</form>';
echo '</section>';

// Info
echo '<section class="card col12">';
echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border-radius:8px;font-size:13px;color:hsl(var(--muted-foreground))">';
echo '🔒 Sua senha é armazenada de forma segura (criptografada). Nenhum administrador pode visualizá-la.';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
