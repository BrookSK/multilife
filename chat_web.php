<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$status = isset($_GET['status']) ? (string)$_GET['status'] : 'open';
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$selectedId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$prefDemandId = isset($_GET['demand_id']) ? (int)$_GET['demand_id'] : 0;

if (!in_array($status, ['open', 'closed'], true)) {
    $status = 'open';
}

// conversations list + unread count per conversation (per current user)
$sql = "SELECT
            c.id,
            c.external_phone,
            c.contact_kind,
            c.contact_ref_id,
            c.status,
            c.assigned_user_id,
            c.last_message_at,
            c.last_message_preview,
            c.created_at,
            u.name AS assigned_user_name,
            (SELECT MAX(m.id) FROM chat_messages m WHERE m.conversation_id = c.id) AS last_message_id,
            (SELECT r.last_read_message_id FROM chat_conversation_reads r WHERE r.conversation_id = c.id AND r.user_id = :uid) AS last_read_message_id
        FROM chat_conversations c
        LEFT JOIN users u ON u.id = c.assigned_user_id
        WHERE c.status = :status";

$params = [
    'status' => $status,
    'uid' => auth_user_id(),
];

if ($q !== '') {
    $sql .= ' AND (c.external_phone LIKE :q OR c.last_message_preview LIKE :q)';
    $params['q'] = '%' . $q . '%';
}

$sql .= ' ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$conversations = $stmt->fetchAll();

if ($selectedId === 0 && count($conversations) > 0) {
    $selectedId = (int)$conversations[0]['id'];
}

$selected = null;
$messages = [];
$users = [];
$contact = null;

if ($selectedId > 0) {
    $stmt = db()->prepare(
        'SELECT c.*, u.name AS assigned_user_name\n'
        . 'FROM chat_conversations c\n'
        . 'LEFT JOIN users u ON u.id = c.assigned_user_id\n'
        . 'WHERE c.id = :id'
    );
    $stmt->execute(['id' => $selectedId]);
    $selected = $stmt->fetch();

    if ($selected) {
        $stmt = db()->prepare(
            'SELECT m.id, m.direction, m.body, m.created_at, u.name AS user_name\n'
            . 'FROM chat_messages m\n'
            . 'LEFT JOIN users u ON u.id = m.sent_by_user_id\n'
            . 'WHERE m.conversation_id = :cid\n'
            . 'ORDER BY m.id ASC'
        );
        $stmt->execute(['cid' => $selectedId]);
        $messages = $stmt->fetchAll();

        $users = db()->query("SELECT id, name, email FROM users WHERE status = 'active' ORDER BY name ASC")->fetchAll();

        // Try to resolve contact info by phone
        $phone = (string)$selected['external_phone'];
        $phoneDigits = preg_replace('/\D+/', '', $phone);

        // Patient by whatsapp / phones
        $stmt = db()->prepare(
            "SELECT id, full_name, email, whatsapp, phone_primary, phone_secondary\n"
            . "FROM patients\n"
            . "WHERE REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''),' ',''),'-',''),'(',''),')','') LIKE :p\n"
            . "   OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_primary,''),' ',''),'-',''),'(',''),')','') LIKE :p\n"
            . "   OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone_secondary,''),' ',''),'-',''),'(',''),')','') LIKE :p\n"
            . "LIMIT 1"
        );
        $stmt->execute(['p' => '%' . $phoneDigits . '%']);
        $p = $stmt->fetch();
        if ($p) {
            $contact = [
                'kind' => 'patient',
                'id' => (int)$p['id'],
                'name' => (string)$p['full_name'],
                'email' => (string)($p['email'] ?? ''),
            ];
        } else {
            // Professional by users.phone (role profissional)
            $stmt = db()->prepare(
                "SELECT u.id, u.name, u.email\n"
                . "FROM users u\n"
                . "INNER JOIN user_roles ur ON ur.user_id = u.id\n"
                . "INNER JOIN roles r ON r.id = ur.role_id\n"
                . "WHERE u.status='active' AND r.slug='profissional'\n"
                . "  AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(u.phone,''),' ',''),'-',''),'(',''),')','') LIKE :p\n"
                . "ORDER BY u.id DESC\n"
                . "LIMIT 1"
            );
            $stmt->execute(['p' => '%' . $phoneDigits . '%']);
            $pu = $stmt->fetch();
            if ($pu) {
                $contact = [
                    'kind' => 'professional_user',
                    'id' => (int)$pu['id'],
                    'name' => (string)$pu['name'],
                    'email' => (string)($pu['email'] ?? ''),
                ];
            }
        }

        // Mark read for current user
        $lastMsgId = 0;
        if (count($messages) > 0) {
            $lastMsgId = (int)$messages[count($messages) - 1]['id'];
        }
        if ($lastMsgId > 0) {
            $stmt = db()->prepare(
                'INSERT INTO chat_conversation_reads (conversation_id, user_id, last_read_message_id, last_read_at)\n'
                . 'VALUES (:cid, :uid, :mid, NOW())\n'
                . 'ON DUPLICATE KEY UPDATE last_read_message_id = VALUES(last_read_message_id), last_read_at = VALUES(last_read_at)'
            );
            $stmt->execute(['cid' => $selectedId, 'uid' => auth_user_id(), 'mid' => $lastMsgId]);
        }
    }
}

