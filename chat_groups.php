<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();

// Buscar configurações da Evolution API
$baseUrl = admin_setting_get('evolution.base_url');
$apiKey = admin_setting_get('evolution.api_key');
$instanceName = admin_setting_get('evolution.instance');

$success = '';
$error = '';

// SEMPRE sincronizar grupos da Evolution API
if (!empty($baseUrl) && !empty($apiKey) && !empty($instanceName)) {
    try {
        // Criar tabelas se não existirem
        db()->exec("
            CREATE TABLE IF NOT EXISTS chat_groups (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_jid VARCHAR(100) NOT NULL UNIQUE,
                group_name VARCHAR(255) NOT NULL,
                group_description TEXT DEFAULT NULL,
                group_picture_url TEXT DEFAULT NULL,
                specialty VARCHAR(100) DEFAULT NULL,
                region VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE INDEX idx_group_jid (group_jid),
                INDEX idx_specialty (specialty),
                INDEX idx_region (region)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Buscar grupos da API
        $groupsUrl = $baseUrl . '/group/fetchAllGroups/' . urlencode($instanceName);
        $ch = curl_init($groupsUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['apikey: ' . $apiKey]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $groupsResponse = curl_exec($ch);
        $groupsHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($groupsHttpCode === 200 && $groupsResponse) {
            $groupsData = json_decode($groupsResponse, true);
            
            if (is_array($groupsData)) {
                foreach ($groupsData as $group) {
                    $groupJid = $group['id'] ?? '';
                    $groupName = $group['subject'] ?? 'Grupo sem nome';
                    $groupPic = $group['picture'] ?? null;
                    
                    if (!empty($groupJid)) {
                        $stmt = db()->prepare("
                            INSERT INTO chat_groups (group_jid, group_name, group_picture_url)
                            VALUES (?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                group_name = VALUES(group_name),
                                group_picture_url = VALUES(group_picture_url),
                                updated_at = CURRENT_TIMESTAMP
                        ");
                        $stmt->execute([$groupJid, $groupName, $groupPic]);
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao sincronizar grupos: " . $e->getMessage());
    }
}

// Processar ações de grupo
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_metadata') {
        // Atualizar especialidade e região do grupo
        $groupJid = trim($_POST['group_jid'] ?? '');
        $specialty = trim($_POST['specialty'] ?? '');
        $region = trim($_POST['region'] ?? '');
        
        if (!empty($groupJid)) {
            try {
                $stmt = db()->prepare("
                    UPDATE chat_groups 
                    SET specialty = ?, region = ?
                    WHERE group_jid = ?
                ");
                $stmt->execute([$specialty, $region, $groupJid]);
                $success = 'Metadados do grupo atualizados com sucesso!';
            } catch (Exception $e) {
                $error = 'Erro ao atualizar metadados: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'add_participant') {
        // Adicionar participante ao grupo
        $groupJid = trim($_POST['group_jid'] ?? '');
        $participantPhone = trim($_POST['participant_phone'] ?? '');
        
        if (!empty($groupJid) && !empty($participantPhone)) {
            // Formatar número
            $participantPhone = preg_replace('/[^0-9]/', '', $participantPhone);
            if (!str_contains($participantPhone, '@')) {
                $participantPhone .= '@s.whatsapp.net';
            }
            
            // Adicionar via API
            $url = $baseUrl . '/group/updateParticipant/' . urlencode($instanceName);
            $payload = json_encode([
                'groupJid' => $groupJid,
                'action' => 'add',
                'participants' => [$participantPhone]
            ]);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $success = 'Participante adicionado com sucesso!';
            } else {
                $error = 'Erro ao adicionar participante. HTTP Code: ' . $httpCode;
            }
        }
    } elseif ($action === 'remove_participant') {
        // Remover participante do grupo
        $groupJid = trim($_POST['group_jid'] ?? '');
        $participantJid = trim($_POST['participant_jid'] ?? '');
        
        if (!empty($groupJid) && !empty($participantJid)) {
            $url = $baseUrl . '/group/updateParticipant/' . urlencode($instanceName);
            $payload = json_encode([
                'groupJid' => $groupJid,
                'action' => 'remove',
                'participants' => [$participantJid]
            ]);
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $apiKey,
                'Content-Type: application/json'
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 || $httpCode === 201) {
                $success = 'Participante removido com sucesso!';
            } else {
                $error = 'Erro ao remover participante. HTTP Code: ' . $httpCode;
            }
        }
    }
}

// Buscar grupos do banco
$specialtyFilter = isset($_GET['specialty']) ? trim($_GET['specialty']) : '';
$regionFilter = isset($_GET['region']) ? trim($_GET['region']) : '';

$whereClauses = [];
$params = [];

if (!empty($specialtyFilter)) {
    $whereClauses[] = "specialty = ?";
    $params[] = $specialtyFilter;
}

if (!empty($regionFilter)) {
    $whereClauses[] = "region = ?";
    $params[] = $regionFilter;
}

$whereSQL = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

try {
    $stmt = db()->prepare("
        SELECT 
            group_jid,
            group_name,
            group_description,
            group_picture_url,
            specialty,
            region,
            updated_at
        FROM chat_groups
        $whereSQL
        ORDER BY group_name ASC
    ");
    $stmt->execute($params);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = 'Erro ao carregar grupos: ' . $e->getMessage();
    $groups = [];
}

// Buscar especialidades e regiões únicas para filtros
try {
    $specialties = db()->query("SELECT DISTINCT specialty FROM chat_groups WHERE specialty IS NOT NULL ORDER BY specialty")->fetchAll(PDO::FETCH_COLUMN);
    $regions = db()->query("SELECT DISTINCT region FROM chat_groups WHERE region IS NOT NULL ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $specialties = [];
    $regions = [];
}

view_header('Grupos WhatsApp');

echo '<div class="grid">';
echo '<section class="card col12">';
echo '<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">';
echo '<div>';
echo '<div style="font-size:22px;font-weight:900;margin-bottom:6px">Grupos WhatsApp</div>';
echo '<div style="color:hsl(var(--muted-foreground));font-size:14px">Gerencie os grupos criados e adicione participantes.</div>';
echo '</div>';
echo '<div style="display:flex;gap:10px;flex-wrap:wrap">';
echo '<a class="btn" href="/chat_web.php?type=grupos">← Voltar ao Chat</a>';
echo '</div>';
echo '</div>';

if (!empty($success)) {
    echo '<div style="background:#dcfce7;border:1px solid #86efac;padding:12px 16px;border-radius:8px;margin-top:16px;color:#166534">' . h($success) . '</div>';
}
if (!empty($error)) {
    echo '<div style="background:#fee2e2;border:1px solid #fca5a5;padding:12px 16px;border-radius:8px;margin-top:16px;color:#991b1b">' . h($error) . '</div>';
}

// Filtros
echo '<form method="get" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:16px">';
echo '<select name="specialty" style="padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px;min-width:180px">';
echo '<option value="">Todas as especialidades</option>';
foreach ($specialties as $spec) {
    $sel = $specialtyFilter === $spec ? ' selected' : '';
    echo '<option value="' . h($spec) . '"' . $sel . '>' . h($spec) . '</option>';
}
echo '</select>';
echo '<select name="region" style="padding:8px 12px;border:1px solid hsl(var(--border));border-radius:8px;min-width:180px">';
echo '<option value="">Todas as regiões</option>';
foreach ($regions as $reg) {
    $sel = $regionFilter === $reg ? ' selected' : '';
    echo '<option value="' . h($reg) . '"' . $sel . '>' . h($reg) . '</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btnPrimary">Filtrar</button>';
echo '<a href="/chat_groups.php" class="btn">Limpar</a>';
echo '</form>';
echo '</section>';

if (empty($groups)) {
    echo '<section class="card col12">';
    echo '<div style="text-align:center;padding:40px;color:hsl(var(--muted-foreground))">Nenhum grupo encontrado. Crie um grupo via Chat ao Vivo.</div>';
    echo '</section>';
} else {
    foreach ($groups as $group) {
        $jid  = $group['group_jid'];
        $name = $group['group_name'];
        $pic  = $group['group_picture_url'] ?? '';
        $spec = $group['specialty'] ?? '';
        $reg  = $group['region'] ?? '';
        $modalId = 'g' . md5($jid);

        echo '<section class="card col4" style="min-width:280px">';
        echo '<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">';
        if (!empty($pic)) {
            echo '<img src="' . h($pic) . '" style="width:56px;height:56px;border-radius:50%;object-fit:cover" alt="">';
        } else {
            echo '<div style="width:56px;height:56px;border-radius:50%;background:#00a884;display:flex;align-items:center;justify-content:center">';
            echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="white"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>';
            echo '</div>';
        }
        echo '<div style="flex:1;min-width:0">';
        echo '<div style="font-weight:700;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' . h($name) . '</div>';
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:2px">';
        if ($spec) echo '<span style="background:hsl(var(--accent));color:hsl(var(--accent-foreground));padding:2px 6px;border-radius:4px;margin-right:4px">' . h($spec) . '</span>';
        if ($reg)  echo '<span style="background:#f1f5f9;color:#64748b;padding:2px 6px;border-radius:4px">' . h($reg) . '</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        echo '<div style="display:flex;flex-direction:column;gap:8px">';
        echo '<a href="/chat_web.php?chat=' . urlencode($jid) . '&type=grupos" class="btn btnPrimary" style="text-align:center">💬 Abrir Chat</a>';
        echo '<button type="button" class="btn" onclick="openModal(\'' . $modalId . '_meta\')">✏️ Editar Metadados</button>';
        echo '<button type="button" class="btn" onclick="openModal(\'' . $modalId . '_part\')" style="background:#e0f2fe;color:#0369a1;border-color:#bae6fd">👥 Gerenciar Participantes</button>';
        echo '</div>';
        echo '</section>';

        // Modal Metadados
        echo '<div id="' . $modalId . '_meta" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">';
        echo '<div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:440px;box-shadow:0 20px 40px rgba(0,0,0,.2)">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">';
        echo '<div style="font-size:18px;font-weight:700">Editar Metadados</div>';
        echo '<button onclick="closeModal(\'' . $modalId . '_meta\')" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b">✕</button>';
        echo '</div>';
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="update_metadata">';
        echo '<input type="hidden" name="group_jid" value="' . h($jid) . '">';
        echo '<div style="margin-bottom:16px">';
        echo '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Especialidade</label>';
        echo '<input type="text" name="specialty" value="' . h($spec) . '" placeholder="Ex: ADS" style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '</div>';
        echo '<div style="margin-bottom:20px">';
        echo '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Região</label>';
        echo '<input type="text" name="region" value="' . h($reg) . '" placeholder="Ex: CARAPEBUS - RJ" style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
        echo '<button type="button" class="btn" onclick="closeModal(\'' . $modalId . '_meta\')">Cancelar</button>';
        echo '<button type="submit" class="btn btnPrimary">Salvar</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        // Modal Participantes
        echo '<div id="' . $modalId . '_part" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">';
        echo '<div style="background:#fff;border-radius:12px;padding:24px;width:100%;max-width:500px;box-shadow:0 20px 40px rgba(0,0,0,.2)">';
        echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">';
        echo '<div style="font-size:18px;font-weight:700">Gerenciar Participantes</div>';
        echo '<button onclick="closeModal(\'' . $modalId . '_part\')" style="background:none;border:none;font-size:20px;cursor:pointer;color:#64748b">✕</button>';
        echo '</div>';
        echo '<div style="margin-bottom:16px;padding:12px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;font-size:13px;color:#856404">';
        echo '⚠️ Adicione pelo menos 1 participante para poder enviar mensagens ao grupo.';
        echo '</div>';
        echo '<form method="post">';
        echo '<input type="hidden" name="action" value="add_participant">';
        echo '<input type="hidden" name="group_jid" value="' . h($jid) . '">';
        echo '<div style="margin-bottom:16px">';
        echo '<label style="display:block;font-size:13px;font-weight:600;margin-bottom:6px">Número do participante</label>';
        echo '<input type="text" name="participant_phone" placeholder="Ex: 5511999999999" required style="width:100%;padding:10px;border:1px solid hsl(var(--border));border-radius:8px">';
        echo '<div style="font-size:12px;color:hsl(var(--muted-foreground));margin-top:4px">DDI + DDD + número, apenas dígitos</div>';
        echo '</div>';
        echo '<div style="display:flex;gap:10px;justify-content:flex-end">';
        echo '<button type="button" class="btn" onclick="closeModal(\'' . $modalId . '_part\')">Cancelar</button>';
        echo '<button type="submit" class="btn btnPrimary">➕ Adicionar ao Grupo</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }
}

echo '</div>';

echo '<script>';
echo 'function openModal(id){var el=document.getElementById(id);if(el){el.style.display="flex";}}';
echo 'function closeModal(id){var el=document.getElementById(id);if(el){el.style.display="none";}}';
echo 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){document.querySelectorAll("[id$=_meta],[id$=_part]").forEach(function(el){el.style.display="none";});}});';
echo '</script>';

view_footer();
