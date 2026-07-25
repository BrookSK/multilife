<?php

declare(strict_types=1);

final class EvolutionApiV1
{
    private $baseUrl;
    private $apiKey;
    private $instance;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, ?string $instance = null)
    {
        $this->baseUrl = rtrim((string)($baseUrl ?? admin_setting_get('evolution.base_url', '')), '/');
        $this->apiKey = (string)($apiKey ?? admin_setting_get('evolution.api_key', ''));
        $this->instance = (string)($instance ?? admin_setting_get('evolution.instance', ''));

        if ($this->baseUrl === '' || $this->apiKey === '' || $this->instance === '') {
            throw new RuntimeException('Evolution API não configurada (base_url/api_key/instance).');
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
        return $i;
    }

    private function request(string $method, string $path, array $query = [], $body = null): array
    {
        $url = $this->url($path);
        if (count($query) > 0) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        $headers = [
            'apikey' => $this->apiKey,
        ];

        $res = http_json_request($method, $url, $headers, $body);

        $ok = $res['status'] >= 200 && $res['status'] < 300;
        integration_log(
            'evolution',
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

    public function getInformation(): array
    {
        return $this->request('GET', '/');
    }

    // --------------------
    // Instance Controller (Admin / QR / status)
    // --------------------

    public function fetchInstances(?string $instanceName = null): array
    {
        $query = [];
        if ($instanceName !== null && $instanceName !== '') {
            $query['instanceName'] = $instanceName;
        }
        return $this->request('GET', '/instance/fetchInstances', $query);
    }

    public function createInstanceBasic(array $payload): array
    {
        return $this->request('POST', '/instance/create', [], $payload);
    }

    public function connectInstance(?string $instanceName = null, ?string $number = null): array
    {
        $query = [];
        if ($number !== null && $number !== '') {
            $query['number'] = $number;
        }
        return $this->request('GET', '/instance/connect/' . urlencode($this->inst($instanceName)), $query);
    }

    public function connectionState(?string $instanceName = null): array
    {
        return $this->request('GET', '/instance/connectionState/' . urlencode($this->inst($instanceName)));
    }

    public function getConnectionStatus(?string $instanceName = null): array
    {
        return $this->connectionState($instanceName);
    }

    public function generateQrCode(?string $instanceName = null): array
    {
        return $this->connectInstance($instanceName);
    }

    public function restartInstance(?string $instanceName = null): array
    {
        return $this->request('PUT', '/instance/restart/' . urlencode($this->inst($instanceName)));
    }

    public function logoutInstance(?string $instanceName = null): array
    {
        return $this->request('DELETE', '/instance/logout/' . urlencode($this->inst($instanceName)));
    }

    public function deleteInstance(?string $instanceName = null): array
    {
        return $this->request('DELETE', '/instance/delete/' . urlencode($this->inst($instanceName)));
    }

    // --------------------
    // Chat Controller
    // --------------------

    public function findChats(): array
    {
        return $this->request('GET', '/chat/findChats/' . urlencode($this->inst()));
    }

    public function findMessages(string $remoteJid): array
    {
        return $this->request('POST', '/chat/findMessages/' . urlencode($this->inst()), [], [
            'where' => [
                'key' => [
                    'remoteJid' => $remoteJid,
                ],
            ],
        ]);
    }

    public function archiveChat(array $lastMessageKey, bool $archive = true): array
    {
        return $this->request('PUT', '/chat/archiveChat/' . urlencode($this->inst()), [], [
            'lastMessage' => [
                'key' => $lastMessageKey,
            ],
            'archive' => $archive,
        ]);
    }

    public function checkIsWhatsapp(array $numbers): array
    {
        return $this->request('POST', '/chat/whatsappNumbers/' . urlencode($this->inst()), [], [
            'numbers' => $numbers,
        ]);
    }

    public function markMessageAsRead(array $readMessages): array
    {
        return $this->request('PUT', '/chat/markMessageAsRead/' . urlencode($this->inst()), [], [
            'read_messages' => $readMessages,
        ]);
    }

    public function deleteMessageForEveryone(array $key): array
    {
        return $this->request('DELETE', '/chat/deleteMessageForEveryone/' . urlencode($this->inst()), [], $key);
    }

    public function updateMessage(int $number, string $text, array $key): array
    {
        return $this->request('PUT', '/chat/updateMessage/' . urlencode($this->inst()), [], [
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
        return $this->request('POST', '/chat/findContacts/' . urlencode($this->inst()), [], [
            'where' => (object)$where,
        ]);
    }

    public function findStatusMessage(array $where, ?int $limit = null): array
    {
        $body = [
            'where' => $where,
        ];
        if ($limit !== null) {
            $body['limit'] = $limit;
        }
        return $this->request('POST', '/chat/findStatusMessage/' . urlencode($this->inst()), [], $body);
    }

    // --------------------
    // Message Controller (conversas privadas + grupos)
    // --------------------

    public function sendText(string $number, string $text, array $options = []): array
    {
        $body = [
            'number' => $number,
            'text' => $text,
        ];
        // Só incluir options se não estiver vazio (evitar enviar options:{} que pode causar 400 em grupos)
        if (!empty($options)) {
            $body['options'] = (object)$options;
        }
        return $this->request('POST', '/message/sendText/' . urlencode($this->inst()), [], $body);
    }

    /**
     * Enviar texto para grupo usando formato alternativo.
     * A Evolution API às vezes falha com sendText para grupos retornando "exists: false".
     * Este método tenta múltiplos formatos do JID.
     */
    public function sendTextToGroup(string $groupJid, string $text): array
    {
        // Formato 1: JID completo (padrão)
        $body = [
            'number' => $groupJid,
            'text' => $text,
        ];
        $res = $this->request('POST', '/message/sendText/' . urlencode($this->inst()), [], $body);
        
        $httpCode = (int)($res['status'] ?? 0);
        if ($httpCode >= 200 && $httpCode < 300) {
            return $res;
        }
        
        error_log("[EVOLUTION] sendTextToGroup: Formato 1 (number=$groupJid) falhou ($httpCode) - body: " . json_encode($res['json'] ?? $res['body_raw'] ?? ''));
        
        // Formato 2: Apenas o ID numérico sem @g.us
        // Algumas versões da API fazem append automático de @g.us
        $groupId = str_replace('@g.us', '', $groupJid);
        $body2 = [
            'number' => $groupId,
            'text' => $text,
        ];
        
        // Delay antes de tentar novamente
        usleep(500000); // 500ms
        
        $res2 = $this->request('POST', '/message/sendText/' . urlencode($this->inst()), [], $body2);
        
        $httpCode2 = (int)($res2['status'] ?? 0);
        if ($httpCode2 >= 200 && $httpCode2 < 300) {
            return $res2;
        }
        
        error_log("[EVOLUTION] sendTextToGroup: Formato 2 (number=$groupId) falhou ($httpCode2)");
        
        // Formato 3: Usando campo "options" com "delay" pode forçar novo lookup
        $body3 = [
            'number' => $groupJid,
            'text' => $text,
            'options' => (object)['delay' => 2000],
        ];
        
        usleep(500000); // 500ms
        
        $res3 = $this->request('POST', '/message/sendText/' . urlencode($this->inst()), [], $body3);
        
        $httpCode3 = (int)($res3['status'] ?? 0);
        if ($httpCode3 >= 200 && $httpCode3 < 300) {
            return $res3;
        }
        
        error_log("[EVOLUTION] sendTextToGroup: Formato 3 (number=$groupJid + delay) falhou ($httpCode3)");

        // Formato 4: Tentar com @lid (Linked ID) caso o JID seja nesse formato
        $groupIdOnly = str_replace(['@g.us', '@lid'], '', $groupJid);
        $lidJid = $groupIdOnly . '@lid';
        $body4 = [
            'number' => $lidJid,
            'text' => $text,
        ];
        
        usleep(500000); // 500ms
        
        $res4 = $this->request('POST', '/message/sendText/' . urlencode($this->inst()), [], $body4);
        
        $httpCode4 = (int)($res4['status'] ?? 0);
        if ($httpCode4 >= 200 && $httpCode4 < 300) {
            return $res4;
        }
        
        error_log("[EVOLUTION] sendTextToGroup: Formato 4 (number=$lidJid) falhou ($httpCode4)");
        
        error_log("[EVOLUTION] sendTextToGroup: TODOS OS FORMATOS FALHARAM para $groupJid");
        
        // Retornar o primeiro resultado (mais relevante)
        return $res;
    }

    public function sendMedia(string $number, string $mediaType, string $fileName, string $media, ?string $caption = null, array $options = []): array
    {
        $body = [
            'number' => $number,
            'mediatype' => $mediaType,
            'fileName' => $fileName,
            'media' => $media,
        ];
        if ($caption !== null && $caption !== '') {
            $body['caption'] = $caption;
        }

        return $this->request('POST', '/message/sendMedia/' . urlencode($this->inst()), [], $body);
    }

    public function sendContact(string $number, array $contactMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendContact/' . urlencode($this->inst()), [], [
            'number' => $number,
            'contactMessage' => $contactMessage,
            'options' => (object)$options,
        ]);
    }

    public function sendWhatsAppAudio(string $number, string $audio, array $options = []): array
    {
        return $this->request('POST', '/message/sendWhatsAppAudio/' . urlencode($this->inst()), [], [
            'number' => $number,
            'audio' => $audio,
        ]);
    }

    public function sendTemplate(string $number, array $templateMessage): array
    {
        return $this->request('POST', '/message/sendTemplate/' . urlencode($this->inst()), [], [
            'number' => $number,
            'templateMessage' => $templateMessage,
        ]);
    }

    public function sendStatus(array $statusMessage): array
    {
        return $this->request('POST', '/message/sendStatus/' . urlencode($this->inst()), [], [
            'statusMessage' => $statusMessage,
        ]);
    }

    public function sendLocation(string $number, array $locationMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendLocation/' . urlencode($this->inst()), [], [
            'number' => $number,
            'locationMessage' => $locationMessage,
            'options' => (object)$options,
        ]);
    }

    public function sendReaction(array $reactionMessage): array
    {
        return $this->request('POST', '/message/sendReaction/' . urlencode($this->inst()), [], [
            'reactionMessage' => $reactionMessage,
        ]);
    }

    public function sendSticker(string $number, string $image, array $options = []): array
    {
        return $this->request('POST', '/message/sendSticker/' . urlencode($this->inst()), [], [
            'number' => $number,
            'stickerMessage' => ['image' => $image],
            'options' => (object)$options,
        ]);
    }

    public function sendPoll(string $number, array $pollMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendPoll/' . urlencode($this->inst()), [], [
            'number' => $number,
            'pollMessage' => $pollMessage,
            'options' => (object)$options,
        ]);
    }

    public function sendList(string $number, array $listMessage, array $options = []): array
    {
        return $this->request('POST', '/message/sendList/' . urlencode($this->inst()), [], [
            'number' => $number,
            'options' => (object)$options,
            'listMessage' => $listMessage,
        ]);
    }

    // --------------------
    // Group Controller
    // --------------------

    public function fetchAllGroups(bool $getMembers = false): array
    {
        return $this->request(
            'GET',
            '/group/fetchAllGroups/' . urlencode($this->inst()),
            ['getMembers' => $getMembers ? 'true' : 'false']
        );
    }

    public function findGroupByJid(string $groupJid): array
    {
        return $this->request(
            'GET',
            '/group/findGroupInfos/' . urlencode($this->inst()),
            ['groupJid' => $groupJid]
        );
    }

    public function findGroupMembers(string $groupJid): array
    {
        return $this->request(
            'GET',
            '/group/participants/' . urlencode($this->inst()),
            ['groupJid' => $groupJid]
        );
    }

    public function createGroup(string $subject, array $participants, ?string $description = null): array
    {
        $body = [
            'subject' => $subject,
            'participants' => $participants,
        ];
        if ($description !== null && $description !== '') {
            $body['description'] = $description;
        }
        return $this->request('POST', '/group/create/' . urlencode($this->inst()), [], $body);
    }

    public function updateGroupMembers(string $groupJid, string $action, array $participants): array
    {
        return $this->request(
            'POST',
            '/group/updateParticipant/' . urlencode($this->inst()),
            [],
            [
                'groupJid' => $groupJid,
                'action' => $action,
                'participants' => $participants,
            ]
        );
    }

    public function updateGroupSetting(string $groupJid, string $action): array
    {
        // Evolution API v2.3.7 - Tentar múltiplos formatos de endpoint
        // Formato 1: PUT /group/updateSetting/{instance} (documentação oficial v2)
        $result = $this->request(
            'PUT',
            '/group/updateSetting/' . urlencode($this->inst()),
            [],
            ['groupJid' => $groupJid, 'action' => $action]
        );
        
        $httpCode = (int)($result['status'] ?? 0);
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("[EVOLUTION] updateGroupSetting OK com PUT /group/updateSetting (body)");
            return $result;
        }
        
        // Formato 2: PUT com groupJid como query param
        error_log("[EVOLUTION] Formato 1 falhou (HTTP $httpCode), tentando formato 2");
        $result2 = $this->request(
            'PUT',
            '/group/updateSetting/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            ['action' => $action]
        );
        
        $httpCode2 = (int)($result2['status'] ?? 0);
        if ($httpCode2 >= 200 && $httpCode2 < 300) {
            error_log("[EVOLUTION] updateGroupSetting OK com PUT /group/updateSetting (query)");
            return $result2;
        }
        
        // Formato 3: POST /group/updateSetting/{instance}
        error_log("[EVOLUTION] Formato 2 falhou (HTTP $httpCode2), tentando formato 3 (POST)");
        $result3 = $this->request(
            'POST',
            '/group/updateSetting/' . urlencode($this->inst()),
            [],
            ['groupJid' => $groupJid, 'action' => $action]
        );
        
        $httpCode3 = (int)($result3['status'] ?? 0);
        if ($httpCode3 >= 200 && $httpCode3 < 300) {
            error_log("[EVOLUTION] updateGroupSetting OK com POST /group/updateSetting");
            return $result3;
        }
        
        // Formato 4: PUT /group/setting/{instance}
        error_log("[EVOLUTION] Formato 3 falhou (HTTP $httpCode3), tentando formato 4");
        $result4 = $this->request(
            'PUT',
            '/group/setting/' . urlencode($this->inst()),
            [],
            ['groupJid' => $groupJid, 'action' => $action]
        );
        
        $httpCode4 = (int)($result4['status'] ?? 0);
        if ($httpCode4 >= 200 && $httpCode4 < 300) {
            error_log("[EVOLUTION] updateGroupSetting OK com PUT /group/setting");
            return $result4;
        }

        error_log("[EVOLUTION] TODOS OS FORMATOS FALHARAM para updateGroupSetting. Códigos: $httpCode, $httpCode2, $httpCode3, $httpCode4");
        return $result; // retorna o primeiro resultado
    }

    public function updateGroupSubject(string $groupJid, string $subject): array
    {
        return $this->request(
            'PUT',
            '/group/updateGroupSubject/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            ['subject' => $subject]
        );
    }

    public function updateGroupDescription(string $groupJid, string $description): array
    {
        return $this->request(
            'PUT',
            '/group/updateGroupDescription/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            ['description' => $description]
        );
    }

    public function updateGroupPicture(string $groupJid, string $imageUrl): array
    {
        return $this->request(
            'PUT',
            '/group/updateGroupPicture/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            ['image' => $imageUrl]
        );
    }

    public function fetchInviteCode(string $groupJid): array
    {
        return $this->request(
            'GET',
            '/group/inviteCode/' . urlencode($this->inst()),
            ['groupJid' => $groupJid]
        );
    }

    public function acceptInviteCode(string $inviteCode): array
    {
        return $this->request(
            'GET',
            '/group/acceptInviteCode/' . urlencode($this->inst()),
            ['inviteCode' => $inviteCode]
        );
    }

    public function revokeInviteCode(string $groupJid): array
    {
        return $this->request(
            'PUT',
            '/group/revokeInviteCode/' . urlencode($this->inst()),
            ['groupJid' => $groupJid]
        );
    }

    public function sendGroupInvite(string $groupJid, array $numbers, string $description): array
    {
        return $this->request('POST', '/group/sendInvite/' . urlencode($this->inst()), [], [
            'groupJid' => $groupJid,
            'numbers' => $numbers,
            'description' => $description,
        ]);
    }

    public function findGroupByInviteCode(string $inviteCode): array
    {
        return $this->request(
            'GET',
            '/group/inviteInfo/' . urlencode($this->inst()),
            ['inviteCode' => $inviteCode]
        );
    }

    public function leaveGroup(string $groupJid): array
    {
        return $this->request(
            'DELETE',
            '/group/leaveGroup/' . urlencode($this->inst()),
            ['groupJid' => $groupJid]
        );
    }

    public function toggleEphemeral(string $groupJid, int $expirationSeconds): array
    {
        return $this->request(
            'PUT',
            '/group/toggleEphemeral/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            ['expiration' => $expirationSeconds]
        );
    }

    // --------------------
    // Métodos auxiliares para envio de mídias
    // --------------------

    public function sendAudio(string $number, string $audioUrl, array $options = []): array
    {
        return $this->sendWhatsAppAudio($number, $audioUrl, $options);
    }

    public function sendImage(string $number, string $imageUrl, ?string $caption = null, array $options = []): array
    {
        $fileName = basename(parse_url($imageUrl, PHP_URL_PATH));
        return $this->sendMedia($number, 'image', $fileName, $imageUrl, $caption, $options);
    }

    public function sendVideo(string $number, string $videoUrl, ?string $caption = null, array $options = []): array
    {
        $fileName = basename(parse_url($videoUrl, PHP_URL_PATH));
        return $this->sendMedia($number, 'video', $fileName, $videoUrl, $caption, $options);
    }

    public function sendDocument(string $number, string $documentUrl, ?string $fileName = null, array $options = []): array
    {
        if ($fileName === null || $fileName === '') {
            $fileName = basename(parse_url($documentUrl, PHP_URL_PATH));
        }
        return $this->sendMedia($number, 'document', $fileName, $documentUrl, null, $options);
    }
}