view_header('Comunicação');

$activeTabCls = $status === 'open' ? 'btn btnPrimary' : 'btn';
$histTabCls = $status === 'closed' ? 'btn btnPrimary' : 'btn';

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Comunicação</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.5">Chat estilo WhatsApp Web. Conversas pvt sempre iniciadas externamente.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/dashboard.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

echo '<section class="card col12">';
// WhatsApp Web-ish layout (3 columns)
echo '<div style="display:grid;grid-template-columns:360px 1fr 360px;gap:14px;align-items:stretch">';

// Left: conversation list
echo '<div style="min-height:640px;display:flex;flex-direction:column">';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px">';
echo '<a class="' . $activeTabCls . '" href="/chat_web.php?status=open">Ativas</a>';
echo '<a class="' . $histTabCls . '" href="/chat_web.php?status=closed">Histórico</a>';
echo '</div>';

echo '<form method="get" action="/chat_web.php" style="display:flex;gap:10px;margin-bottom:10px">';
echo '<input type="hidden" name="status" value="' . h($status) . '">';
echo '<input name="q" value="' . h($q) . '" placeholder="Buscar por telefone ou mensagem">';
echo '<button class="btn" type="submit">Buscar</button>';
echo '</form>';

echo '<div style="flex:1;overflow:auto;border:1px solid hsl(var(--border));border-radius:14px;background:hsl(var(--muted)/.15)">';
if (count($conversations) === 0) {
    echo '<div style="padding:14px;color:hsl(var(--muted-foreground));font-size:13px">Nenhuma conversa.</div>';
}
foreach ($conversations as $c) {
    $cid = (int)$c['id'];
    $isSel = $selectedId === $cid;
    $lastAt = $c['last_message_at'] ? (string)$c['last_message_at'] : (string)$c['created_at'];
    $preview = $c['last_message_preview'] ? (string)$c['last_message_preview'] : '';

    $lastMsgId = $c['last_message_id'] !== null ? (int)$c['last_message_id'] : 0;
    $lastRead = $c['last_read_message_id'] !== null ? (int)$c['last_read_message_id'] : 0;
    $unread = ($lastMsgId > 0 && $lastMsgId > $lastRead);

    $bg = $isSel ? 'hsl(var(--accent))' : 'transparent';

    echo '<a href="/chat_web.php?status=' . h($status) . '&q=' . urlencode($q) . '&id=' . $cid . '" style="display:block;padding:12px 12px;border-bottom:1px solid hsl(var(--border));background:' . $bg . ';text-decoration:none">';
    echo '<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">';
    echo '<div style="min-width:0">';
    echo '<div style="font-weight:900;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . h((string)$c['external_phone']) . '</div>';
    echo '<div style="margin-top:4px;color:hsl(var(--muted-foreground));font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . h(mb_strimwidth($preview, 0, 60, '...')) . '</div>';
    echo '</div>';
    echo '<div style="text-align:right;flex:0 0 auto">';
    echo '<div style="color:hsl(var(--muted-foreground));font-size:11px">' . h($lastAt) . '</div>';
    if ($unread) {
        echo '<div style="margin-top:6px"><span class="badge badgeDanger">Nova</span></div>';
    }
    echo '</div>';
    echo '</div>';
    echo '</a>';
}

