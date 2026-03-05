<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

$groupId = trim($_GET['id'] ?? '');

if (empty($groupId)) {
    flash_set('error', 'ID do grupo não informado.');
    header('Location: /chat_web.php');
    exit;
}

$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

$groupData = null;
$participants = [];

if (!empty($baseUrl) && !empty($apiKey) && !empty($instanceName)) {
    try {
        // Buscar dados do grupo
        $ch = curl_init($baseUrl . '/group/participants/' . urlencode($instanceName) . '?groupJid=' . urlencode($groupId));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['participants'])) {
                $participants = $data['participants'];
            }
        }
    } catch (Exception $e) {
        // Erro ao buscar participantes
    }
}

// Buscar profissionais disponíveis para adicionar
$professionals = db()->query(
    "SELECT u.id, u.name, u.phone
    FROM users u
    LEFT JOIN user_roles ur ON ur.user_id = u.id
    LEFT JOIN roles r ON r.id = ur.role_id
    WHERE u.status = 'active' AND r.slug = 'profissional' AND u.phone IS NOT NULL
    ORDER BY u.name ASC"
)->fetchAll();

view_header('Gerenciar Grupo');

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:center;justify-content:space-between">';
echo '<h2>Gerenciar Grupo</h2>';
echo '<a href="/chat_web.php?type=groups&chat=' . urlencode($groupId) . '" class="btn">Voltar ao Chat</a>';
echo '</div>';
echo '</section>';

echo '<section class="card col6">';
echo '<h3>Participantes (' . count($participants) . ')</h3>';
echo '<div style="max-height:500px;overflow-y:auto">';
if (empty($participants)) {
    echo '<p style="color:hsl(var(--muted-foreground))">Nenhum participante encontrado.</p>';
} else {
    foreach ($participants as $participant) {
        $participantId = $participant['id'] ?? '';
        $isAdmin = isset($participant['admin']) && $participant['admin'] === 'admin';
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;border-bottom:1px solid hsl(var(--border))">';
        echo '<div>';
        echo '<div style="font-weight:600">' . h($participantId) . '</div>';
        if ($isAdmin) {
            echo '<div style="font-size:12px;color:hsl(var(--primary))">Administrador</div>';
        }
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        if (!$isAdmin) {
            echo '<form method="post" action="/chat_group_promote_member.php" style="display:inline">';
            echo '<input type="hidden" name="group_id" value="' . h($groupId) . '">';
            echo '<input type="hidden" name="participant_id" value="' . h($participantId) . '">';
            echo '<button type="submit" class="btn" style="font-size:12px;padding:6px 12px">Promover</button>';
            echo '</form>';
        } else {
            echo '<form method="post" action="/chat_group_demote_member.php" style="display:inline">';
            echo '<input type="hidden" name="group_id" value="' . h($groupId) . '">';
            echo '<input type="hidden" name="participant_id" value="' . h($participantId) . '">';
            echo '<button type="submit" class="btn" style="font-size:12px;padding:6px 12px">Rebaixar</button>';
            echo '</form>';
        }
        echo '<form method="post" action="/chat_group_remove_member.php" style="display:inline" onsubmit="return confirm(\'Remover este participante?\')">';
        echo '<input type="hidden" name="group_id" value="' . h($groupId) . '">';
        echo '<input type="hidden" name="participant_id" value="' . h($participantId) . '">';
        echo '<button type="submit" class="btn" style="font-size:12px;padding:6px 12px;background:hsl(var(--destructive));color:white">Remover</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
}
echo '</div>';
echo '</section>';

echo '<section class="card col6">';
echo '<h3>Adicionar Participantes</h3>';
echo '<form method="post" action="/chat_group_add_members.php">';
echo '<input type="hidden" name="group_id" value="' . h($groupId) . '">';
echo '<div style="max-height:400px;overflow-y:auto;border:1px solid hsl(var(--border));border-radius:8px;padding:12px;margin-bottom:12px">';
foreach ($professionals as $prof) {
    $phone = preg_replace('/\D/', '', $prof['phone'] ?? '');
    if (empty($phone)) continue;
    
    echo '<label style="display:flex;align-items:center;gap:8px;padding:8px;border-bottom:1px solid hsl(var(--border))">';
    echo '<input type="checkbox" name="participants[]" value="' . h($phone) . '">';
    echo '<div>';
    echo '<div style="font-weight:600">' . h($prof['name']) . '</div>';
    echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">' . h($prof['phone']) . '</div>';
    echo '</div>';
    echo '</label>';
}
echo '</div>';
echo '<button type="submit" class="btn btnPrimary">Adicionar Selecionados</button>';
echo '</form>';
echo '</section>';

echo '</div>';

view_footer();
