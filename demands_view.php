<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT d.*, u.name AS assumed_by_name
     FROM demands d
     LEFT JOIN users u ON u.id = d.assumed_by_user_id
     WHERE d.id = :id'
);
$stmt->execute(['id' => $id]);
$d = $stmt->fetch();

if (!$d) {
    flash_set('error', 'Demanda não encontrada.');
    header('Location: /demands_list.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT l.id, l.old_status, l.new_status, l.note, l.created_at, u.name AS user_name
     FROM demand_status_logs l
     LEFT JOIN users u ON u.id = l.user_id
     WHERE l.demand_id = :id
     ORDER BY l.id DESC'
);
$stmt->execute(['id' => $id]);
$statusLogs = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT dl.id, dl.message, dl.dispatch_status, dl.error_message, dl.created_at,
            g.name AS group_name, g.city, g.state, g.specialty,
            u.name AS dispatched_by_name
     FROM demand_dispatch_logs dl
     LEFT JOIN whatsapp_groups g ON g.id = dl.group_id
     LEFT JOIN users u ON u.id = dl.dispatched_by_user_id
     WHERE dl.demand_id = :id
     ORDER BY dl.id DESC'
);
$stmt->execute(['id' => $id]);
$dispatchLogs = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT m.id, m.group_id, m.sender_phone, m.body, m.received_at, m.created_at, g.name AS group_name\n"
    . "FROM whatsapp_group_messages m\n"
    . "LEFT JOIN whatsapp_groups g ON g.id = m.group_id\n"
    . "WHERE m.demand_id = :id\n"
    . "ORDER BY m.id DESC\n"
    . "LIMIT 200"
);
$stmt->execute(['id' => $id]);
$groupMessages = $stmt->fetchAll();

$loc = trim((string)($d['location_city'] ?? ''));
$uf = trim((string)($d['location_state'] ?? ''));
$locTxt = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : '-';
$assumedBy = $d['assumed_by_name'] ? (string)$d['assumed_by_name'] : '-';