echo '</div>';

echo '</div>';

// Middle: messages
echo '<div style="min-height:640px;display:flex;flex-direction:column">';
if (!$selected) {
    echo '<div style="padding:14px;color:hsl(var(--muted-foreground));font-size:13px">Selecione uma conversa.</div>';
} else {
    $assigned = $selected['assigned_user_name'] ? (string)$selected['assigned_user_name'] : '-';

    echo '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px">';
    echo '<div>';
    echo '<div style="font-weight:900">' . h((string)$selected['external_phone']) . '</div>';
    echo '<div style="margin-top:4px;color:hsl(var(--muted-foreground));font-size:12px">Status: ' . h((string)$selected['status']) . ' | Responsável: ' . h($assigned) . '</div>';
    echo '</div>';
    echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
    echo '<form method="post" action="/chat_finalize_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<button class="btn" type="submit">Finalizar</button>';
    echo '</form>';
    echo '<form method="post" action="/chat_reopen_post.php" style="display:inline">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<button class="btn" type="submit">Reabrir</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';

    echo '<div style="flex:1;overflow:auto;padding:10px;border-radius:14px;border:1px solid hsl(var(--border));background:hsl(var(--muted)/.25)">';
    foreach ($messages as $m) {
        $isOut = ((string)$m['direction'] === 'out');
        $align = $isOut ? 'flex-end' : 'flex-start';
        $bg = $isOut ? 'hsl(var(--primary)/.18)' : 'hsl(var(--muted)/.55)';

        echo '<div style="display:flex;justify-content:' . $align . ';margin:10px 0">';
        echo '<div style="max-width:78%;padding:10px 12px;border-radius:14px;background:' . $bg . ';border:1px solid hsl(var(--border))">';
        echo '<div style="white-space:pre-wrap;font-size:14px;line-height:1.5">' . h((string)$m['body']) . '</div>';
        echo '<div style="margin-top:6px;font-size:12px;color:hsl(var(--muted-foreground))">';
        echo h((string)$m['created_at']);
        if ($isOut) {
            echo ' — ' . h((string)($m['user_name'] ?? ''));
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';

    echo '<form method="post" action="/chat_send_post.php" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<textarea name="body" rows="2" required placeholder="Digite sua resposta..." style="flex:1;min-width:260px"></textarea>';
    echo '<button class="btn btnPrimary" type="submit">Enviar</button>';
    echo '</form>';
}

echo '</div>';

// Right: contact panel
echo '<div style="min-height:640px;display:flex;flex-direction:column">';
if ($selected) {
    // Show demand context if available
    if ($prefDemandId > 0) {
        $stmt = db()->prepare('SELECT id, title, specialty, location_city, location_state, status FROM demands WHERE id = :id');
        $stmt->execute(['id' => $prefDemandId]);
        $demandCtx = $stmt->fetch();
        if ($demandCtx) {
            echo '<div style="padding:12px;border-radius:10px;background:hsl(var(--accent)/.15);border:1px solid hsl(var(--border));margin-bottom:14px">';
            echo '<div style="font-weight:900;font-size:13px;color:hsl(var(--primary));margin-bottom:8px">📋 Contexto da Demanda</div>';
            echo '<div style="font-size:12px;line-height:1.6">';
            echo '<strong>Card #' . (int)$demandCtx['id'] . ':</strong> ' . h((string)$demandCtx['title']) . '<br>';
            echo '<strong>Especialidade:</strong> ' . h((string)($demandCtx['specialty'] ?? '-')) . '<br>';
            echo '<strong>Local:</strong> ' . h((string)($demandCtx['location_city'] ?? '-')) . '/' . h((string)($demandCtx['location_state'] ?? '-')) . '<br>';
            echo '<strong>Status:</strong> ' . h((string)$demandCtx['status']);
            echo '</div>';
            echo '<div style="margin-top:10px"><a class="btn" href="/demands_view.php?id=' . (int)$demandCtx['id'] . '" style="font-size:12px;padding:6px 12px">Ver card completo</a></div>';
            echo '</div>';
        }
    }

    echo '<div style="font-weight:900;margin-bottom:10px">Contato</div>';
    echo '<div class="pill" style="display:block;margin-bottom:10px">Telefone: ' . h((string)$selected['external_phone']) . '</div>';

    if ($contact) {
        if ($contact['kind'] === 'patient') {
            echo '<div class="pill" style="display:block;margin-bottom:10px">Paciente: ' . h($contact['name']) . '</div>';
            if ($contact['email'] !== '') {
                echo '<div class="pill" style="display:block;margin-bottom:10px">E-mail: ' . h($contact['email']) . '</div>';
            }
            echo '<a class="btn" href="/patients_view.php?id=' . (int)$contact['id'] . '">Abrir paciente</a>';
        } else {
            echo '<div class="pill" style="display:block;margin-bottom:10px">Profissional: ' . h($contact['name']) . '</div>';
            if (isset($contact['status']) && $contact['status'] !== '') {
                echo '<div class="pill" style="display:block;margin-bottom:10px">Status: ' . h((string)$contact['status']) . '</div>';
            }
            echo '<a class="btn" href="/professional_applications_view.php?id=' . (int)$contact['id'] . '">Abrir candidatura</a>';
        }
    } else {
        echo '<div class="pill" style="display:block;margin-bottom:10px">Contato não identificado</div>';
    }

    echo '<div style="height:10px"></div>';
    $admUrl = '/chat_confirm_admission.php?chat_id=' . (int)$selected['id'];
    if ($prefDemandId > 0) {
        $admUrl .= '&demand_id=' . urlencode((string)$prefDemandId);
    }
    echo '<a class="btn btnPrimary" href="' . h($admUrl) . '">Confirmar Admissão</a>';

    echo '<div style="height:14px"></div>';
    echo '<div style="font-weight:900;margin:0 0 10px">Vincular manualmente</div>';
    echo '<form method="post" action="/chat_link_contact_post.php" style="display:grid;gap:10px">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<select name="kind" required>';
    echo '<option value="">Selecione</option>';
    echo '<option value="patient">Paciente</option>';
    echo '<option value="professional">Profissional (usuário)</option>';
    echo '</select>';
    echo '<input name="ref_id" placeholder="ID do registro" required>';
    echo '<button class="btn" type="submit">Vincular</button>';
    echo '</form>';

    echo '<div style="font-weight:900;margin:14px 0 10px">Transferir</div>';
    echo '<form method="post" action="/chat_transfer_post.php" style="display:grid;gap:10px">';
    echo '<input type="hidden" name="id" value="' . (int)$selected['id'] . '">';
    echo '<select name="to_user_id" required>';
    echo '<option value="">Selecione um usuário</option>';
    foreach ($users as $u) {
        echo '<option value="' . (int)$u['id'] . '">' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
    }
    echo '</select>';
    echo '<input name="note" placeholder="Observação (opcional)">';
    echo '<button class="btn" type="submit">Transferir</button>';
    echo '</form>';

    echo '<div style="height:12px"></div>';
    echo '<a class="btn" href="/chat_view.php?id=' . (int)$selected['id'] . '">Abrir tela clássica</a>';
}

echo '</div>';

echo '</div>';

echo '</section>';

echo '</div>';

view_footer();
