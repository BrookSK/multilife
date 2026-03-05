<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('whatsapp_groups.manage');

$groupId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($groupId === 0) {
    flash_set('error', 'Grupo não especificado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$stmt = db()->prepare('SELECT * FROM whatsapp_groups WHERE id = :id');
$stmt->execute(['id' => $groupId]);
$group = $stmt->fetch();

if (!$group) {
    flash_set('error', 'Grupo não encontrado.');
    header('Location: /whatsapp_groups_list.php');
    exit;
}

$groupJid = (string)$group['evolution_group_jid'];

// Buscar membros atuais do grupo via Evolution API
$members = [];
try {
    $api = new EvolutionApiV1();
    $response = $api->fetchGroupParticipants($groupJid);
    $members = $response['json']['participants'] ?? [];
} catch (Exception $e) {
    // Ignorar erro
}

// Buscar profissionais disponíveis
$profStmt = db()->prepare(
    "SELECT u.id, u.name, u.email, u.phone
    FROM users u
    INNER JOIN user_roles ur ON ur.user_id = u.id
    INNER JOIN roles r ON r.id = ur.role_id
    WHERE u.status = 'active' AND r.slug = 'profissional'
    ORDER BY u.name ASC"
);
$profStmt->execute();
$professionals = $profStmt->fetchAll();

view_header('Gerenciar Membros - ' . h((string)$group['name']));

echo '<div class="grid">';

echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900">Gerenciar Membros</div>';
echo '<div style="margin-top:6px;color:hsl(var(--muted-foreground));font-size:14px;line-height:1.6">Grupo: <strong>' . h((string)$group['name']) . '</strong></div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/whatsapp_groups_list.php">Voltar</a>';
echo '</div>';
echo '</div>';
echo '</section>';

// Seção: Adicionar Membros
echo '<section class="card col12">';
echo '<div style="font-weight:700;font-size:16px;margin-bottom:12px">Adicionar Membros</div>';
echo '<form method="post" action="/whatsapp_groups_add_members_post.php" style="display:grid;gap:12px">';
echo '<input type="hidden" name="group_id" value="' . $groupId . '">';
echo '<label>Selecione profissionais para adicionar<select name="user_ids[]" multiple required size="8">';
foreach ($professionals as $prof) {
    $phone = preg_replace('/\D+/', '', (string)($prof['phone'] ?? ''));
    if (empty($phone)) continue;
    
    // Verificar se já está no grupo
    $isInGroup = false;
    foreach ($members as $member) {
        $memberPhone = preg_replace('/\D+/', '', (string)($member['id'] ?? ''));
        if ($memberPhone === $phone) {
            $isInGroup = true;
            break;
        }
    }
    
    if ($isInGroup) continue;
    
    $label = (string)$prof['name'] . ' — ' . (string)$prof['phone'];
    echo '<option value="' . (int)$prof['id'] . '">' . h($label) . '</option>';
}
echo '</select></label>';
echo '<div style="display:flex;justify-content:flex-end">';
echo '<button type="submit" class="btn btnPrimary">Adicionar Selecionados</button>';
echo '</div>';
echo '</form>';
echo '</section>';

// Seção: Membros Atuais
echo '<section class="card col12">';
echo '<div style="font-weight:700;font-size:16px;margin-bottom:12px">Membros Atuais (' . count($members) . ')</div>';

if (empty($members)) {
    echo '<div style="padding:40px;text-align:center;color:hsl(var(--muted-foreground))">Nenhum membro no grupo</div>';
} else {
    echo '<div style="display:grid;gap:8px">';
    foreach ($members as $member) {
        $memberId = (string)($member['id'] ?? '');
        $memberPhone = preg_replace('/\D+/', '', $memberId);
        $isAdmin = (bool)($member['admin'] ?? false);
        
        // Tentar encontrar usuário correspondente
        $userName = 'Desconhecido';
        foreach ($professionals as $prof) {
            $profPhone = preg_replace('/\D+/', '', (string)($prof['phone'] ?? ''));
            if ($profPhone === $memberPhone) {
                $userName = (string)$prof['name'];
                break;
            }
        }
        
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div>';
        echo '<strong>' . h($userName) . '</strong>';
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:2px">' . h($memberPhone) . '</div>';
        if ($isAdmin) {
            echo '<span style="margin-left:8px;padding:2px 6px;background:hsl(var(--primary));color:white;border-radius:4px;font-size:10px">ADMIN</span>';
        }
        echo '</div>';
        echo '<div style="display:flex;gap:8px">';
        if (!$isAdmin) {
            echo '<form method="post" action="/whatsapp_groups_promote_member_post.php" style="display:inline">';
            echo '<input type="hidden" name="group_id" value="' . $groupId . '">';
            echo '<input type="hidden" name="member_id" value="' . h($memberId) . '">';
            echo '<button type="submit" class="btn" style="font-size:12px;padding:6px 10px">Promover Admin</button>';
            echo '</form>';
        } else {
            echo '<form method="post" action="/whatsapp_groups_demote_member_post.php" style="display:inline">';
            echo '<input type="hidden" name="group_id" value="' . $groupId . '">';
            echo '<input type="hidden" name="member_id" value="' . h($memberId) . '">';
            echo '<button type="submit" class="btn" style="font-size:12px;padding:6px 10px">Remover Admin</button>';
            echo '</form>';
        }
        echo '<form method="post" action="/whatsapp_groups_remove_member_post.php" style="display:inline" onsubmit="return confirm(\'Remover este membro?\')">';
        echo '<input type="hidden" name="group_id" value="' . $groupId . '">';
        echo '<input type="hidden" name="member_id" value="' . h($memberId) . '">';
        echo '<button type="submit" class="btn" style="font-size:12px;padding:6px 10px;color:hsl(var(--destructive))">Remover</button>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

echo '</section>';

echo '</div>';

view_footer();