view_header('Demanda #' . (string)$d['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Card</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$d['id'] . ' — ' . h((string)$d['title']) . '</div>';

$st = (string)$d['status'];
$badgeCls = 'badgeInfo';
if ($st === 'admitido') {
    $badgeCls = 'badgeSuccess';
} elseif ($st === 'em_captacao' || $st === 'tratamento_manual') {
    $badgeCls = 'badgeWarn';
} elseif ($st === 'cancelado') {
    $badgeCls = 'badgeDanger';
}

echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<span class="badge ' . h($badgeCls) . '">' . h($st) . '</span>';

// Badge de urgência
$urgency = trim((string)($d['urgency'] ?? ''));
if ($urgency === 'urgente') {
    echo '<span class="badge badgeDanger" style="background:hsl(0,84%,60%);color:#fff;font-weight:700;font-size:13px">URGENTE</span>';
} elseif ($urgency === 'normal') {
    echo '<span class="badge badgeWarn" style="font-size:12px">Normal</span>';
} elseif ($urgency === 'baixa') {
    echo '<span class="badge badgeInfo" style="font-size:12px">Baixa</span>';
}

echo '<span style="color:hsl(var(--muted-foreground));font-size:13px"><strong>Local:</strong> ' . h($locTxt) . '</span>';
echo '<span style="color:hsl(var(--muted-foreground));font-size:13px"><strong>Especialidade:</strong> ' . h((string)($d['specialty'] ?? '-')) . '</span>';
echo '<span style="color:hsl(var(--muted-foreground));font-size:13px"><strong>Responsável:</strong> ' . h($assumedBy) . '</span>';
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/demands_list.php">Voltar</a>';
echo '<a class="btn" href="/demands_edit.php?id=' . (int)$d['id'] . '">Editar</a>';

echo '<form method="post" action="/demands_assume_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn" type="submit">Assumir Demanda</button>';
echo '</form>';

echo '<form method="post" action="/demands_release_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn" type="submit">Devolver</button>';
echo '</form>';

echo '<form method="post" action="/demands_dispatch_whatsapp_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<button class="btn btnPrimary" type="submit">Realizar Captação</button>';
echo '</form>';

echo '</div>';
echo '</div>';

echo '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<form method="post" action="/demands_set_status_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<select name="status" style="min-width:220px">';
$allowed = ['aguardando_captacao','tratamento_manual','em_captacao','admitido','cancelado'];
foreach ($allowed as $st) {
    $sel = ((string)$d['status'] === $st) ? ' selected' : '';
    echo '<option value="' . h($st) . '"' . $sel . '>' . h($st) . '</option>';
}
echo '</select>';
echo '<input name="note" placeholder="Observação (opcional)" style="min-width:240px;flex:1">';
echo '<button class="btn" type="submit">Atualizar status</button>';
echo '</form>';
echo '</div>';

echo '</section>';

// Detalhes

echo '<section class="card col12">';

echo '<div style="font-weight:900;margin-bottom:12px">Detalhes</div>';

echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;line-height:1.7">';
echo '<div><strong>Origem:</strong> ' . h((string)($d['origin_email'] ?? '-')) . '</div>';

// Valor do serviço
$procedureValue = $d['procedure_value'] !== null ? (float)$d['procedure_value'] : null;
if ($procedureValue !== null && $procedureValue > 0) {
    echo '<div style="margin-top:8px"><strong>Valor do Serviço:</strong> R$ ' . number_format($procedureValue, 2, ',', '.') . '</div>';
}

// Resumo da IA (se disponível)
$aiSummary = trim((string)($d['ai_summary'] ?? ''));
if ($aiSummary !== '') {
    echo '<div style="margin-top:16px;padding:12px;background:hsla(var(--primary)/.05);border-left:3px solid hsl(var(--primary));border-radius:6px">';
    echo '<div style="font-weight:700;color:hsl(var(--primary));margin-bottom:6px">Resumo da Necessidade (IA)</div>';
    echo '<div style="white-space:pre-wrap;color:hsl(var(--foreground))">' . h($aiSummary) . '</div>';
    echo '</div>';
}

// Separador
echo '<hr style="margin:20px 0;border:none;border-top:1px solid hsl(var(--border))">';

// E-mail original completo
$description = trim((string)($d['description'] ?? ''));
if ($description !== '') {
    echo '<div style="margin-top:16px">';
    echo '<div style="font-weight:700;color:hsl(var(--muted-foreground));margin-bottom:8px">E-mail Original</div>';
    echo '<div style="white-space:pre-wrap;font-size:13px;color:hsl(var(--foreground))">' . h($description) . '</div>';
    echo '</div>';
}

echo '</div>';
echo '</section>';

// Logs

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Histórico de status</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Quando</th><th>Usuário</th><th>De</th><th>Para</th><th>Obs.</th>';
echo '</tr></thead><tbody>';
foreach ($statusLogs as $l) {
    echo '<tr>';
    echo '<td>' . h((string)$l['created_at']) . '</td>';
    echo '<td>' . h((string)($l['user_name'] ?? '-')) . '</td>';
    echo '<td>' . h((string)($l['old_status'] ?? '-')) . '</td>';
    echo '<td>' . h((string)$l['new_status']) . '</td>';
    echo '<td>' . h((string)($l['note'] ?? '')) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Respostas WhatsApp (grupos)</div>';
if (count($groupMessages) === 0) {
    echo '<div class="pill" style="display:block">Nenhuma resposta registrada ainda.</div>';
} else {
    echo '<div style="display:grid;gap:10px">';
    foreach ($groupMessages as $m) {
        $sender = (string)$m['sender_phone'];
        $senderDigits = preg_replace('/\D+/', '', $sender);

        $stmt2 = db()->prepare(
            "SELECT u.id, u.name\n"
            . "FROM users u\n"
            . "INNER JOIN user_roles ur ON ur.user_id = u.id\n"
            . "INNER JOIN roles r ON r.id = ur.role_id\n"
            . "WHERE u.status='active' AND r.slug='profissional'\n"
            . "  AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone,''),' ',''),'-',''),'(',''),')','') LIKE :p\n"
            . "LIMIT 1"
        );
        $stmt2->execute(['p' => '%' . $senderDigits . '%']);
        $prof = $stmt2->fetch();

        $when = $m['received_at'] ? (string)$m['received_at'] : (string)$m['created_at'];
        $gname = $m['group_name'] ? (string)$m['group_name'] : '-';

        echo '<div style="border:1px solid hsl(var(--border));border-radius:14px;padding:12px;background:hsl(var(--muted)/.15)">';
        echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;flex-wrap:wrap">';
        echo '<div style="min-width:0">';
        echo '<div style="font-weight:900;font-size:13px">' . h($sender) . '</div>';
        echo '<div style="margin-top:4px;color:hsl(var(--muted-foreground));font-size:12px">Grupo: ' . h($gname) . ' | ' . h($when) . '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="margin-top:10px;white-space:pre-wrap;font-size:14px;line-height:1.6">' . h((string)$m['body']) . '</div>';

        if ($prof) {
            echo '<div style="margin-top:14px;padding:12px;border-radius:10px;background:hsl(var(--primary)/.08);border:2px solid hsl(var(--primary)/.3)">';
            echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
            echo '<div>';
            echo '<div style="font-weight:900;color:hsl(var(--primary));font-size:13px">✓ Profissional Identificado</div>';
            echo '<div style="margin-top:4px;font-size:14px">' . h((string)$prof['name']) . ' <span style="color:hsl(var(--muted-foreground));font-size:12px">(ID: ' . (int)$prof['id'] . ')</span></div>';
            echo '</div>';
            echo '<form method="post" action="/chat_open_professional_post.php" style="display:inline">';
            echo '<input type="hidden" name="demand_id" value="' . (int)$d['id'] . '">';
            echo '<input type="hidden" name="professional_user_id" value="' . (int)$prof['id'] . '">';
            echo '<button class="btn btnPrimary" type="submit" style="font-weight:900;padding:10px 20px">→ Selecionar e Abrir Chat</button>';
            echo '</form>';
            echo '</div>';
            echo '</div>';
        } else {
            echo '<div style="margin-top:14px;padding:12px;border-radius:10px;background:hsl(var(--muted)/.25);border:1px solid hsl(var(--border))">';
            echo '<div style="color:hsl(var(--muted-foreground));font-size:13px">⚠ Profissional não identificado automaticamente</div>';
            echo '<div style="margin-top:4px;font-size:12px;color:hsl(var(--muted-foreground))">Apenas usuários cadastrados com telefone vinculado podem ser selecionados.</div>';
            echo '</div>';
        }

        echo '</div>';
    }
    echo '</div>';
}
echo '</section>';

echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:8px">Disparos em grupos (logs)</div>';
echo '<div style="overflow:auto">';
echo '<table>';
echo '<thead><tr>';
echo '<th>Quando</th><th>Usuário</th><th>Grupo</th><th>Status</th><th>Erro</th>';
echo '</tr></thead><tbody>';
foreach ($dispatchLogs as $l) {
    $g = $l['group_name'] ? (string)$l['group_name'] : '-';
    echo '<tr>';
    echo '<td>' . h((string)$l['created_at']) . '</td>';
    echo '<td>' . h((string)($l['dispatched_by_name'] ?? '-')) . '</td>';
    echo '<td>' . h($g) . '</td>';
    echo '<td>' . h((string)$l['dispatch_status']) . '</td>';
    echo '<td>' . h((string)($l['error_message'] ?? '')) . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';
echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
