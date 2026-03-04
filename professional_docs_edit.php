<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.submit');

$uid = (int)auth_user_id();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM professional_documentations WHERE id = :id AND professional_user_id = :uid');
$stmt->execute(['id' => $id, 'uid' => $uid]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Formulário não encontrado.');
    header('Location: /professional_docs_list.php');
    exit;
}

view_header('Formulário #' . (string)$d['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Formulário #' . (int)$d['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Status:</strong> ' . h((string)$d['status']) . ' &nbsp; <strong>Vence em:</strong> ' . h((string)($d['due_at'] ?? ''));
echo '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professional_docs_list.php">Voltar</a>';
echo '<form method="post" action="/professional_docs_submit_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn btnPrimary" type="submit">Enviar</button>';
echo '</form>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/professional_docs_edit_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';

echo '<label>Paciente (referência)<input name="patient_ref" required maxlength="160" value="' . h((string)$d['patient_ref']) . '"></label>';

echo '<label>Quantidade de atendimentos<input type="number" name="sessions_count" min="1" value="' . (int)$d['sessions_count'] . '" required></label>';

echo '<label>Documentos de faturamento<textarea name="billing_docs" rows="3">' . h((string)($d['billing_docs'] ?? '')) . '</textarea></label>';

echo '<label>Documentos de produtividade<textarea name="productivity_docs" rows="3">' . h((string)($d['productivity_docs'] ?? '')) . '</textarea></label>';

echo '<label>Observações<textarea name="notes" rows="3">' . h((string)($d['notes'] ?? '')) . '</textarea></label>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/professional_docs_list.php">Cancelar</a>';
echo '<button class="btn" type="submit">Salvar</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
