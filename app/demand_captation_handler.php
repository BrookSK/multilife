<?php

declare(strict_types=1);

/**
 * Handler para eventos de captação de demandas.
 * Gerencia a lista de profissionais interessados (reações) e notificações.
 */

/**
 * Chamado quando uma demanda é admitida (profissional selecionado).
 * - Marca o profissional selecionado como 'selected'
 * - NÃO notifica os outros como 'rejected' ainda (eles ficam como reserva/substituição)
 * - Envia mensagem no grupo informando que a captação foi preenchida
 * 
 * @param int $demandId ID da demanda
 * @param int $selectedProfessionalUserId ID do profissional selecionado
 */
function demand_on_admitted(int $demandId, int $selectedProfessionalUserId): void
{
    try {
        // Marcar o profissional selecionado
        $stmt = db()->prepare("
            UPDATE demand_interested_professionals 
            SET status = 'selected', selected_at = NOW()
            WHERE demand_id = ? AND user_id = ?
        ");
        $stmt->execute([$demandId, $selectedProfessionalUserId]);
        
        // Se não encontrou pelo user_id, tentar pelo telefone
        if ($stmt->rowCount() === 0) {
            $stmtPhone = db()->prepare("SELECT phone FROM users WHERE id = ?");
            $stmtPhone->execute([$selectedProfessionalUserId]);
            $profRow = $stmtPhone->fetch();
            if ($profRow && !empty($profRow['phone'])) {
                $cleanPhone = preg_replace('/[^0-9]/', '', $profRow['phone']);
                $phoneSuffix = substr($cleanPhone, -8);
                $stmtUpdate = db()->prepare("
                    UPDATE demand_interested_professionals 
                    SET status = 'selected', selected_at = NOW(), user_id = ?
                    WHERE demand_id = ? AND phone LIKE ?
                ");
                $stmtUpdate->execute([$selectedProfessionalUserId, $demandId, '%' . $phoneSuffix . '%']);
            }
        }
        
        // Enviar mensagem no grupo (se encontrar o grupo da captação)
        $stmtGroup = db()->prepare("
            SELECT dl.id, g.evolution_group_jid, d.title
            FROM demand_dispatch_logs dl
            LEFT JOIN whatsapp_groups g ON g.id = dl.group_id
            LEFT JOIN demands d ON d.id = dl.demand_id
            WHERE dl.demand_id = ? AND dl.dispatch_status = 'sent' AND g.evolution_group_jid IS NOT NULL
            LIMIT 1
        ");
        $stmtGroup->execute([$demandId]);
        $groupRow = $stmtGroup->fetch();
        
        if ($groupRow && !empty($groupRow['evolution_group_jid'])) {
            try {
                $api = new EvolutionApiV1();
                $groupMsg = "📋 *Captação preenchida*\n\n"
                    . "A captação *{$groupRow['title']}* já foi atribuída a um profissional.\n\n"
                    . "Obrigado a todos que demonstraram interesse!";
                $api->sendText($groupRow['evolution_group_jid'], $groupMsg, []);
                error_log("[CAPTATION] Mensagem de encerramento enviada no grupo para demanda #$demandId");
            } catch (Exception $e) {
                error_log("[CAPTATION] Erro ao enviar msg no grupo: " . $e->getMessage());
            }
        }
        
        error_log("[CAPTATION] Demanda #$demandId admitida - profissional #$selectedProfessionalUserId selecionado");
    } catch (Exception $e) {
        error_log("[CAPTATION] Erro em demand_on_admitted: " . $e->getMessage());
    }
}

/**
 * Chamado quando uma demanda é concluída/encerrada definitivamente.
 * Notifica todos os profissionais que ficaram como 'interested' (reserva) 
 * que a captação foi encerrada.
 * 
 * @param int $demandId ID da demanda
 */
function demand_on_closed(int $demandId): void
{
    try {
        // Buscar profissionais interessados que não foram selecionados e ainda não foram notificados
        $stmt = db()->prepare("
            SELECT id, phone_jid, push_name 
            FROM demand_interested_professionals 
            WHERE demand_id = ? AND status = 'interested' AND notified_rejection = 0
        ");
        $stmt->execute([$demandId]);
        $interestedPros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($interestedPros)) {
            return;
        }
        
        // Buscar título da demanda
        $stmtDemand = db()->prepare("SELECT title FROM demands WHERE id = ?");
        $stmtDemand->execute([$demandId]);
        $demand = $stmtDemand->fetch();
        $demandTitle = $demand ? $demand['title'] : "Captação #$demandId";
        
        try {
            $api = new EvolutionApiV1();
            
            foreach ($interestedPros as $pro) {
                $phoneJid = $pro['phone_jid'];
                if (empty($phoneJid) || strpos($phoneJid, '@') === false) {
                    continue;
                }
                
                $name = $pro['push_name'] ? ", {$pro['push_name']}" : "";
                $msg = "ℹ️ *Captação encerrada*\n\n"
                    . "Olá{$name}!\n\n"
                    . "A captação *{$demandTitle}* foi encerrada e atribuída a outro profissional.\n\n"
                    . "Agradecemos seu interesse! Novas oportunidades serão enviadas em breve.\n\n"
                    . "Equipe MultiLife";
                
                try {
                    usleep(1500000); // 1.5s delay entre mensagens
                    $api->sendText($phoneJid, $msg, ['delay' => 1200]);
                } catch (Exception $e) {
                    error_log("[CAPTATION] Erro ao notificar $phoneJid: " . $e->getMessage());
                }
                
                // Marcar como notificado
                $stmtNotif = db()->prepare("UPDATE demand_interested_professionals SET status = 'rejected', notified_rejection = 1 WHERE id = ?");
                $stmtNotif->execute([$pro['id']]);
            }
            
            error_log("[CAPTATION] " . count($interestedPros) . " profissionais notificados sobre encerramento da demanda #$demandId");
        } catch (Exception $e) {
            error_log("[CAPTATION] Erro ao enviar notificações de encerramento: " . $e->getMessage());
        }
    } catch (Exception $e) {
        error_log("[CAPTATION] Erro em demand_on_closed: " . $e->getMessage());
    }
}

/**
 * Buscar profissionais interessados em uma demanda (para lista de espera/substituição)
 * 
 * @param int $demandId ID da demanda
 * @return array Lista de profissionais interessados
 */
function demand_get_interested_professionals(int $demandId): array
{
    $stmt = db()->prepare("
        SELECT dip.*, u.name as user_name, u.email as user_email
        FROM demand_interested_professionals dip
        LEFT JOIN users u ON u.id = dip.user_id
        WHERE dip.demand_id = ?
        ORDER BY dip.reacted_at ASC
    ");
    $stmt->execute([$demandId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Substituir profissional: marca o anterior como 'rejected' e seleciona um novo da lista
 * 
 * @param int $demandId ID da demanda
 * @param int $newProfessionalUserId ID do novo profissional (da lista de interessados)
 * @param string|null $reason Motivo da substituição
 */
function demand_substitute_professional(int $demandId, int $newProfessionalUserId, ?string $reason = null): void
{
    try {
        // Desmarcar o profissional anterior
        $stmt = db()->prepare("
            UPDATE demand_interested_professionals 
            SET status = 'rejected'
            WHERE demand_id = ? AND status = 'selected'
        ");
        $stmt->execute([$demandId]);
        
        // Marcar o novo como selecionado
        $stmt = db()->prepare("
            UPDATE demand_interested_professionals 
            SET status = 'selected', selected_at = NOW()
            WHERE demand_id = ? AND user_id = ?
        ");
        $stmt->execute([$demandId, $newProfessionalUserId]);
        
        // Notificar o novo profissional
        $stmtPro = db()->prepare("
            SELECT phone_jid, push_name FROM demand_interested_professionals 
            WHERE demand_id = ? AND user_id = ?
        ");
        $stmtPro->execute([$demandId, $newProfessionalUserId]);
        $pro = $stmtPro->fetch();
        
        if ($pro && !empty($pro['phone_jid'])) {
            $stmtDemand = db()->prepare("SELECT title FROM demands WHERE id = ?");
            $stmtDemand->execute([$demandId]);
            $demand = $stmtDemand->fetch();
            $demandTitle = $demand ? $demand['title'] : "Captação #$demandId";
            
            $name = $pro['push_name'] ? ", {$pro['push_name']}" : "";
            try {
                $api = new EvolutionApiV1();
                $msg = "🔄 *Você foi selecionado!*\n\n"
                    . "Olá{$name}!\n\n"
                    . "Você foi selecionado para a captação:\n"
                    . "📋 *{$demandTitle}*\n\n"
                    . "Um operador entrará em contato em breve com mais detalhes.\n\n"
                    . "Equipe MultiLife";
                $api->sendText($pro['phone_jid'], $msg, ['delay' => 1200]);
            } catch (Exception $e) {
                error_log("[CAPTATION] Erro ao notificar novo profissional: " . $e->getMessage());
            }
        }
        
        error_log("[CAPTATION] Substituição na demanda #$demandId: novo profissional #$newProfessionalUserId" . ($reason ? " (motivo: $reason)" : ""));
    } catch (Exception $e) {
        error_log("[CAPTATION] Erro em demand_substitute_professional: " . $e->getMessage());
    }
}
