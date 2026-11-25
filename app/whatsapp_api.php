<?php

declare(strict_types=1);

/**
 * Cliente do WhatsApp do sistema: serviço ml-baileys-service (Node + Baileys),
 * pasta ml-baileys-service/ deste repositório.
 *
 * O serviço fala o MESMO contrato de API e de webhook da Evolution v2 (rotas,
 * corpos e respostas), então os métodos abaixo mantêm os nomes e formatos que
 * o resto do sistema já usa. Configuração em Configurações → WhatsApp Conexão:
 *   baileys.base_url  URL do serviço (ex.: http://177.104.185.7:8090)
 *   baileys.api_key   API_KEY do serviço (.env)
 *   baileys.instance  WhatsApp padrão das mensagens automáticas
 */

/** URL base do serviço WhatsApp (sem barra no final). */
function wa_api_base_url(): string
{
    return rtrim(trim((string)admin_setting_get('baileys.base_url', '')), '/');
}

/** Chave da API do serviço WhatsApp. */
function wa_api_key(): string
{
    return trim((string)admin_setting_get('baileys.api_key', ''));
}

/**
 * Configuração do WhatsApp que não depende do servidor (instância padrão,
 * telefone do admin...). Bases que ainda não salvaram a chave nova usam o valor
 * antigo (evolution.*), para não perder a configuração na troca.
 */
function wa_setting(string $name, string $default = ''): string
{
    $v = admin_setting_get('baileys.' . $name, null);
    if ($v === null || trim($v) === '') {
        $v = admin_setting_get('evolution.' . $name, null);
    }
    return $v === null || trim($v) === '' ? $default : trim($v);
}

/** WhatsApp padrão das mensagens automáticas (cron, aprovações, avisos). */
function wa_default_instance(): string
{
    return wa_setting('instance');
}

/** Serviço configurado (URL + chave)? */
function wa_api_configured(): bool
{
    return wa_api_base_url() !== '' && wa_api_key() !== '';
}

/** Cliente para uma instância (padrão: a instância padrão). */
function wa_api(?string $instance = null): WhatsAppApi
{
    return new WhatsAppApi(null, null, $instance);
}

/** Cabeçalhos de autenticação do serviço (para chamadas cURL diretas). */
function wa_api_headers(): array
{
    return ['apikey' => wa_api_key()];
}

