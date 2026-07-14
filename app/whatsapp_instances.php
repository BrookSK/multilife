<?php

declare(strict_types=1);

/**
 * Helpers para multi-instância WhatsApp.
 * 
 * Cada usuário pode ter sua própria conexão WhatsApp (instância na Evolution API).
 * Se não tiver, usa a instância padrão da plataforma (is_default=1 ou admin_settings).
 * 
 * Na criação de grupos, TODOS os números conectados (de todas as instâncias ativas)
 * são adicionados como participantes para garantir presença em todos os grupos.
 */

/**
 * Retorna todos os números de telefone de instâncias WhatsApp ativas e conectadas.
 * Usado para adicionar todos os números como participantes/admins nos grupos criados.
 *
 * @return array<string> Array de números formatados (ex: ['5517991253062', '5511999887766'])
 */
function whatsapp_get_all_connected_numbers(): array
{
    $numbers = [];

    // Buscar todas as instâncias ativas que têm número de telefone cadastrado
    $stmt = db()->prepare("
        SELECT owner_number, owner_phone_formatted 
        FROM whatsapp_instances 
        WHERE status = 'active' 
        AND (connection_status = 'connected' OR connection_status IS NULL)
        AND (owner_number IS NOT NULL AND owner_number != '')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        // Preferir o campo formatado, senão limpar o owner_number
        $phone = trim((string)($row['owner_phone_formatted'] ?? ''));
        if ($phone === '') {
            $phone = preg_replace('/\D+/', '', (string)($row['owner_number'] ?? ''));
        }

        if ($phone === '') {
            continue;
        }

        // Garantir formato com DDI (55...)
        if (strlen($phone) === 10 || strlen($phone) === 11) {
            $phone = '55' . $phone;
        }

        if (strlen($phone) >= 12) {
            $numbers[] = $phone;
        }
    }

    // Fallback: incluir o número admin configurado nas settings (instância padrão)
    $adminPhone = trim((string)admin_setting_get('evolution.admin_phone', ''));
    if ($adminPhone !== '') {
        $adminPhone = preg_replace('/\D+/', '', $adminPhone);
        if (strlen($adminPhone) === 10 || strlen($adminPhone) === 11) {
            $adminPhone = '55' . $adminPhone;
        }
        if (strlen($adminPhone) >= 12 && !in_array($adminPhone, $numbers, true)) {
            $numbers[] = $adminPhone;
        }
    }

    return array_values(array_unique($numbers));
}

/**
 * Retorna a instância WhatsApp de um usuário específico.
 * Se o usuário não tiver instância própria conectada, retorna a instância padrão da plataforma.
 *
 * @param int|null $userId ID do usuário (null = retorna a padrão)
 * @return array{instance_name: string, token: string, owner_number: string, is_default: bool}|null
 */
