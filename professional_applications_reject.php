<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_applications.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, status, full_name, email, phone, admin_note FROM professional_applications WHERE id = :id');
$stmt->execute(['id' => $id]);
$pa = $stmt->fetch();

if (!$pa) {
    flash_set('error', 'Candidatura não encontrada.');
    header('Location: /professional_applications_list.php');
    exit;
}

view_header('Reprovar candidatura');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Candidatura</div>';
echo '<div style="font-size:22px;font-weight:900">Reprovar candidatura</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">#' . (int)$pa['id'] . ' — ' . h((string)$pa['full_name']) . '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professional_applications_view.php?id=' . (int)$pa['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/professional_applications_reject_post.php" style="display:grid;gap:12px;max-width:820px">';
echo '<input type="hidden" name="id" value="' . (int)$pa['id'] . '">';
echo '<label>Motivo da reprovação (WhatsApp + e-mail)<textarea name="reason" rows="5" required placeholder="Ex: Conselho inválido / dados incompletos..." ></textarea></label>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
echo '<a class="btn" href="/professional_applications_view.php?id=' . (int)$pa['id'] . '">Cancelar</a>';
echo '<button class="btn btnPrimary" type="submit" onclick="return confirm(\'Confirmar reprovação?\')">Reprovar e notificar</button>';
echo '</div>';
echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
