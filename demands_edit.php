<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

demand_form_ensure_schema();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT * FROM demands WHERE id = :id');
$stmt->execute(['id' => $id]);
$d = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

// Se o POST voltou com erro, mostra o que foi digitado.
$old = $_SESSION['demand_form_old'] ?? null;
unset($_SESSION['demand_form_old']);
if (is_array($old)) {
    $d = array_merge($d, $old);
}

// Motivo registrado quando o card foi para Tratamento Manual (ajuda a entender e-mails).
$manualNote = '';
try {
    $st = db()->prepare("SELECT note FROM demand_status_logs WHERE demand_id = :id AND new_status = 'tratamento_manual' ORDER BY id DESC LIMIT 1");
    $st->execute(['id' => $id]);
    $manualNote = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {}

view_header('Editar card');

echo '<div class="card">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Editar card #' . (int)$d['id'] . '</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Status atual: <strong>' . h(function_exists('format_status_label') ? format_status_label((string)$d['status']) : (string)$d['status']) . '</strong>';
if ($manualNote !== '' && (string)$d['status'] === 'tratamento_manual') {
    echo ' · Registro: ' . h($manualNote);
}
echo '</div>';
echo '</div>';
echo '<a class="btn" href="/demands_view.php?id=' . (int)$d['id'] . '">Voltar</a>';
echo '</div>';
echo '<div style="height:14px"></div>';

demand_form_render($d, '/demands_edit_post.php', [
    'id' => (int)$d['id'],
    'submit' => 'Salvar alterações',
    'cancel_url' => '/demands_view.php?id=' . (int)$d['id'],
]);

echo '</div>';

view_footer();
