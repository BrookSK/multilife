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

$stmt = db()->prepare(
    "SELECT pdd.doc_kind, doc.id AS document_id, doc.category, doc.title, v.stored_path, v.original_name, v.uploaded_at\n"
    . "FROM professional_documentation_documents pdd\n"
    . "INNER JOIN documents doc ON doc.id = pdd.document_id\n"
    . "INNER JOIN document_versions v ON v.document_id = doc.id AND v.version_no = 1\n"
    . "WHERE pdd.documentation_id = :id\n"
    . "ORDER BY v.uploaded_at DESC"
);
$stmt->execute(['id' => $id]);
$linkedDocs = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT p.id, p.full_name\n'
    . 'FROM patients p\n'
    . 'INNER JOIN patient_professionals pp ON pp.patient_id = p.id\n'
    . 'WHERE p.deleted_at IS NULL AND pp.professional_user_id = :uid AND pp.is_active = 1\n'
    . 'ORDER BY p.full_name ASC'
);
$stmt->execute(['uid' => $uid]);
$patients = $stmt->fetchAll();

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

if (count($patients) > 0) {
    $curPid = (int)($d['patient_id'] ?? 0);
    echo '<label>Paciente<select name="patient_id" required>';
    echo '<option value="">Selecione</option>';
    foreach ($patients as $p) {
        $sel = ((int)$p['id'] === $curPid) ? ' selected' : '';
        echo '<option value="' . (int)$p['id'] . '"' . $sel . '>' . h((string)$p['full_name']) . ' (#' . (int)$p['id'] . ')</option>';
    }
    echo '</select></label>';
} else {
    echo '<label>Paciente (referência)<input name="patient_ref" required maxlength="160" value="' . h((string)$d['patient_ref']) . '"></label>';
}

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

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Uploads (Faturamento / Produtividade)</div>';

if ((string)$d['status'] !== 'draft') {
    echo '<div class="pill" style="display:block">Apenas rascunhos podem receber anexos.</div>';
} else {
    echo '<form method="post" action="/professional_docs_upload_files_post.php" enctype="multipart/form-data" style="display:grid;gap:12px;max-width:820px">';
    echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';

    echo '<label>Documentos de faturamento (múltiplos arquivos)<input type="file" name="billing_files[]" multiple></label>';
    echo '<label>Documentos de produtividade (múltiplos arquivos)<input type="file" name="productivity_files[]" multiple></label>';

    echo '<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end">';
    echo '<button class="btn btnPrimary" type="submit">Enviar arquivos</button>';
    echo '</div>';
    echo '</form>';
}

echo '<div style="height:10px"></div>';
if (count($linkedDocs) === 0) {
    echo '<div class="pill" style="display:block">Nenhum arquivo anexado ainda.</div>';
} else {
    echo '<div style="overflow:auto">';
    echo '<table>';
    echo '<thead><tr><th>Tipo</th><th>Documento</th><th>Arquivo</th><th>Enviado em</th><th style="text-align:right">Ações</th></tr></thead><tbody>';
    foreach ($linkedDocs as $ld) {
        $kind = (string)$ld['doc_kind'];
        $kindLabel = $kind === 'billing' ? 'Faturamento' : 'Produtividade';
        echo '<tr>';
        echo '<td>' . h($kindLabel) . '</td>';
        echo '<td>#' . (int)$ld['document_id'] . '</td>';
        echo '<td>' . h((string)($ld['original_name'] ?? '')) . '</td>';
        echo '<td>' . h((string)($ld['uploaded_at'] ?? '')) . '</td>';
        echo '<td style="text-align:right"><a class="btn" href="/documents_view.php?id=' . (int)$ld['document_id'] . '">Abrir</a></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
