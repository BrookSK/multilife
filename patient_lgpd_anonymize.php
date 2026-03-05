<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('patients.lgpd.anonymize');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare('SELECT id, full_name, cpf, email, whatsapp, phone_primary, phone_secondary FROM patients WHERE id = :id AND deleted_at IS NULL');
$stmt->execute(['id' => $id]);
$p = $stmt->fetch();

if (!$p) {
    flash_set('error', 'Paciente não encontrado.');
    header('Location: /patients_list.php');
    exit;
}

view_header('LGPD - Anonimizar paciente');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Anonimizar paciente (LGPD)</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Ação irreversível (opera por sobrescrita de dados pessoais).</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/patients_view.php?id=' . (int)$p['id'] . '">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div class="pill" style="display:block;margin-bottom:10px">';
echo '<strong>Paciente:</strong> ' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')';
echo '</div>';

echo '<div class="pill" style="display:block;margin-bottom:10px">';
echo '<strong>O que será anonimizado:</strong><br>';
echo 'Nome, CPF, RG, data de nascimento, sexo, contato (WhatsApp/telefones/e-mail), endereço, emergência, convênio e campos JSON de saúde/histórico/responsável/documentos/financeiro/LGPD.';
echo '</div>';

echo '<div class="pill" style="display:block;margin-bottom:10px">';
echo '<strong>O que será preservado:</strong><br>';
echo 'IDs, vínculos, prontuário, agendamentos, financeiro relacional (contas), documentos (arquivos) e auditoria.';
echo '</div>';

echo '<form method="post" action="/patient_lgpd_anonymize_post.php" style="display:grid;gap:10px;max-width:720px">';
echo '<input type="hidden" name="id" value="' . (int)$p['id'] . '">';
echo '<label>Motivo / observação (vai para auditoria)<input name="note" maxlength="255" placeholder="Ex: solicitação do titular"></label>';
echo '<button class="btn btnPrimary" type="submit" onclick="return confirm(\'Confirmar anonimização? Essa ação não pode ser desfeita.\')">Confirmar anonimização</button>';
echo '</form>';

echo '</section>';

echo '</div>';

view_footer();
