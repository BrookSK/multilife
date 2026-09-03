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
$_currentUserId = (int)($_SESSION['auth_user_id'] ?? 0);
$_userInst = whatsapp_get_user_instance($_currentUserId);
$instanceName = $_userInst ? $_userInst['instance_name'] : admin_setting_get('evolution.instance');

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

// ============================================================
// DECODIFICAR @lid → número real + nome (item: resolver Linked IDs)
// ============================================================
// 1) Montar mapa LID → número real a partir dos contatos da Evolution.
$lidToPhone = [];
if (!empty($baseUrl) && !empty($apiKey) && !empty($instanceName)) {
    try {
        $api = new EvolutionApiV1($baseUrl, $apiKey, $instanceName);
        $contactsRes = $api->findContacts();
        $contactsJson = $contactsRes['json'] ?? [];
        if (is_array($contactsJson)) {
            foreach ($contactsJson as $c) {
                // A Evolution v2 pode trazer 'id' (jid real) e 'lid' no mesmo contato,
                // ou o remoteJid com o número e um campo separado de lid.
                $cid = (string)($c['id'] ?? ($c['remoteJid'] ?? ''));
                $clid = (string)($c['lid'] ?? '');
                // Extrair número real do jid (formato NUMERO@s.whatsapp.net)
                if ($clid !== '' && $cid !== '' && strpos($cid, '@s.whatsapp.net') !== false) {
                    $realNum = preg_replace('/@.*/', '', $cid);
                    $lidNum = preg_replace('/@.*/', '', $clid);
                    if ($realNum !== '' && $lidNum !== '') {
                        $lidToPhone[$lidNum] = $realNum;
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // segue sem o mapa
    }
}

// 2) Mapa telefone → nome do profissional (para exibir quem é)
$phoneToName = [];
try {
    $usersRows = db()->query("SELECT name, phone FROM users WHERE phone IS NOT NULL AND phone <> ''")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($usersRows as $ur) {
        $digits = preg_replace('/\D+/', '', (string)$ur['phone']);
        if ($digits === '') continue;
        // Normalizar com e sem 55 para casar
        $variants = [$digits];
        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $variants[] = '55' . $digits;
        }
        if (strpos($digits, '55') === 0) {
            $variants[] = substr($digits, 2);
        }
        foreach ($variants as $v) {
            $phoneToName[$v] = (string)$ur['name'];
        }
    }
} catch (Throwable $e) {}

/**
 * Resolve um participante (id do WhatsApp) em ['phone' => ..., 'name' => ..., 'raw' => ...].
 */
function resolveParticipant(array $participant, array $lidToPhone, array $phoneToName): array
{
    $rawId = (string)($participant['id'] ?? '');
    // Alguns retornos trazem o número real em outro campo
    $altJid = (string)($participant['jid'] ?? ($participant['phoneNumber'] ?? ''));

    $phone = '';
    if ($rawId !== '') {
        $numPart = preg_replace('/@.*/', '', $rawId);
        if (strpos($rawId, '@lid') !== false) {
            // Tentar decodificar via mapa de contatos
            $phone = $lidToPhone[$numPart] ?? '';
        } else {
            // Já é número real (@s.whatsapp.net) ou numérico
            $phone = $numPart;
        }
    }
    // Fallback: campo alternativo
    if ($phone === '' && $altJid !== '' && strpos($altJid, '@lid') === false) {
        $phone = preg_replace('/@.*/', '', $altJid);
    }

    // Nome via cruzamento com users
    $name = '';
    if ($phone !== '') {
        $name = $phoneToName[$phone] ?? ($phoneToName[ltrim($phone, '55')] ?? ($phoneToName['55' . $phone] ?? ''));
    }

    // Formatar telefone para exibição
    $displayPhone = $phone;
    if ($phone !== '' && strpos($phone, '55') === 0 && strlen($phone) >= 12) {
        $rest = substr($phone, 2);
        $ddd = substr($rest, 0, 2);
        $num = substr($rest, 2);
        $displayPhone = '(' . $ddd . ') ' . $num;
    }

    return [
        'phone' => $phone,
        'display_phone' => $displayPhone !== '' ? $displayPhone : '(número não identificado)',
        'name' => $name !== '' ? $name : 'Não identificado',
        'raw' => $rawId,
    ];
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
        $resolved = resolveParticipant($participant, $lidToPhone, $phoneToName);
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:12px;border-bottom:1px solid hsl(var(--border))">';
        echo '<div>';
        echo '<div style="font-weight:600">' . h($resolved['name']) . '</div>';
        echo '<div style="font-size:13px;color:hsl(var(--muted-foreground))">' . h($resolved['display_phone']) . '</div>';
        // Mostrar o ID cru (lid) em fonte pequena para referência técnica
        echo '<div style="font-size:10px;color:hsl(var(--muted-foreground));opacity:.6">' . h($participantId) . '</div>';
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
