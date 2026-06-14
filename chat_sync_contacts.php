<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('chat.manage');

header('Content-Type: application/json');

/**
 * Sincroniza nomes e fotos dos contatos do chat com a Evolution API.
 * - Busca pushName/profilePicture de cada contato
 * - Atualiza a tabela chat_contacts
 * - Remove contatos órfãos (sem mensagens)
 */

$action = trim($_GET['action'] ?? 'sync_all');

try {
    $api = new EvolutionApiV1();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Evolution API não configurada: ' . $e->getMessage()]);
    exit;
}

if ($action === 'sync_all') {
    $updated = 0;
    $errors = 0;
    
    // 1. Buscar todos os contatos da Evolution API
    try {
        $res = $api->findContacts();
        $apiContacts = $res['json'] ?? [];
        
        if (is_array($apiContacts) && !empty($apiContacts)) {
            foreach ($apiContacts as $contact) {
                $jid = $contact['id'] ?? $contact['jid'] ?? '';
                $name = $contact['pushName'] ?? $contact['name'] ?? $contact['verifiedName'] ?? '';
                $pic = $contact['profilePictureUrl'] ?? $contact['profilePicUrl'] ?? $contact['imgUrl'] ?? null;
                
                if (empty($jid)) continue;
                
                // Só atualizar se temos informação nova
                if (!empty($name) || !empty($pic)) {
                    try {
                        $sets = [];
                        $params = ['jid' => $jid];
                        
                        if (!empty($name)) {
                            $sets[] = "contact_name = :name";
                            $params['name'] = $name;
                        }
                        if (!empty($pic)) {
                            $sets[] = "profile_picture_url = :pic";
                            $params['pic'] = $pic;
                        }
                        $sets[] = "updated_at = NOW()";
                        
                        $sql = "UPDATE chat_contacts SET " . implode(', ', $sets) . " WHERE remote_jid = :jid";
                        $stmt = db()->prepare($sql);
                        $stmt->execute($params);
                        
                        if ($stmt->rowCount() > 0) {
                            $updated++;
                        }
                    } catch (Exception $e) {
                        $errors++;
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('[SYNC] Erro ao buscar contatos da API: ' . $e->getMessage());
    }
    
    // 2. Atualizar nomes a partir do pushName das mensagens recentes (fallback)
    try {
        $stmtPush = db()->query("
            SELECT DISTINCT cm.remote_jid, cm.sender_name
            FROM chat_messages cm
            INNER JOIN chat_contacts cc ON cc.remote_jid = cm.remote_jid
            WHERE cm.sender_name IS NOT NULL 
              AND cm.sender_name != ''
              AND cm.from_me = 0
              AND (cc.contact_name IS NULL OR cc.contact_name = '' OR cc.contact_name = REPLACE(REPLACE(cc.remote_jid, '@s.whatsapp.net', ''), '@g.us', ''))
            ORDER BY cm.message_timestamp DESC
        ");
        $pushNameUpdates = $stmtPush->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pushNameUpdates as $row) {
            $stmtUpd = db()->prepare("UPDATE chat_contacts SET contact_name = ? WHERE remote_jid = ? AND (contact_name IS NULL OR contact_name = '' OR contact_name = REPLACE(REPLACE(remote_jid, '@s.whatsapp.net', ''), '@g.us', ''))");
            $stmtUpd->execute([$row['sender_name'], $row['remote_jid']]);
            if ($stmtUpd->rowCount() > 0) $updated++;
        }
    } catch (Exception $e) {
        error_log('[SYNC] Erro ao atualizar pushNames: ' . $e->getMessage());
    }
    
    // 3. Para contatos que ainda não têm nome, usar o nome da tabela users (vinculação por telefone)
    try {
        $stmtUsers = db()->query("
            UPDATE chat_contacts cc
            INNER JOIN users u ON (
                REPLACE(REPLACE(REPLACE(cc.remote_jid, '@s.whatsapp.net', ''), '@g.us', ''), '@lid', '') = u.phone
                OR REPLACE(REPLACE(REPLACE(cc.remote_jid, '@s.whatsapp.net', ''), '@g.us', ''), '@lid', '') = CONCAT('55', u.phone)
                OR CONCAT('55', REPLACE(REPLACE(REPLACE(cc.remote_jid, '@s.whatsapp.net', ''), '@g.us', ''), '@lid', '')) = u.phone
            )
            SET cc.contact_name = u.name
            WHERE (cc.contact_name IS NULL OR cc.contact_name = '' OR cc.contact_name REGEXP '^[0-9]+$')
              AND u.name IS NOT NULL AND u.name != ''
              AND cc.is_group = 0
        ");
        $updated += $stmtUsers->rowCount();
    } catch (Exception $e) {
        error_log('[SYNC] Erro ao vincular users: ' . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true, 
        'updated' => $updated, 
        'errors' => $errors,
        'message' => "Sincronização concluída: $updated contatos atualizados."
    ]);
    
} elseif ($action === 'clean_old') {
    // Limpar contatos antigos sem mensagens (órfãos)
    $deleted = 0;
    try {
        $stmt = db()->query("
            DELETE cc FROM chat_contacts cc
            LEFT JOIN chat_messages cm ON cm.remote_jid = cc.remote_jid
            WHERE cm.id IS NULL AND cc.last_message_timestamp IS NULL
        ");
        $deleted = $stmt->rowCount();
    } catch (Exception $e) {
        error_log('[SYNC] Erro ao limpar contatos: ' . $e->getMessage());
    }
    
    echo json_encode(['success' => true, 'deleted' => $deleted, 'message' => "$deleted contatos órfãos removidos."]);
    
} elseif ($action === 'reset_photos') {
    // Limpar todas as fotos (forçar re-download na próxima sincronização)
    try {
        $stmt = db()->query("UPDATE chat_contacts SET profile_picture_url = NULL WHERE is_group = 0");
        $cleared = $stmt->rowCount();
        echo json_encode(['success' => true, 'cleared' => $cleared, 'message' => "Fotos de $cleared contatos limpas. Clique em Sincronizar para baixar novas."]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    
} else {
    echo json_encode(['success' => false, 'error' => 'Ação inválida']);
}
