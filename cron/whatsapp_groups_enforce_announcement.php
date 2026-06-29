<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * CRON: Garantir que todos os grupos WhatsApp ativos estão em modo "anúncio"
 * (somente administradores podem publicar, participantes só podem reagir)
 * 
 * Conforme definição operacional:
 * - Grupos em formato fechado
 * - Publicação exclusiva por administradores
 * - Bloqueio de envio de mensagens pelos participantes
 * - Manutenção das reações disponíveis
 */

$db = db();

$baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
$apiKey = (string)admin_setting_get('evolution.api_key', '');
$instanceName = (string)admin_setting_get('evolution.instance', '');

if ($baseUrl === '' || $apiKey === '' || $instanceName === '') {
    echo "Evolution API não configurada.\n";
    exit;
}

// Buscar grupos ativos que ainda não foram marcados como announcement
$stmt = $db->query("
    SELECT id, name, evolution_group_jid 
    FROM whatsapp_groups 
    WHERE status = 'active' 
      AND evolution_group_jid IS NOT NULL 
      AND evolution_group_jid != ''
      AND is_announcement = 0
");
$groups = $stmt->fetchAll();

if (count($groups) === 0) {
    echo "OK: todos os grupos já estão em modo anúncio.\n";
    exit;
}

$updated = 0;
$errors = 0;

foreach ($groups as $g) {
    $jid = (string)$g['evolution_group_jid'];
    
    try {
        $settingsUrl = $baseUrl . '/group/updateSetting/' . urlencode($instanceName);
        $payload = json_encode([
            'groupJid' => $jid,
            'action' => 'announcement', // Somente admins podem enviar
        ]);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $settingsUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ["Content-Type: application/json", "apikey: " . $apiKey],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            // Marcar no banco como announcement ativado
            $upd = $db->prepare('UPDATE whatsapp_groups SET is_announcement = 1 WHERE id = :id');
            $upd->execute(['id' => (int)$g['id']]);
            $updated++;
            echo "✓ Grupo '{$g['name']}' configurado como somente admins.\n";
        } else {
            $errors++;
            echo "✗ Erro ao configurar grupo '{$g['name']}': HTTP $httpCode\n";
        }
    } catch (Exception $e) {
        $errors++;
        echo "✗ Exceção ao configurar grupo '{$g['name']}': " . $e->getMessage() . "\n";
    }
    
    // Delay entre requests para não sobrecarregar
    usleep(500000); // 0.5s
}

echo "\nConcluído: $updated grupos atualizados, $errors erros.\n";
