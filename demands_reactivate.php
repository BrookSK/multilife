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

if ($demand['status'] !== 'cancelado') {
    flash_set('error', 'Esta demanda não está cancelada.');
    header('Location: /demands_view.php?id=' . $id);
    exit;
}

view_header('Reativar Demanda #' . $id);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Reativar Demanda</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo 'Card #' . (int)$demand['id'] . ' - ' . h((string)$demand['title']);
echo '</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_view.php?id=' . $id . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Mostrar informações do cancelamento
if (!empty($demand['cancellation_reason'])) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:700;margin-bottom:8px">Informações do Cancelamento</div>';
    echo '<div style="padding:12px;background:hsla(var(--destructive)/.05);border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));line-height:1.6">';
    echo '<strong>Motivo:</strong> ' . h((string)$demand['cancellation_reason']);
    if (!empty($demand['cancelled_at'])) {
        echo '<br><strong>Cancelado em:</strong> ' . h((string)$demand['cancelled_at']);
    }
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

echo '<section class="card col12">';
echo '<form method="post" action="/demands_reactivate_post.php" style="display:grid;gap:14px">';
echo '<input type="hidden" name="id" value="' . $id . '">';

echo '<div style="padding:12px;background:hsla(var(--primary)/.1);border:1px solid hsl(var(--primary));border-radius:8px">';
echo '<div style="font-size:13px;color:hsl(var(--primary));line-height:1.6">';
echo '<strong>ℹ️ Informação:</strong> Ao reativar esta demanda, ela voltará ao status "em_captacao" e aparecerá novamente na lista principal.';
echo '</div>';
echo '</div>';

echo '<label>Justificativa da Reativação<textarea name="reactivation_reason" rows="4" required placeholder="Descreva o motivo da reativação desta demanda..."></textarea></label>';

echo '<div style="display:flex;justify-content:flex-end;gap:10px">';
echo '<a href="/demands_view.php?id=' . $id . '" class="btn">Cancelar</a>';
echo '<button type="submit" class="btn btnPrimary">Confirmar Reativação</button>';
echo '</div>';

echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
