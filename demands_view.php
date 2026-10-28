<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('demands.manage');

demand_form_ensure_schema();

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

// Status EFETIVO do card: se existe atendimento vinculado já concluído (ex.: por óbito),
// o card é considerado "Concluído", igual à lógica da coluna do kanban (demands_list.php).
// Isso alinha o badge de detalhe com a coluna e corrige também cards antigos.
try {
    $paDoneStmt = db()->prepare(
        "SELECT COUNT(*) FROM patient_assignments pa WHERE pa.demand_id = :id AND pa.status = 'completed'"
    );
    $paDoneStmt->execute(['id' => $id]);
    $hasCompletedAssignment = ((int)$paDoneStmt->fetchColumn() > 0);
} catch (Throwable $e) {
    $hasCompletedAssignment = false;
}
if ($hasCompletedAssignment && (string)$d['status'] !== 'cancelado') {
    // Autocorreção: se o status real ficou defasado (ex.: 'admitido'), grava 'concluido'
    // para que o histórico e futuras leituras fiquem consistentes.
    if ((string)$d['status'] !== 'concluido') {
        try {
            $fixStmt = db()->prepare("UPDATE demands SET status = 'concluido' WHERE id = :id");
            $fixStmt->execute(['id' => $id]);
            try {
                $fixLog = db()->prepare(
                    "INSERT INTO demand_status_logs (demand_id, old_status, new_status, user_id, note)
                     VALUES (:did, :os, 'concluido', :uid, :note)"
                );
                $fixLog->execute([
                    'did' => $id,
                    'os' => (string)$d['status'],
                    'uid' => auth_user_id(),
                    'note' => 'Ajuste automático: atendimento vinculado já concluído (óbito/encerramento).',
                ]);
            } catch (Throwable $e) {}
        } catch (Throwable $e) {}
        $d['status'] = 'concluido';
    }
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

// Função para formatar labels de status
function format_status_label(string $status): string {
    $labels = [
        'aguardando_captacao' => 'Aguardando Captação',
        'tratamento_manual' => 'Tratamento Manual',
        'em_captacao' => 'Em Captação',
        'autorizacao_negada' => 'Autorização Negada',
        'admitido' => 'Admitido',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];
    return $labels[$status] ?? ucfirst(str_replace('_', ' ', $status));
}

$loc = trim((string)($d['location_city'] ?? ''));
$uf = trim((string)($d['location_state'] ?? ''));
$street = trim((string)($d['location_street'] ?? ''));
$neighborhood = trim((string)($d['location_neighborhood'] ?? ''));
$locNumber = trim((string)($d['location_number'] ?? ''));

// Montar texto do local com endereço completo
$locParts = [];
if ($street !== '') {
    $addr = $street;
    if ($locNumber !== '') $addr .= ', ' . $locNumber;
    $locParts[] = $addr;
}
if ($neighborhood !== '') $locParts[] = $neighborhood;
$cityState = $loc !== '' ? ($loc . ($uf !== '' ? '/' . $uf : '')) : ($uf !== '' ? $uf : '');
$zoneTxt = trim((string)($d['location_zone'] ?? ''));
if ($cityState !== '' && $zoneTxt !== '') $cityState .= ' (' . $zoneTxt . ')';
if ($cityState !== '') $locParts[] = $cityState;
// Fallback: endereço informado como campo único no card manual (ainda não separado pela IA).
$locationRaw = trim((string)($d['location_raw'] ?? ''));
if (count($locParts) === 0 && $locationRaw !== '') {
    $locTxt = $locationRaw;
} else {
    $locTxt = count($locParts) > 0 ? implode(' — ', $locParts) : '-';
}
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
if ($st === 'admitido' || $st === 'concluido') {
    $badgeCls = 'badgeSuccess';
} elseif ($st === 'em_captacao' || $st === 'tratamento_manual') {
    $badgeCls = 'badgeWarn';
} elseif ($st === 'cancelado') {
    $badgeCls = 'badgeDanger';
}

echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">';
echo '<span class="badge ' . h($badgeCls) . '">' . h(format_status_label($st)) . '</span>';

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

// O QUE FALTA no card (explícito): bloqueia a captação ou deixa a mensagem incompleta.
if (in_array($st, ['tratamento_manual', 'aguardando_captacao', 'em_captacao'], true)) {
    $missingNow = demand_missing_fields($d);
    if ($missingNow['blocking'] || $missingNow['recommended']) {
        $isBlocking = (bool)$missingNow['blocking'];
        echo '<div style="margin-top:12px;padding:12px 14px;border-radius:8px;border:1px solid ' . ($isBlocking ? 'hsl(0,84%,60%)' : 'hsl(36,90%,55%)') . ';background:' . ($isBlocking ? 'hsla(0,84%,60%,.08)' : 'hsla(36,90%,55%,.08)') . ';font-size:13px;line-height:1.5">';
        if ($isBlocking) {
            echo '<div><strong>⚠️ Captação bloqueada — falta:</strong> ' . h(implode(', ', $missingNow['blocking'])) . '</div>';
        }
        if ($missingNow['recommended']) {
            echo '<div><strong>Recomendado completar (a mensagem sai incompleta):</strong> ' . h(implode(', ', $missingNow['recommended'])) . '</div>';
        }
        echo '<a class="btn btnPrimary" style="margin-top:8px;display:inline-block" href="/demands_edit.php?id=' . (int)$d['id'] . '">Completar dados do card</a>';
        echo '</div>';
    }
}

// Faixa verde quando demanda assumida
if ($d['assumed_by_user_id'] && $d['assumed_by_name']) {
    echo '<div style="margin-top:12px;padding:12px 16px;background:hsl(var(--success));border-radius:8px;font-size:14px;font-weight:700;color:#fff;text-align:center;box-shadow:0 2px 4px hsla(var(--success)/.3)">';
    echo '✓ Assumida por: ' . h($assumedBy);
    echo '</div>';
}

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

// Buscar sub-solicitações (se houver múltiplas)
$subRequests = [];
$hasMultiReqs = (int)($d['has_multiple_requests'] ?? 0);
if ($hasMultiReqs === 1) {
    $stmtSub = db()->prepare('SELECT * FROM demand_sub_requests WHERE demand_id = :did ORDER BY id ASC');
    $stmtSub->execute(['did' => $id]);
    $subRequests = $stmtSub->fetchAll();
}

echo '<form method="post" action="/demands_dispatch_whatsapp_post.php" class="dispatch-form" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
if (count($subRequests) > 1) {
    echo '<button class="btn btnPrimary" type="button" onclick="document.getElementById(\'modalSubRequests\').style.display=\'flex\'">Realizar Captação</button>';
} else {
    echo '<button class="btn btnPrimary" type="submit">Realizar Captação</button>';
}
echo '</form>';

if ($st !== 'cancelado') {
    echo '<a class="btn" href="/demands_cancel.php?id=' . (int)$d['id'] . '" style="background:hsl(0,84%,60%);color:#fff">Cancelar Card</a>';
} else {
    echo '<a class="btn btnPrimary" href="/demands_reactivate.php?id=' . (int)$d['id'] . '">Reativar Card</a>';
}

echo '</div>';
echo '</div>';

echo '<div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<form method="post" action="/demands_set_status_post.php" style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<select name="status" style="min-width:220px">';
$allowed = ['aguardando_captacao','tratamento_manual','em_captacao','admitido','concluido','cancelado'];
foreach ($allowed as $st) {
    $sel = ((string)$d['status'] === $st) ? ' selected' : '';
    echo '<option value="' . h($st) . '"' . $sel . '>' . h(format_status_label($st)) . '</option>';
}
echo '</select>';
echo '<input name="note" placeholder="Observação (opcional)" style="min-width:240px;flex:1">';
echo '<button class="btn" type="submit">Atualizar status</button>';
echo '</form>';
echo '</div>';

echo '</section>';

// Informações de Cancelamento/Reativação
if ($st === 'cancelado' && !empty($d['cancellation_reason'])) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:12px;color:hsl(var(--destructive))">Informações do Cancelamento</div>';
    echo '<div style="padding:12px;background:hsla(var(--destructive)/.05);border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));line-height:1.6">';
    echo '<strong>Motivo:</strong> ' . h((string)$d['cancellation_reason']) . '<br>';
    if (!empty($d['cancelled_at'])) {
        echo '<strong>Cancelado em:</strong> ' . h((string)$d['cancelled_at']) . '<br>';
    }
    if (!empty($d['cancelled_by_user_id'])) {
        $cancelledByStmt = db()->prepare('SELECT name FROM users WHERE id = :id');
        $cancelledByStmt->execute(['id' => $d['cancelled_by_user_id']]);
        $cancelledByName = $cancelledByStmt->fetchColumn();
        if ($cancelledByName) {
            echo '<strong>Cancelado por:</strong> ' . h((string)$cancelledByName);
        }
    }
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

if (!empty($d['reactivation_reason'])) {
    echo '<section class="card col12">';
    echo '<div style="font-weight:900;margin-bottom:12px;color:hsl(var(--primary))">Informações da Reativação</div>';
    echo '<div style="padding:12px;background:hsla(var(--primary)/.05);border:1px solid hsl(var(--border));border-radius:8px">';
    echo '<div style="font-size:14px;color:hsl(var(--muted-foreground));line-height:1.6">';
    echo '<strong>Justificativa:</strong> ' . h((string)$d['reactivation_reason']) . '<br>';
    if (!empty($d['reactivated_at'])) {
        echo '<strong>Reativado em:</strong> ' . h((string)$d['reactivated_at']) . '<br>';
    }
    if (!empty($d['reactivated_by_user_id'])) {
        $reactivatedByStmt = db()->prepare('SELECT name FROM users WHERE id = :id');
        $reactivatedByStmt->execute(['id' => $d['reactivated_by_user_id']]);
        $reactivatedByName = $reactivatedByStmt->fetchColumn();
        if ($reactivatedByName) {
            echo '<strong>Reativado por:</strong> ' . h((string)$reactivatedByName);
        }
    }
    echo '</div>';
    echo '</div>';
    echo '</section>';
}

// Observação da Captação
echo '<section class="card col12">';
echo '<div style="font-weight:900;margin-bottom:12px">📝 Observação da Captação</div>';
echo '<form method="post" action="/demands_captation_note_post.php" style="display:flex;gap:10px;align-items:flex-start">';
echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
echo '<textarea name="captation_note" rows="3" placeholder="Insira uma observação que será incluída na mensagem de captação enviada ao grupo WhatsApp..." style="flex:1;min-width:0;resize:vertical">' . h((string)($d['captation_note'] ?? '')) . '</textarea>';
echo '<button class="btn btnPrimary" type="submit">Salvar</button>';
echo '</form>';
if (trim((string)($d['captation_note'] ?? '')) !== '') {
    echo '<div style="margin-top:8px;font-size:12px;color:hsl(var(--muted-foreground))">⚠️ Esta observação será exibida na mensagem de captação enviada ao grupo WhatsApp.</div>';
}
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

        // Antes: 1 consulta com LIKE '%...%' (varredura da tabela users) POR mensagem,
        // até 200 por card. Agora os telefones dos profissionais são carregados uma vez.
        if (!isset($profByPhoneKey)) {
            $profByPhoneKey = [];
            $profRows = db()->query(
                "SELECT u.id, u.name, u.phone FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE u.status = 'active' AND r.slug = 'profissional' AND u.phone IS NOT NULL AND u.phone <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($profRows as $pr) {
                $k = dispatch_member_key((string)$pr['phone']);
                if ($k !== '' && !isset($profByPhoneKey[$k])) {
                    $profByPhoneKey[$k] = ['id' => $pr['id'], 'name' => $pr['name']];
                }
                // Cadastros sem DDI 55: indexa também com o prefixo.
                $k55 = dispatch_member_key('55' . preg_replace('/\D+/', '', (string)$pr['phone']));
                if ($k55 !== '' && !isset($profByPhoneKey[$k55])) {
                    $profByPhoneKey[$k55] = ['id' => $pr['id'], 'name' => $pr['name']];
                }
            }
        }
        $prof = $profByPhoneKey[dispatch_member_key($senderDigits)] ?? null;

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

// ====================================================================
// COBRANÇA MANUAL (follow-up) — só faz sentido se a demanda já foi captada em grupo.
// Seleção de profissionais + geração de mensagem por IA + envio espaçado (anti-bloqueio).
// ====================================================================
$followupCandidates = [];
$demandWasDispatched = false;
try {
    $demandWasDispatched = function_exists('demand_followup_demand_was_dispatched')
        ? demand_followup_demand_was_dispatched(db(), (int)$d['id'])
        : false;
    if ($demandWasDispatched && function_exists('demand_followup_candidates')) {
        $followupCandidates = demand_followup_candidates(db(), (int)$d['id']);
    }
} catch (Throwable $e) {
    $followupCandidates = [];
}

if ($demandWasDispatched) {
    echo '<section class="card col12">';
    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">';
    echo '<div>';
    echo '<div style="font-weight:900;margin-bottom:4px">Cobrança de profissionais (privado)</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));line-height:1.5">Selecione profissionais para cobrar no privado uma resposta sobre esta demanda. As mensagens são geradas por IA (uma por profissional) e enviadas em lotes espaçados para reduzir risco de bloqueio.</div>';
    echo '</div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<button type="button" class="btn btnPrimary" onclick="openFollowupModal()">📣 Fazer cobrança manual</button>';
    echo '<button type="button" class="btn" onclick="openReferralModal()">➕ Registrar indicação</button>';
    echo '</div>';
    echo '</div>';
    echo '</section>';

    // Modal de registro de indicação (profissional indicado por outro).
    echo '<div id="modalReferral" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:20px">';
    echo '<div style="background:hsl(var(--card));border-radius:12px;padding:24px;max-width:520px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3)">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    echo '<div style="font-size:18px;font-weight:900">Registrar indicação — Demanda #' . (int)$d['id'] . '</div>';
    echo '<button type="button" onclick="closeReferralModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:hsl(var(--muted-foreground))">×</button>';
    echo '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground));margin-bottom:14px">Lance o contato do profissional indicado por quem foi cobrado, para dar continuidade no fluxo.</div>';
    echo '<div style="display:grid;gap:10px">';
    echo '<label style="font-size:13px;font-weight:600">Nome do indicado<input type="text" id="refName" style="width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></label>';
    echo '<label style="font-size:13px;font-weight:600">Telefone do indicado<input type="text" id="refPhone" placeholder="Ex: 5511999999999" style="width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></label>';
    echo '<label style="font-size:13px;font-weight:600">Especialidade<input type="text" id="refSpecialty" value="' . h((string)($d['specialty'] ?? '')) . '" style="width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></label>';
    echo '<label style="font-size:13px;font-weight:600">Quem indicou (telefone, opcional)<input type="text" id="refReferrer" style="width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></label>';
    echo '<label style="font-size:13px;font-weight:600">Observação<textarea id="refNote" rows="2" style="width:100%;padding:8px;border:1px solid hsl(var(--border));border-radius:6px"></textarea></label>';
    echo '</div>';
    echo '<div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end">';
    echo '<button type="button" class="btn" onclick="closeReferralModal()">Cancelar</button>';
    echo '<button type="button" class="btn btnPrimary" id="refSubmitBtn" onclick="submitReferral(' . (int)$d['id'] . ')">Salvar indicação</button>';
    echo '</div>';
    echo '</div>';
    echo '</div>';

    echo '<script>';
    echo 'function openReferralModal(){document.getElementById("modalReferral").style.display="flex";}';
    echo 'function closeReferralModal(){document.getElementById("modalReferral").style.display="none";}';
    echo 'function submitReferral(demandId){';
    echo '  var name=document.getElementById("refName").value.trim();var phone=document.getElementById("refPhone").value.trim();';
    echo '  if(name===""&&phone===""){alert("Informe ao menos o nome ou o telefone do indicado.");return;}';
    echo '  var btn=document.getElementById("refSubmitBtn");btn.disabled=true;btn.textContent="Salvando...";';
    echo '  var fd=new FormData();fd.append("demand_id",demandId);fd.append("referred_name",name);fd.append("referred_phone",phone);';
    echo '  fd.append("referred_specialty",document.getElementById("refSpecialty").value.trim());';
    echo '  fd.append("referrer_phone",document.getElementById("refReferrer").value.trim());';
    echo '  fd.append("note",document.getElementById("refNote").value.trim());';
    echo '  fetch("/demand_referral_create_post.php",{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}})';
    echo '  .then(function(r){return r.json();})';
    echo '  .then(function(data){ if(data&&data.success){ alert("✅ "+(data.message||"Indicação registrada.")); closeReferralModal(); } else { alert("❌ "+((data&&data.error)||"Falha.")); btn.disabled=false;btn.textContent="Salvar indicação"; } })';
    echo '  .catch(function(){ alert("❌ Erro de conexão."); btn.disabled=false;btn.textContent="Salvar indicação"; });';
    echo '}';
    echo '</script>';

    // Modal de cobrança
    echo '<div id="modalFollowup" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:20px">';
    echo '<div style="background:hsl(var(--card));border-radius:12px;padding:24px;max-width:640px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">';
    echo '<div style="font-size:18px;font-weight:900">Cobrar profissionais — Demanda #' . (int)$d['id'] . '</div>';
    echo '<button type="button" onclick="closeFollowupModal()" style="background:none;border:none;font-size:24px;cursor:pointer;color:hsl(var(--muted-foreground))">×</button>';
    echo '</div>';

    echo '<div style="padding:12px;border-radius:8px;background:hsla(var(--destructive)/.08);border:1px solid hsl(var(--destructive));margin-bottom:14px">';
    echo '<div style="font-size:13px;color:hsl(var(--destructive));font-weight:700">⚠ Aviso de risco</div>';
    echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));line-height:1.5;margin-top:4px">O envio de cobrança no privado é feito por sua conta e risco. Mesmo com envio espaçado, há risco de o número de WhatsApp ser bloqueado pelo excesso de mensagens. Selecione apenas quem realmente precisa.</div>';
    echo '</div>';

    $selectable = array_values(array_filter($followupCandidates, fn($c) => !$c['already_charged']));
    if (count($selectable) === 0) {
        echo '<div style="padding:24px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum profissional disponível para cobrança (todos já foram cobrados ou sem número válido).</div>';
    } else {
        echo '<div style="font-size:13px;font-weight:600;margin-bottom:8px">Profissionais (' . count($selectable) . ')</div>';
        echo '<div style="display:flex;gap:8px;margin-bottom:8px">';
        echo '<button type="button" class="btn" style="font-size:12px;padding:4px 10px" onclick="followupToggleAll(true)">Selecionar todos</button>';
        echo '<button type="button" class="btn" style="font-size:12px;padding:4px 10px" onclick="followupToggleAll(false)">Limpar</button>';
        echo '</div>';
        echo '<div style="max-height:280px;overflow:auto;border:1px solid hsl(var(--border));border-radius:8px">';
        foreach ($selectable as $c) {
            $reactedBadge = $c['reacted'] ? '<span class="badge badgeSuccess" style="font-size:10px;margin-left:6px">já reagiu</span>' : '';
            echo '<label style="display:flex;align-items:center;gap:10px;padding:8px 12px;border-bottom:1px solid hsl(var(--border));cursor:pointer">';
            echo '<input type="checkbox" class="followupChk" value="' . h($c['phone']) . '" ' . ($c['reacted'] ? '' : 'checked') . '>';
            echo '<span style="flex:1"><span style="font-weight:600">' . h($c['name'] !== '' ? $c['name'] : $c['phone']) . '</span> '
                . '<span style="font-size:12px;color:hsl(var(--muted-foreground))">' . h($c['phone']) . '</span>' . $reactedBadge . '</span>';
            echo '</label>';
        }
        echo '</div>';

        echo '<label style="display:flex;align-items:flex-start;gap:8px;margin-top:14px;font-size:13px;cursor:pointer">';
        echo '<input type="checkbox" id="followupAck" style="margin-top:2px">';
        echo '<span>Confirmo que li o aviso e assumo o risco de bloqueio do número ao enviar estas cobranças.</span>';
        echo '</label>';

        echo '<div style="display:flex;gap:10px;margin-top:16px;justify-content:flex-end">';
        echo '<button type="button" class="btn" onclick="closeFollowupModal()">Cancelar</button>';
        echo '<button type="button" class="btn btnPrimary" id="followupSubmitBtn" onclick="submitFollowup(' . (int)$d['id'] . ')">🤖 Gerar com IA e cobrar</button>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';

    echo '<script>';
    echo 'function openFollowupModal(){document.getElementById("modalFollowup").style.display="flex";}';
    echo 'function closeFollowupModal(){document.getElementById("modalFollowup").style.display="none";}';
    echo 'function followupToggleAll(v){document.querySelectorAll(".followupChk").forEach(function(c){c.checked=v;});}';
    echo 'function submitFollowup(demandId){';
    echo '  var phones=[];document.querySelectorAll(".followupChk:checked").forEach(function(c){phones.push(c.value);});';
    echo '  if(phones.length===0){alert("Selecione ao menos um profissional.");return;}';
    echo '  if(!document.getElementById("followupAck").checked){alert("Confirme o aviso de risco para continuar.");return;}';
    // Confirmação tripla (pedido explícito da reunião).
    echo '  if(!confirm("Confirmar cobrança de "+phones.length+" profissional(is) desta demanda?"))return;';
    echo '  if(!confirm("Tem certeza? As mensagens serão enviadas no PRIVADO de cada profissional."))return;';
    echo '  if(!confirm("Última confirmação: isto pode causar BLOQUEIO do número de WhatsApp. Deseja mesmo prosseguir?"))return;';
    echo '  var btn=document.getElementById("followupSubmitBtn");btn.disabled=true;btn.textContent="Enfileirando...";';
    echo '  var fd=new FormData();fd.append("demand_id",demandId);fd.append("acknowledge_risk","1");';
    echo '  phones.forEach(function(p){fd.append("phones[]",p);});';
    echo '  fetch("/demand_followup_enqueue_post.php",{method:"POST",body:fd,headers:{"X-Requested-With":"XMLHttpRequest"}})';
    echo '  .then(function(r){return r.json();})';
    echo '  .then(function(data){ if(data&&data.success){ alert("✅ "+(data.message||"Cobrança enfileirada.")); closeFollowupModal(); } else { alert("❌ "+((data&&data.error)||"Falha ao enfileirar.")); btn.disabled=false; btn.textContent="🤖 Gerar com IA e cobrar"; } })';
    echo '  .catch(function(){ alert("❌ Erro de conexão."); btn.disabled=false; btn.textContent="🤖 Gerar com IA e cobrar"; });';
    echo '}';
    echo '</script>';
}

