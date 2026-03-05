<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '') {
    view_header('Confirmação');
    echo '<div class="card"><div style="font-weight:900">Link inválido.</div></div>';
    view_footer();
    exit;
}

$stmt = db()->prepare(
    'SELECT f.id AS feedback_id, f.status AS feedback_status, f.note AS feedback_note, f.created_at AS feedback_created_at, '
    . 'a.id AS appointment_id, a.first_at, '
    . 'p.full_name AS patient_name, '
    . 'u.name AS professional_name '
    . 'FROM appointment_patient_feedback f '
    . 'INNER JOIN appointments a ON a.id = f.appointment_id '
    . 'INNER JOIN patients p ON p.id = a.patient_id '
    . 'INNER JOIN users u ON u.id = a.professional_user_id '
    . 'WHERE f.token = :t '
    . 'LIMIT 1'
);
$stmt->execute(['t' => $token]);
$row = $stmt->fetch();

if (!$row) {
    view_header('Confirmação');
    echo '<div class="card"><div style="font-weight:900">Link inválido ou expirado.</div></div>';
    view_footer();
    exit;
}

view_header('Confirmação do atendimento');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="font-size:22px;font-weight:900">Confirmação do atendimento</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Paciente: ' . h((string)$row['patient_name']) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Profissional: ' . h((string)$row['professional_name']) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Data/hora: ' . h((string)$row['first_at']) . '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:10px">Selecione uma opção</div>';

echo '<form method="post" action="/public/appointment_feedback_post.php" style="display:grid;gap:12px;max-width:720px">';
echo '<input type="hidden" name="token" value="' . h($token) . '">';

echo '<label>Resposta<select name="status" required>';
echo '<option value="">Selecione</option>';
echo '<option value="confirmed">Confirmar presença do profissional</option>';
echo '<option value="professional_absent">Profissional não compareceu / ausência</option>';
echo '<option value="cancelled">Cancelar / reagendar</option>';
echo '</select></label>';

echo '<label>Observação (opcional)<textarea name="note" rows="3" placeholder="Descreva rapidamente o ocorrido..."></textarea></label>';

echo '<button class="btn btnPrimary" type="submit">Enviar</button>';

echo '</form>';

echo '</section>';

echo '</div>';

view_footer();
