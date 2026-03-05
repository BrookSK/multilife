<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$demand = $stmt->fetch();

if (!$demand) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

if ($demand['status'] === 'cancelado') {
    flash_set('error', 'Esta demanda já está cancelada.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

view_header('Cancelar Demanda #' . $id);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Cancelar Demanda</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo 'Card #' . (int)$demand['id'] . ' - ' . h((string)$demand['title']);
echo '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_view.php?id=' . $id . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<form method="post" action="/demands_cancel_post.php" style="display:grid;gap:14px">';
echo '<input type="hidden" name="id" value="' . $id . '">';

echo '<div style="padding:12px;background:hsla(var(--destructive)/.1);border:1px solid hsl(var(--destructive));border-radius:8px">';
echo '<div style="font-size:13px;color:hsl(var(--destructive));line-height:1.6">';
echo '<strong>⚠️ Atenção:</strong> Ao cancelar esta demanda, ela será marcada como "cancelado" e não aparecerá mais na lista principal.';
echo '</div>';
echo '</div>';

echo '<label>Motivo do Cancelamento<textarea name="cancellation_reason" rows="4" required placeholder="Descreva o motivo do cancelamento desta demanda..."></textarea></label>';

echo '<div style="display:flex;justify-content:flex-end;gap:10px">';
echo '<a href="/demands_view.php?id=' . $id . '" class="btn">Cancelar</a>';
echo '<button type="submit" class="btn" style="background:hsl(var(--destructive));color:white">Confirmar Cancelamento</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
