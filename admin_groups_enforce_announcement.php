<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

header('Content-Type: text/html; charset=utf-8');

echo '<h2>Enforcement: Grupos em modo Anúncio (somente admins)</h2><pre>';

$db = db();

// Buscar todos os grupos ativos
$stmt = $db->query("
    SELECT id, name, evolution_group_jid, is_announcement 
    FROM whatsapp_groups 
    WHERE status = 'active' 
      AND evolution_group_jid IS NOT NULL 
      AND evolution_group_jid != ''
    ORDER BY id DESC
");
$groups = $stmt->fetchAll();

if (count($groups) === 0) {
    echo "Nenhum grupo ativo encontrado.\n";
    echo '</pre><br><a href="/admin_settings.php">Voltar</a>';
    exit;
}

echo "Encontrados " . count($groups) . " grupo(s) ativo(s).\n\n";

$api = new EvolutionApiV1();
$updated = 0;
$errors = 0;

foreach ($groups as $g) {
    $jid = (string)$g['evolution_group_jid'];
    $name = (string)$g['name'];
    $isAnnouncement = (int)$g['is_announcement'];
    
    echo "Grupo: $name (JID: $jid) | announcement_db=$isAnnouncement\n";
    
    try {
        $result = $api->updateGroupSetting($jid, 'announcement');
        $httpCode = (int)($result['status'] ?? 0);
        $body = $result['json'] ?? $result['body_raw'] ?? '';
        
        echo "  → HTTP $httpCode | Response: " . json_encode($body) . "\n";
        
        if ($httpCode >= 200 && $httpCode < 300) {
            $upd = $db->prepare('UPDATE whatsapp_groups SET is_announcement = 1 WHERE id = :id');
            $upd->execute(['id' => (int)$g['id']]);
            echo "  ✅ Configurado como somente admins!\n\n";
            $updated++;
        } else {
            echo "  ❌ Falha!\n\n";
            $errors++;
        }
    } catch (Exception $e) {
        echo "  ❌ Exceção: " . $e->getMessage() . "\n\n";
        $errors++;
    }
    
    usleep(500000); // 0.5s entre requests
}

echo "=== RESULTADO ===\n";
echo "✅ Atualizados: $updated\n";
echo "❌ Erros: $errors\n";

echo '</pre>';
echo '<br><a href="/admin_settings.php">Voltar para Configurações</a>';
