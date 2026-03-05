<?php

declare(strict_types=1);

final class EvolutionApiV1
{
    private string $baseUrl;
    private string $apiKey;
    private string $instance;

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
        return $this->request('POST', '/message/sendText/' . urlencode($this->inst()), [], [
            'number' => $number,
            'textMessage' => ['text' => $text],
            'options' => (object)$options,
        ]);
    }

    public function sendMedia(string $number, string $mediaType, string $fileName, string $media, ?string $caption = null, array $options = []): array
    {
        $msg = [
            'mediaType' => $mediaType,
            'fileName' => $fileName,
            'media' => $media,
        ];
        if ($caption !== null && $caption !== '') {
            $msg['caption'] = $caption;
        }

        return $this->request('POST', '/message/sendMedia/' . urlencode($this->inst()), [], [
            'number' => $number,
            'mediaMessage' => $msg,
            'options' => (object)$options,
        ]);
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
            'audioMessage' => ['audio' => $audio],
            'options' => (object)$options,
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
            'PUT',
            '/group/updateParticipant/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            [
                'action' => $action,
                'participants' => $participants,
            ]
        );
    }

    public function updateGroupSetting(string $groupJid, string $action): array
    {
        return $this->request(
            'PUT',
            '/group/updateSetting/' . urlencode($this->inst()),
            ['groupJid' => $groupJid],
            ['action' => $action]
        );
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
}
