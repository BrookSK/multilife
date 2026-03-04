<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('professional_docs.review');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT d.*, u.name AS professional_name, u.email AS professional_email, r.name AS reviewed_by_name
     FROM professional_documentations d
     INNER JOIN users u ON u.id = d.professional_user_id
     LEFT JOIN users r ON r.id = d.reviewed_by_user_id
     WHERE d.id = :id'
);
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Registro não encontrado.');
    header('Location: /professional_docs_review_list.php');
    exit;
}

view_header('Revisão #' . (string)$d['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Revisão #' . (int)$d['id'] . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Status:</strong> ' . h((string)$d['status']) . ' &nbsp; <strong>Profissional:</strong> ' . h((string)$d['professional_name']) . ' &nbsp; <strong>Paciente:</strong> ' . h((string)$d['patient_ref']);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/professional_docs_review_list.php">Voltar</a>';

echo '<form method="post" action="/professional_docs_approve_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn btnPrimary" type="submit">Aprovar</button>';
echo '</form>';

echo '<form method="post" action="/professional_docs_reject_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<input type="hidden" name="review_note" value="">';
echo '<button class="btn" type="submit">Reprovar</button>';
echo '</form>';

echo '</div>';
echo '</div>';

echo '</section>';

echo '<section class="card col12">';
echo '<div class="grid">';
echo '<div class="col6">';
echo '<div class="pill" style="display:block"><strong>Profissional:</strong> ' . h((string)$d['professional_name']) . ' — ' . h((string)$d['professional_email']) . '</div>';
echo '<div class="pill" style="display:block;margin-top:10px"><strong>Sessões:</strong> ' . (int)$d['sessions_count'] . '</div>';
echo '<div class="pill" style="display:block;margin-top:10px"><strong>Enviado em:</strong> ' . h((string)($d['submitted_at'] ?? '')) . '</div>';
echo '<div class="pill" style="display:block;margin-top:10px"><strong>Vence em:</strong> ' . h((string)($d['due_at'] ?? '')) . '</div>';
echo '</div>';

echo '<div class="col6">';
echo '<div class="pill" style="display:block"><strong>Faturamento:</strong><div style="white-space:pre-wrap;margin-top:6px">' . h((string)($d['billing_docs'] ?? '')) . '</div></div>';
echo '<div class="pill" style="display:block;margin-top:10px"><strong>Produtividade:</strong><div style="white-space:pre-wrap;margin-top:6px">' . h((string)($d['productivity_docs'] ?? '')) . '</div></div>';
echo '</div>';

echo '<div class="col12">';
echo '<div class="pill" style="display:block"><strong>Observações:</strong><div style="white-space:pre-wrap;margin-top:6px">' . h((string)($d['notes'] ?? '')) . '</div></div>';
echo '</div>';

echo '<div class="col12">';
echo '<form method="post" action="/professional_docs_reject_post.php" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<textarea name="review_note" rows="2" placeholder="Motivo da reprovação (opcional)" style="flex:1;min-width:260px"></textarea>';
echo '<button class="btn" type="submit">Reprovar com motivo</button>';
echo '</form>';
echo '</div>';

echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