echo '</div>';

// Modal de seleção de sub-solicitação (quando e-mail tem múltiplas)
if (count($subRequests) > 1) {
    echo '<div id="modalSubRequests" style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.5);align-items:center;justify-content:center;padding:20px">';
    echo '<div style="background:hsl(var(--card));border-radius:12px;padding:24px;max-width:600px;width:100%;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.3)">';
    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">';
    echo '<div style="font-size:18px;font-weight:900">Selecionar Solicitação para Captação</div>';
    echo '<button type="button" onclick="document.getElementById(\'modalSubRequests\').style.display=\'none\'" style="background:none;border:none;font-size:24px;cursor:pointer;color:hsl(var(--muted-foreground))">×</button>';
    echo '</div>';
    echo '<div style="color:hsl(var(--muted-foreground));font-size:14px;margin-bottom:20px">Este e-mail contém <strong>' . count($subRequests) . ' solicitações</strong> diferentes. Selecione qual deseja captar:</div>';
    echo '<div style="display:grid;gap:12px">';
    foreach ($subRequests as $sr) {
        $srStatus = (string)$sr['status'];
        $srDisabled = ($srStatus === 'em_captacao' || $srStatus === 'concluido');
        $srBadge = '';
        if ($srStatus === 'em_captacao') {
            $srBadge = '<span class="badge badgeWarn" style="font-size:11px;margin-left:8px">Em Captação</span>';
        } elseif ($srStatus === 'concluido') {
            $srBadge = '<span class="badge badgeSuccess" style="font-size:11px;margin-left:8px">Concluído</span>';
        } elseif ($srStatus === 'cancelado') {
            $srBadge = '<span class="badge badgeDanger" style="font-size:11px;margin-left:8px">Cancelado</span>';
        }
        echo '<div style="border:1px solid hsl(var(--border));border-radius:10px;padding:16px;' . ($srDisabled ? 'opacity:.5;' : '') . '">';
        echo '<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">';
        echo '<div>';
        echo '<div style="font-weight:700;font-size:15px">' . h((string)$sr['specialty']) . $srBadge . '</div>';
        if (trim((string)($sr['description'] ?? '')) !== '') {
            echo '<div style="margin-top:6px;font-size:13px;color:hsl(var(--muted-foreground));line-height:1.5">' . h(mb_strimwidth((string)$sr['description'], 0, 150, '...')) . '</div>';
        }
        if ($sr['procedure_value'] !== null && (float)$sr['procedure_value'] > 0) {
            echo '<div style="margin-top:4px;font-size:13px;color:hsl(var(--muted-foreground))"><strong>Valor:</strong> R$ ' . number_format((float)$sr['procedure_value'], 2, ',', '.') . '</div>';
        }
        echo '</div>';
        echo '<div>';
        if (!$srDisabled) {
            echo '<form method="post" action="/demands_dispatch_whatsapp_post.php" class="dispatch-form" style="display:inline">';
            echo '<input type="hidden" name="id" value="' . (int)$d['id'] . '">';
            echo '<input type="hidden" name="sub_request_id" value="' . (int)$sr['id'] . '">';
            echo '<button class="btn btnPrimary" type="submit" style="white-space:nowrap">Captar esta</button>';
            echo '</form>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
    echo '</div>';
    echo '</div>';
}

// ============================================================================
// POPUP DE PROGRESSO DO DISPARO (background, não-bloqueante)
// ----------------------------------------------------------------------------
// Intercepta o submit dos formulários de captação (.dispatch-form), dispara via
// fetch (resposta imediata com run_id) e acompanha o progresso por polling em
// demands_dispatch_status.php. O usuário pode minimizar/fechar o popup e
// continuar usando o sistema enquanto a captação roda em segundo plano.
// ============================================================================
?>
<script>
(function(){
  // O acompanhamento do progresso é GLOBAL (app/dispatch_tracker_widget.php):
  // continua aparecendo ao recarregar ou trocar de página e some sozinho ao terminar.
  var cardTitle = <?= json_encode('#' . (int)$d['id'] . ' — ' . (string)$d['title'], JSON_UNESCAPED_UNICODE) ?>;

  function showError(message){
    alert(message || 'Falha ao iniciar a captação.');
  }

  document.querySelectorAll('form.dispatch-form').forEach(function(form){
    form.addEventListener('submit', function(ev){
      ev.preventDefault();

      // Fecha o modal de sub-solicitações, se estiver aberto.
      var modal = document.getElementById('modalSubRequests');
      if (modal) modal.style.display = 'none';

      // Desabilita o botão para evitar duplo clique.
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; setTimeout(function(){ btn.disabled = false; }, 4000); }

      fetch(form.action, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      })
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (data && data.ok && data.run_id) {
          if (window.mlDispatchTracker) window.mlDispatchTracker.start(data.run_id, cardTitle);
        } else {
          showError(data && data.error);
        }
      })
      .catch(function(){
        // Fallback: se o fetch falhar (rede), envia o form normalmente.
        form.submit();
      });
    });
  });

  // Ao terminar a captação DESTE card, atualiza a tela (status e logs de disparo).
  document.addEventListener('ml-dispatch-done', function(ev){
    var run = ev.detail || {};
    if (String(run.demand_id) === <?= json_encode((string)(int)$d['id']) ?> && run.success) {
      setTimeout(function(){ window.location.reload(); }, 5000);
    }
  });
})();
</script>
<?php

view_footer();