function whatsapp_get_user_instance(?int $userId = null): ?array
{
    // Se passou userId, tentar buscar instância do usuário
    if ($userId !== null && $userId > 0) {
        $stmt = db()->prepare("
            SELECT instance_name, token, owner_number, owner_phone_formatted, is_default, connection_status
            FROM whatsapp_instances 
            WHERE user_id = :uid 
            AND status = 'active' 
            AND (connection_status = 'connected' OR connection_status IS NULL)
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        $row = $stmt->fetch();

        if ($row) {
            return [
                'instance_name' => (string)$row['instance_name'],
                'token' => (string)($row['token'] ?? ''),
                'owner_number' => (string)($row['owner_phone_formatted'] ?: $row['owner_number'] ?: ''),
                'is_default' => false,
            ];
        }
    }

    // Fallback: buscar instância padrão (is_default=1)
    $stmt = db()->prepare("
        SELECT instance_name, token, owner_number, owner_phone_formatted, is_default, connection_status
        FROM whatsapp_instances 
        WHERE is_default = 1 
        AND status = 'active'
        ORDER BY id ASC 
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch();

    if ($row) {
        return [
            'instance_name' => (string)$row['instance_name'],
            'token' => (string)($row['token'] ?? ''),
            'owner_number' => (string)($row['owner_phone_formatted'] ?: $row['owner_number'] ?: ''),
            'is_default' => true,
        ];
    }

    // Último fallback: usar admin_settings (compatibilidade com versão anterior)
    $instanceName = (string)admin_setting_get('evolution.instance', '');
    if ($instanceName !== '') {
        return [
            'instance_name' => $instanceName,
            'token' => (string)admin_setting_get('evolution.api_key', ''),
            'owner_number' => '',
            'is_default' => true,
        ];
    }

    return null;
}

/**
 * Cria uma instância EvolutionApiV1 para o usuário (ou padrão se não tiver).
 *
 * @param int|null $userId ID do usuário
 * @return EvolutionApiV1
 * @throws RuntimeException se nenhuma instância disponível
 */
function whatsapp_get_api_for_user(?int $userId = null): EvolutionApiV1
{
    $inst = whatsapp_get_user_instance($userId);

    if ($inst === null) {
        // Construtor padrão vai usar admin_settings
        return new EvolutionApiV1();
    }

    // Se é a instância padrão, pode usar o construtor sem params (vai ler admin_settings)
    if ($inst['is_default']) {
        return new EvolutionApiV1();
    }

    // Instância do usuário: usar os mesmos base_url e api_key, mas instance diferente
    $baseUrl = (string)admin_setting_get('evolution.base_url', '');
    $apiKey = (string)admin_setting_get('evolution.api_key', '');

    return new EvolutionApiV1($baseUrl, $apiKey, $inst['instance_name']);
}

/**
 * Retorna todas as instâncias ativas (para listagem/administração).
 *
 * @return array Lista de instâncias com dados do usuário associado
 */
function whatsapp_list_all_instances(): array
{
    $stmt = db()->prepare("
        SELECT wi.*, u.name AS user_name, u.email AS user_email
        FROM whatsapp_instances wi
        LEFT JOIN users u ON u.id = wi.user_id
        WHERE wi.status = 'active'
        ORDER BY wi.is_default DESC, wi.created_at ASC
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

/**
 * Vincula uma instância existente a um usuário.
 *
 * @param int $instanceId ID da instância
 * @param int $userId ID do usuário
 * @return bool
 */
function whatsapp_link_instance_to_user(int $instanceId, int $userId): bool
{
    $stmt = db()->prepare("
        UPDATE whatsapp_instances 
        SET user_id = :uid 
        WHERE id = :id AND status = 'active'
    ");
    $stmt->execute(['uid' => $userId, 'id' => $instanceId]);
    return $stmt->rowCount() > 0;
}

/**
 * Desvincula a instância de um usuário (volta a ser instância sem dono).
 *
 * @param int $instanceId ID da instância
 * @return bool
 */
function whatsapp_unlink_instance(int $instanceId): bool
{
    $stmt = db()->prepare("
        UPDATE whatsapp_instances 
        SET user_id = NULL 
        WHERE id = :id AND is_default = 0
    ");
    $stmt->execute(['id' => $instanceId]);
    return $stmt->rowCount() > 0;
}

/**
 * Atualiza o status de conexão de uma instância.
 *
 * @param string $instanceName Nome da instância
 * @param string $status 'connected', 'disconnected' ou 'connecting'
 * @param string|null $phoneNumber Número de telefone detectado (opcional)
 */
function whatsapp_update_connection_status(string $instanceName, string $status, ?string $phoneNumber = null): void
{
    $params = ['name' => $instanceName, 'status' => $status];
    $extraSet = '';

    if ($phoneNumber !== null && $phoneNumber !== '') {
        $clean = preg_replace('/\D+/', '', $phoneNumber);
        if (strlen($clean) === 10 || strlen($clean) === 11) {
            $clean = '55' . $clean;
        }
        $extraSet = ', owner_phone_formatted = :phone, owner_number = :phone_raw';
        $params['phone'] = $clean;
        $params['phone_raw'] = $phoneNumber;
    }

    $stmt = db()->prepare("
        UPDATE whatsapp_instances 
        SET connection_status = :status $extraSet
        WHERE instance_name = :name
    ");
    $stmt->execute($params);
}
