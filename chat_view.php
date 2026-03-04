<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT c.*, u.name AS assigned_user_name
     FROM chat_conversations c
     LEFT JOIN users u ON u.id = c.assigned_user_id
     WHERE c.id = :id'
);
$stmt->execute(['id' => $id]);
$c = $stmt->fetch();

if (!$c) {
    flash_set('error', 'Conversa não encontrada.');
    header('Location: /chat_list.php');
    exit;
}

$stmt = db()->prepare(
    'SELECT m.id, m.direction, m.body, m.created_at, u.name AS user_name
     FROM chat_messages m
     LEFT JOIN users u ON u.id = m.sent_by_user_id
     WHERE m.conversation_id = :cid
     ORDER BY m.id ASC'
);
$stmt->execute(['cid' => $id]);
$messages = $stmt->fetchAll();

$users = db()->query('SELECT id, name, email FROM users WHERE status = \'active\' ORDER BY name ASC')->fetchAll();

$assigned = $c['assigned_user_name'] ? (string)$c['assigned_user_name'] : '-';

view_header('Chat #' . (string)$c['id']);

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-bottom:6px">Conversa</div>';
echo '<div style="font-size:22px;font-weight:900">#' . (int)$c['id'] . ' — ' . h((string)$c['external_phone']) . '</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">';
echo '<strong>Status:</strong> ' . h((string)$c['status']) . ' &nbsp; <strong>Responsável:</strong> ' . h($assigned) . ' &nbsp; <strong>Tipo:</strong> ' . h((string)$c['contact_kind']);
echo '</div>';
echo '</div>';

echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_list.php">Voltar</a>';

echo '<form method="post" action="/chat_finalize_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$c['id'] . '">';
echo '<button class="btn" type="submit">Finalizar</button>';
echo '</form>';

echo '<form method="post" action="/chat_reopen_post.php" style="display:inline">';
echo '<input type="hidden" name="id" value="' . (int)$c['id'] . '">';
echo '<button class="btn" type="submit">Reabrir</button>';
echo '</form>';

echo '</div>';
echo '</div>';

echo '</section>';

// Mensagens + painel

echo '<section class="card col12">';
echo '<div class="grid">';

// Lista mensagens

echo '<div class="col12" style="grid-column:span 8">';
echo '<div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px">';
echo '<div style="font-weight:900">Mensagens</div>';
echo '<div class="pill">Conversa iniciada externamente</div>';
echo '</div>';

echo '<div style="height:420px;overflow:auto;padding:10px;border-radius:14px;border:1px solid hsl(var(--border));background:hsl(var(--muted)/.25)">';
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

// Enviar

echo '<form method="post" action="/chat_send_post.php" style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap">';
echo '<input type="hidden" name="id" value="' . (int)$c['id'] . '">';
echo '<textarea name="body" rows="2" required placeholder="Digite sua resposta..." style="flex:1;min-width:260px"></textarea>';
echo '<button class="btn btnPrimary" type="submit">Enviar</button>';
echo '</form>';

echo '</div>';

// Painel do contato / ações

echo '<div class="col12" style="grid-column:span 4">';
echo '<div style="font-weight:900;margin-bottom:10px">Contato</div>';
echo '<div class="pill" style="display:block;margin-bottom:10px">Telefone: ' . h((string)$c['external_phone']) . '</div>';
echo '<div class="pill" style="display:block;margin-bottom:10px">Tipo: ' . h((string)$c['contact_kind']) . '</div>';
if ($c['contact_ref_id'] !== null) {
    echo '<div class="pill" style="display:block;margin-bottom:10px">Ref ID: ' . h((string)$c['contact_ref_id']) . '</div>';
} else {
    echo '<div class="pill" style="display:block;margin-bottom:10px">Contato não identificado</div>';
}

echo '<div style="font-weight:900;margin:14px 0 10px">Transferir</div>';
echo '<form method="post" action="/chat_transfer_post.php" style="display:grid;gap:10px">';
echo '<input type="hidden" name="id" value="' . (int)$c['id'] . '">';
echo '<select name="to_user_id" required>'; 
echo '<option value="">Selecione um usuário</option>';
foreach ($users as $u) {
    echo '<option value="' . (int)$u['id'] . '">' . h((string)$u['name']) . ' — ' . h((string)$u['email']) . '</option>';
}
echo '</select>';
echo '<input name="note" placeholder="Observação (opcional)">';
echo '<button class="btn" type="submit">Transferir</button>';
echo '</form>';

echo '</div>';

echo '</div>';
echo '</section>';

echo '</div>';

view_footer();