final class WhatsAppApi
{
    private $baseUrl;
    private $apiKey;
    private $instance;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?string $instance = null)
    {
        $this->baseUrl = rtrim((string)($baseUrl !== null && $baseUrl !== '' ? $baseUrl : wa_api_base_url()), '/');
        $this->apiKey = (string)($apiKey !== null && $apiKey !== '' ? $apiKey : wa_api_key());
        $this->instance = (string)($instance !== null && $instance !== '' ? $instance : wa_default_instance());

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->instance === '') {
            throw new RuntimeException('Serviço WhatsApp (Baileys) não configurado: preencha URL, API Key e instância em Configurações → WhatsApp Conexão.');
        }
    }

    private function url(string $path): string
    {
        return $this->baseUrl . '/' . ltrim($path, '/');
    }

    public function getBaseUrl(): string { return $this->baseUrl; }
    public function getApiKey(): string { return $this->apiKey; }
    public function getInstance(): string { return $this->instance; }

    private function inst(?string $instanceName = null): string
    {
        $i = (string)($instanceName ?? $this->instance);
        if ($i === '') {
            throw new RuntimeException('Instance não informada.');
        }
        return rawurlencode($i);
    }

    /** @return array{status:int, headers_raw:string, body_raw:string, json:mixed} */
    public function request(string $method, string $path, array $query = [], $body = null, int $timeoutSeconds = 30): array
    {
        $url = $this->url($path);
        if (count($query) > 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        try {
            $res = http_json_request($method, $url, ['apikey' => $this->apiKey], $body, $timeoutSeconds);
        } catch (Throwable $e) {
            integration_log('whatsapp', $method . ' ' . $path, 'error', null, ['query' => $query, 'body' => $body], null, mb_strimwidth($e->getMessage(), 0, 250, ''), 1);
            throw $e;
        }

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        integration_log(
            'whatsapp',
            $method . ' ' . $path,
            $ok ? 'success' : 'error',
            (int)$res['status'],
            ['query' => $query, 'body' => $body],
            $res['json'] ?? $res['body_raw'],
            $ok ? null : 'HTTP ' . (string)$res['status'],
            1
        );

        return $res;
    }

    /** Informações do serviço (versão do Baileys / WhatsApp Web). */
    public function getInformation(): array
    {
        return $this->request('GET', '/', [], null, 8);
    }

    // --------------------
    // Instâncias (QR Code / status)
    // --------------------

    public function fetchInstances(?string $instanceName = null): array
    {
        $query = [];
        if ($instanceName !== null && $instanceName !== '') {
            $query['instanceName'] = $instanceName;
        }
        return $this->request('GET', '/instance/fetchInstances', $query, null, 12);
    }

    public function createInstanceBasic(array $payload): array
    {
        return $this->request('POST', '/instance/create', [], $payload, 40);
    }

    /** QR Code (e código de pareamento se vier o número). */
    public function connectInstance(?string $instanceName = null, ?string $number = null): array
    {
        $query = [];
        if ($number !== null && $number !== '') {
            $query['number'] = $number;
        }
        return $this->request('GET', '/instance/connect/' . $this->inst($instanceName), $query, null, 40);
    }

    public function connectionState(?string $instanceName = null, int $timeoutSeconds = 10): array
    {
        return $this->request('GET', '/instance/connectionState/' . $this->inst($instanceName), [], null, $timeoutSeconds);
    }

    public function getConnectionStatus(?string $instanceName = null): array
    {
        return $this->connectionState($instanceName);
    }

    public function generateQrCode(?string $instanceName = null): array
    {
        return $this->connectInstance($instanceName);
    }

    /** Reinicia a conexão com a sessão salva (não pede QR Code de novo). */
    public function restartInstance(?string $instanceName = null): array
    {
        return $this->request('POST', '/instance/restart/' . $this->inst($instanceName), [], null, 30);
    }

    public function logoutInstance(?string $instanceName = null): array
    {
        return $this->request('DELETE', '/instance/logout/' . $this->inst($instanceName), [], null, 20);
    }

    public function deleteInstance(?string $instanceName = null): array
    {
        return $this->request('DELETE', '/instance/delete/' . $this->inst($instanceName), [], null, 20);
    }

    // --------------------
    // Webhook e opções da instância
    // --------------------

    public function setWebhook(array $payload, ?string $instanceName = null): array
    {
        return $this->request('POST', '/webhook/set/' . $this->inst($instanceName), [], $payload, 15);
    }

    public function findWebhook(?string $instanceName = null): array
    {
        return $this->request('GET', '/webhook/find/' . $this->inst($instanceName), [], null, 8);
    }

    public function setSettings(array $settings, ?string $instanceName = null): array
    {
        return $this->request('POST', '/settings/set/' . $this->inst($instanceName), [], $settings, 15);
    }

    public function findSettings(?string $instanceName = null): array
    {
        return $this->request('GET', '/settings/find/' . $this->inst($instanceName), [], null, 8);
    }

    // --------------------
    // Conversas
    // --------------------

    public function findChats(): array
    {
        return $this->request('POST', '/chat/findChats/' . $this->inst(), [], new stdClass());
    }

    public function findMessages(string $remoteJid): array
    {
        return $this->request('POST', '/chat/findMessages/' . $this->inst(), [], [
            'where' => ['key' => ['remoteJid' => $remoteJid]],
        ]);
    }

    /** Últimas mensagens de uma conversa (mais nova primeiro). */
    public function findRecentMessages(string $remoteJid, int $count = 30, int $timeoutSeconds = 8): array
    {
        return $this->request('POST', '/chat/findMessages/' . $this->inst(), [], [
            'where' => ['key' => ['remoteJid' => $remoteJid]],
            'page' => 1,
            'offset' => max(1, $count),
        ], $timeoutSeconds);
    }

    /** Baixa a mídia de uma mensagem (base64). */
    public function getBase64FromMediaMessage(array $key, array $message = [], int $timeoutSeconds = 20): array
    {
        return $this->request('POST', '/chat/getBase64FromMediaMessage/' . $this->inst(), [], [
            'message' => ['key' => $key, 'message' => $message ?: new stdClass()],
            'convertToMp4' => false,
        ], $timeoutSeconds);
    }

    public function fetchProfile(string $number, int $timeoutSeconds = 10): array
    {
        return $this->request('POST', '/chat/fetchProfile/' . $this->inst(), [], ['number' => $number], $timeoutSeconds);
    }

    public function fetchProfilePictureUrl(string $number, int $timeoutSeconds = 10): array
    {
        return $this->request('POST', '/chat/fetchProfilePictureUrl/' . $this->inst(), [], ['number' => $number], $timeoutSeconds);
    }

    public function archiveChat(array $lastMessageKey, bool $archive = true): array
    {
        return $this->request('POST', '/chat/archiveChat/' . $this->inst(), [], [
            'lastMessage' => ['key' => $lastMessageKey],
            'archive' => $archive,
        ]);
    }

    public function checkIsWhatsapp(array $numbers): array
    {
        return $this->request('POST', '/chat/whatsappNumbers/' . $this->inst(), [], ['numbers' => $numbers]);
    }

    public function markMessageAsRead(array $readMessages): array
    {
        return $this->request('POST', '/chat/markMessageAsRead/' . $this->inst(), [], ['readMessages' => $readMessages]);
    }

    public function deleteMessageForEveryone(array $key): array
    {
        return $this->request('DELETE', '/chat/deleteMessageForEveryone/' . $this->inst(), [], $key);
    }

    public function updateMessage(int $number, string $text, array $key): array
    {
        return $this->request('POST', '/chat/updateMessage/' . $this->inst(), [], [
            'number' => $number,
            'text' => $text,
            'key' => $key,
        ]);
    }

    public function findContacts(?string $id = null): array
    {
        $where = [];
        if ($id !== null && $id !== '') {
            $where['id'] = $id;
        }
        return $this->request('POST', '/chat/findContacts/' . $this->inst(), [], ['where' => (object)$where]);
    }

    public function findStatusMessage(array $where, ?int $limit = null): array
    {
        $body = ['where' => $where];
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        return $this->request('POST', '/chat/findStatusMessage/' . $this->inst(), [], $body);
    }

    // --------------------
    // Mensagens (conversas privadas + grupos)
    // --------------------

    /** Corpo do sendText (campo "text"; "textMessage.text" também é aceito pelo serviço). */
    private function textBody(string $number, string $text): array
    {
        return ['number' => $number, 'text' => $text];
    }

    public function sendText(string $number, string $text, array $options = []): array
    {
        $body = $this->textBody($number, $text);
        // delay/quoted/menções vão na raiz do corpo; "options" fica para chamadas antigas
        foreach (['linkPreview', 'delay', 'quoted', 'mentionsEveryOne', 'mentioned'] as $k) {
            if (array_key_exists($k, $options)) {
                $body[$k] = $options[$k];
                unset($options[$k]);
            }
        }
        if (!empty($options)) {
            $body['options'] = (object)$options;
        }
        return $this->request('POST', '/message/sendText/' . $this->inst(), [], $body);
    }

    /**
     * Texto para grupo (JID completo @g.us). Timeout maior: no primeiro envio
     * depois de conectar, o WhatsApp abre a criptografia com cada aparelho de
     * cada participante, o que leva bem mais de 30 s em grupo grande.
     */
    public function sendTextToGroup(string $groupJid, string $text, int $timeoutSeconds = 150): array
    {
        return $this->request('POST', '/message/sendText/' . $this->inst(), [], $this->textBody($groupJid, $text), $timeoutSeconds);
    }

    public function sendMedia(string $number, string $mediaType, string $fileName, string $media, ?string $caption = null, array $options = [], ?string $mimeType = null): array
    {
        $body = [
            'number' => $number,
            'mediatype' => $mediaType,
            'fileName' => $fileName,
            'media' => $media,
        ];
        if ($mimeType !== null && $mimeType !== '') {
            $body['mimetype'] = $mimeType;
        }
        if ($caption !== null && $caption !== '') {
            $body['caption'] = $caption;
        }
        foreach (['delay', 'quoted', 'mentionsEveryOne', 'mentioned'] as $k) {
            if (array_key_exists($k, $options)) {
                $body[$k] = $options[$k];
            }
        }
        return $this->request('POST', '/message/sendMedia/' . $this->inst(), [], $body, 120);
    }

    public function sendContact(string $number, array $contactMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendContact/' . $this->inst(), [], [
            'number' => $number,
            'contact' => $contactMessage,
            'options' => (object)$options,
        ]);
    }

    /** Nota de voz (o serviço converte para ogg/opus). */
    public function sendWhatsAppAudio(string $number, string $audio, array $options = []): array
    {
        $body = ['number' => $number, 'audio' => $audio];
        if (isset($options['delay'])) {
            $body['delay'] = $options['delay'];
        }
        return $this->request('POST', '/message/sendWhatsAppAudio/' . $this->inst(), [], $body, 120);
    }

    /** Modelos (templates) não existem no WhatsApp comum: o serviço responde 400. */
    public function sendTemplate(string $number, array $templateMessage): array
    {
        return $this->request('POST', '/message/sendTemplate/' . $this->inst(), [], [
            'number' => $number,
            'templateMessage' => $templateMessage,
        ]);
    }

    public function sendStatus(array $statusMessage): array
    {
        return $this->request('POST', '/message/sendStatus/' . $this->inst(), [], ['statusMessage' => $statusMessage], 60);
    }

    public function sendLocation(string $number, array $locationMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendLocation/' . $this->inst(), [], [
            'number' => $number,
            'locationMessage' => $locationMessage,
            'options' => (object)$options,
        ]);
    }

    public function sendReaction(array $reactionMessage): array
    {
        return $this->request('POST', '/message/sendReaction/' . $this->inst(), [], ['reactionMessage' => $reactionMessage]);
    }

    public function sendSticker(string $number, string $image, array $options = []): array
    {
        return $this->request('POST', '/message/sendSticker/' . $this->inst(), [], [
            'number' => $number,
            'sticker' => $image,
            'options' => (object)$options,
        ]);
    }

    public function sendPoll(string $number, array $pollMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendPoll/' . $this->inst(), [], [
            'number' => $number,
            'pollMessage' => $pollMessage,
            'options' => (object)$options,
        ]);
    }

    /** Listas não existem no WhatsApp comum: o serviço responde 400. */
    public function sendList(string $number, array $listMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendList/' . $this->inst(), [], [
            'number' => $number,
            'options' => (object)$options,
            'listMessage' => $listMessage,
        ]);
    }

    // --------------------
    // Grupos
    // --------------------

    public function fetchAllGroups(bool $getMembers = false, int $timeoutSeconds = 25): array
    {
        return $this->request('GET', '/group/fetchAllGroups/' . $this->inst(), ['getParticipants' => $getMembers ? 'true' : 'false'], null, $timeoutSeconds);
    }

    public function findGroupByJid(string $groupJid, int $timeoutSeconds = 15): array
    {
        return $this->request('GET', '/group/findGroupInfos/' . $this->inst(), ['groupJid' => $groupJid], null, $timeoutSeconds);
    }

    public function findGroupMembers(string $groupJid, int $timeoutSeconds = 20): array
    {
        return $this->request('GET', '/group/participants/' . $this->inst(), ['groupJid' => $groupJid], null, $timeoutSeconds);
    }

    public function createGroup(string $subject, array $participants, ?string $description = null): array
    {
        $body = ['subject' => $subject, 'participants' => $participants];
        if ($description !== null && $description !== '') {
            $body['description'] = $description;
        }
        return $this->request('POST', '/group/create/' . $this->inst(), [], $body, 60);
    }

    /** $action: add | remove | promote | demote */
    public function updateGroupMembers(string $groupJid, string $action, array $participants): array
    {
        return $this->request('POST', '/group/updateParticipant/' . $this->inst(), [], [
            'groupJid' => $groupJid,
            'action' => $action,
            'participants' => $participants,
        ], 60);
    }

    /** $action: announcement | not_announcement | locked | unlocked */
    public function updateGroupSetting(string $groupJid, string $action): array
    {
        return $this->request('POST', '/group/updateSetting/' . $this->inst(), [], ['groupJid' => $groupJid, 'action' => $action], 15);
    }

    public function updateGroupSubject(string $groupJid, string $subject): array
    {
        return $this->request('POST', '/group/updateGroupSubject/' . $this->inst(), ['groupJid' => $groupJid], ['subject' => $subject]);
    }

    public function updateGroupDescription(string $groupJid, string $description): array
    {
        return $this->request('POST', '/group/updateGroupDescription/' . $this->inst(), ['groupJid' => $groupJid], ['description' => $description]);
    }

    public function updateGroupPicture(string $groupJid, string $imageUrl): array
    {
        return $this->request('POST', '/group/updateGroupPicture/' . $this->inst(), ['groupJid' => $groupJid], ['image' => $imageUrl]);
    }

    public function fetchInviteCode(string $groupJid): array
    {
        return $this->request('GET', '/group/inviteCode/' . $this->inst(), ['groupJid' => $groupJid]);
    }

    public function acceptInviteCode(string $inviteCode): array
    {
        return $this->request('GET', '/group/acceptInviteCode/' . $this->inst(), ['inviteCode' => $inviteCode]);
    }

    public function revokeInviteCode(string $groupJid): array
    {
        return $this->request('POST', '/group/revokeInviteCode/' . $this->inst(), ['groupJid' => $groupJid]);
    }

    public function sendGroupInvite(string $groupJid, array $numbers, string $description): array
    {
        return $this->request('POST', '/group/sendInvite/' . $this->inst(), [], [
            'groupJid' => $groupJid,
            'numbers' => $numbers,
            'description' => $description,
        ]);
    }

    public function findGroupByInviteCode(string $inviteCode): array
    {
        return $this->request('GET', '/group/inviteInfo/' . $this->inst(), ['inviteCode' => $inviteCode]);
    }

    public function leaveGroup(string $groupJid): array
    {
        return $this->request('DELETE', '/group/leaveGroup/' . $this->inst(), ['groupJid' => $groupJid]);
    }

    public function toggleEphemeral(string $groupJid, int $expirationSeconds): array
    {
        return $this->request('POST', '/group/toggleEphemeral/' . $this->inst(), ['groupJid' => $groupJid], ['expiration' => $expirationSeconds]);
    }

    // --------------------
    // Atalhos de mídia
    // --------------------

    public function sendAudio(string $number, string $audioUrl, array $options = []): array
    {
        return $this->sendWhatsAppAudio($number, $audioUrl, $options);
    }

    public function sendImage(string $number, string $imageUrl, ?string $caption = null, array $options = []): array
    {
        $fileName = basename((string)parse_url($imageUrl, PHP_URL_PATH));
        return $this->sendMedia($number, 'image', $fileName, $imageUrl, $caption, $options);
    }

    public function sendVideo(string $number, string $videoUrl, ?string $caption = null, array $options = []): array
    {
        $fileName = basename((string)parse_url($videoUrl, PHP_URL_PATH));
        return $this->sendMedia($number, 'video', $fileName, $videoUrl, $caption, $options);
    }

    public function sendDocument(string $number, string $documentUrl, ?string $fileName = null, array $options = []): array
    {
        if ($fileName === null || $fileName === '') {
            $fileName = basename((string)parse_url($documentUrl, PHP_URL_PATH));
        }
        return $this->sendMedia($number, 'document', $fileName, $documentUrl, null, $options);
    }
}
