<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('users.manage');

// Buscar especialidades
$specialtiesStmt = db()->query("SELECT id, name FROM specialties WHERE status = 'active' ORDER BY name ASC");
$specialties = $specialtiesStmt->fetchAll();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Garantir coluna is_test_professional (fallback)
try { db()->exec("ALTER TABLE users ADD COLUMN is_test_professional TINYINT(1) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
// Garantir coluna professional_type (antigo/novo) - fallback
try { db()->exec("ALTER TABLE users ADD COLUMN professional_type VARCHAR(20) NOT NULL DEFAULT 'new'"); } catch (Throwable $e) {}

$stmt = db()->prepare('SELECT id, name, email, phone, specialty, status, is_test_professional, professional_type FROM users WHERE id = :id');
$stmt->execute(['id' => $id]);
$user = $stmt->fetch();

if (!$user) {
    flash_set('error', 'Usuário não encontrado.');
    header('Location: /users_list.php');
    exit;
}

// Verificar se o usuario editado e profissional para ajustar link de volta
$_editUserRoles = [];
try { $rs = db()->prepare("SELECT r.slug FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ?"); $rs->execute([$id]); $_editUserRoles = $rs->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) {}
$_backUrl = in_array('profissional', $_editUserRoles, true) ? '/users_list.php?role=profissional' : '/users_list.php';

view_header('Editar usuário');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar usuário</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Atualize dados e senha (opcional).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="' . $_backUrl . '">Voltar</a>';
echo '<a class="btn" href="/users_roles_edit.php?id=' . (int)$user['id'] . '">Perfis</a>';
echo '</div>';
echo '</div>';

echo '<div style="height:14px"></div>';

echo '<form method="post" action="/users_edit_post.php" style="display:grid;gap:12px;max-width:680px">';
echo '<input type="hidden" name="id" value="' . (int)$user['id'] . '">';
echo '<label>Nome<input name="name" required value="' . h((string)$user['name']) . '" placeholder="Nome"></label>';
echo '<label>E-mail<input type="email" name="email" required value="' . h((string)$user['email']) . '" placeholder="email@empresa.com"></label>';
echo '<label>Telefone (para WhatsApp/Evolution)<input name="phone" maxlength="30" value="' . h((string)($user['phone'] ?? '')) . '" placeholder="5511999999999"></label>';
echo '<label>Especialidade (para profissionais)<select name="specialty">';
echo '<option value="">Nenhuma / Não é profissional</option>';
foreach ($specialties as $spec) {
    $selected = ((string)($user['specialty'] ?? '') === (string)$spec['name']) ? ' selected' : '';
    echo '<option value="' . h((string)$spec['name']) . '"' . $selected . '>' . h((string)$spec['name']) . '</option>';
}
echo '</select></label>';
echo '<label>Nova senha (opcional)<input type="password" name="password" minlength="8" placeholder="Deixe em branco para manter"></label>';
echo '<label>Status<select name="status">';
$st = (string)$user['status'];
echo '<option value="active"' . ($st === 'active' ? ' selected' : '') . '>active</option>';
echo '<option value="inactive"' . ($st === 'inactive' ? ' selected' : '') . '>inactive</option>';
echo '</select></label>';

// Tipo de profissional (antigo/novo) - usado para gerenciar envio de documentos
$profType = (string)($user['professional_type'] ?? 'new');
echo '<label>Tipo de profissional<select name="professional_type">';
echo '<option value="new"' . ($profType === 'new' ? ' selected' : '') . '>Profissional novo</option>';
echo '<option value="legacy"' . ($profType === 'legacy' ? ' selected' : '') . '>Profissional antigo</option>';
echo '</select><span class="helpText">Define se o profissional é novo ou antigo. Usado no envio de documentos específicos para cada grupo.</span></label>';

// Marcar como profissional de teste (usado no modo de teste da captação)
$isTest = (int)($user['is_test_professional'] ?? 0) === 1;
echo '<div style="display:flex;align-items:flex-start;gap:10px;padding:14px 16px;background:hsla(var(--warning)/.08);border:1px solid hsla(var(--warning)/.25);border-radius:10px">';
echo '<input type="checkbox" id="is_test_professional" name="is_test_professional" value="1"' . ($isTest ? ' checked' : '') . '>';
echo '<label for="is_test_professional" style="display:block;cursor:pointer;font-weight:400;gap:0">';
echo '<span style="font-weight:700;font-size:14px">Profissional de teste</span><br>';
echo '<span style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.5">Quando o modo de teste da captação estiver ativo, apenas profissionais marcados aqui serão adicionados aos grupos.</span>';
echo '</label>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="' . $_backUrl . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</div>';
echo '</form>';

echo '</div>';

view_footer();
