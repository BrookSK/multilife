<?php

declare(strict_types=1);

/**
 * Envia ao profissional o link de atualização da ficha cadastral por E-MAIL e por WHATSAPP.
 *
 * Gera/renova o registration_token, monta a URL pública /atualizar-cadastro?token=...
 * e dispara uma mensagem "bonita" pelos dois canais (o que estiver disponível).
 *
 * Reusa os helpers do projeto:
 *   - SmtpClient + email_base_layout() para o e-mail HTML;
 *   - EvolutionApiV1::sendText() para o WhatsApp (com a normalização padrão de telefone).
 */

require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/email_base_template.php';

auth_require_login();
rbac_require_permission('users.manage');

/**
 * Retorna uma instância EvolutionApiV1 CONECTADA (ou null se nenhuma).
 * Replica a seleção de instância do WhatsAppEventDispatcher: tenta a instância
 * padrão e, se não estiver conectada, procura outra ativa/conectada em
 * whatsapp_instances. Isso evita falha silenciosa quando a padrão está offline.
 */
function reg_link_pick_connected_instance(): ?EvolutionApiV1
{
    $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
    $apiKey = (string)admin_setting_get('evolution.api_key', '');
    $defaultInstance = (string)admin_setting_get('evolution.instance', '');

    if ($baseUrl === '' || $apiKey === '') {
        return null;
    }

    $isConnected = function (EvolutionApiV1 $api): bool {
        try {
            $res = $api->connectionState();
            $state = strtolower(trim((string)($res['json']['instance']['state'] ?? ($res['json']['state'] ?? ''))));
            return in_array($state, ['open', 'connected'], true);
        } catch (Throwable $e) {
            return false;
        }
    };

    // 1) Instância padrão.
    if ($defaultInstance !== '') {
        try {
            $api = new EvolutionApiV1($baseUrl, $apiKey, $defaultInstance);
            if ($isConnected($api)) {
                return $api;
            }
        } catch (Throwable $e) { /* tenta as demais */ }
    }

    // 2) Demais instâncias conectadas cadastradas.
    try {
        $stmt = db()->prepare("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' AND connection_status = 'connected' ORDER BY is_default DESC, id ASC LIMIT 5");
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $instName) {
            $instName = (string)$instName;
            if ($instName === '' || $instName === $defaultInstance) {
                continue;
            }
            try {
                $api = new EvolutionApiV1($baseUrl, $apiKey, $instName);
                if ($isConnected($api)) {
                    return $api;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
    } catch (Throwable $e) { /* tabela pode não existir em alguns ambientes */ }

    // 3) Último recurso: instância padrão sem checagem (pode falhar no envio).
    if ($defaultInstance !== '') {
        try {
            return new EvolutionApiV1($baseUrl, $apiKey, $defaultInstance);
        } catch (Throwable $e) {
            return null;
        }
    }

    return null;
}

$backUrl = '/professional_pre_registrations_list.php';

$userId = (int)($_POST['user_id'] ?? 0);
if ($userId <= 0) {
    flash_set('error', 'Profissional inválido.');
    header('Location: ' . $backUrl);
    exit;
}

// Garantir colunas de apoio (idempotente).
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
try { db()->exec("ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL"); } catch (Throwable $e) {}

$stmt = db()->prepare('SELECT id, name, email, phone, registration_token FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    flash_set('error', 'Profissional não encontrado.');
    header('Location: ' . $backUrl);
    exit;
}

$name = trim((string)($user['name'] ?? ''));
$email = trim((string)($user['email'] ?? ''));
$rawPhone = trim((string)($user['phone'] ?? ''));

// E-mail placeholder de pré-cadastro não é um destino real.
$emailIsReal = $email !== ''
    && filter_var($email, FILTER_VALIDATE_EMAIL)
    && !str_ends_with(strtolower($email), '@precadastro.local');

// Precisa de pelo menos um canal de contato.
if (!$emailIsReal && $rawPhone === '') {
    flash_set('error', 'Este profissional não tem e-mail nem telefone cadastrado. Edite o cadastro antes de enviar o link.');
    header('Location: ' . $backUrl);
    exit;
}

// Reaproveita token válido; senão gera um novo (mesmo critério de pre_admissao_approve.php).
$regToken = (string)($user['registration_token'] ?? '');
if ($regToken === '' || strlen($regToken) < 32) {
    $regToken = bin2hex(random_bytes(32));
    db()->prepare('UPDATE users SET registration_token = :t, registration_token_created_at = NOW() WHERE id = :id')
        ->execute(['t' => $regToken, 'id' => $userId]);
}

// Monta a URL pública.
$publicBaseUrl = trim((string)admin_setting_get('app.public_base_url', ''));
if ($publicBaseUrl === '') {
    $publicBaseUrl = trim((string)admin_setting_get('app.base_url', ''));
}
if ($publicBaseUrl === '') {
    $publicBaseUrl = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'multilife.onsolutionsbrasil.com.br');
}
$updateUrl = rtrim($publicBaseUrl, '/') . '/atualizar-cadastro?token=' . urlencode($regToken);

$firstName = $name !== '' ? explode(' ', $name)[0] : 'profissional';

// ------------------------------------------------------------------
// 1) E-MAIL (HTML bonito com o layout padrão MultiLife Care)
// ------------------------------------------------------------------
$emailOk = false;
$emailTried = false;
if ($emailIsReal) {
    $emailTried = true;
    try {
        $fromEmail = (string)admin_setting_get('smtp.out.from_email', '');
        $fromName = (string)admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        if ($fromEmail === '') {
            throw new RuntimeException('Remetente SMTP (smtp.out.from_email) não configurado.');
        }

        $safeName = htmlspecialchars($name !== '' ? $name : 'profissional', ENT_QUOTES);
        $safeUrl = htmlspecialchars($updateUrl, ENT_QUOTES);

        $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . $safeName . '</strong>! 👋</p>';
        $body .= '<p style="font-size:14px;color:#4b5563;line-height:1.7">Para mantermos sua ficha sempre atualizada na <strong>MultiLife Care</strong>, precisamos que você complete/atualize os seus dados cadastrais. É rápido e seguro, e ajuda a agilizar seus atendimentos e recebimentos.</p>';
        $body .= '<div style="text-align:center;margin:28px 0">';
        $body .= '<a href="' . $safeUrl . '" style="display:inline-block;background:#00a884;color:#ffffff;padding:14px 30px;border-radius:8px;font-weight:700;text-decoration:none;font-size:15px">Atualizar minha ficha cadastral</a>';
        $body .= '</div>';
        $body .= '<p style="font-size:13px;color:#6b7280">Se o botão não funcionar, copie e cole este link no seu navegador:<br><a href="' . $safeUrl . '" style="color:#0284c7;word-break:break-all">' . $safeUrl . '</a></p>';
        $body .= email_divider();
        $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';

        $htmlBody = email_base_layout('Atualização de Ficha Cadastral', $body, 'Este link é pessoal e intransferível.');

        $smtp = new SmtpClient();
        $smtp->send($fromEmail, $fromName, $email, 'Atualize sua ficha cadastral — MultiLife Care', $htmlBody);
        $emailOk = true;
    } catch (Throwable $e) {
        error_log('[REG_LINK_SEND] Erro ao enviar e-mail (user ' . $userId . '): ' . $e->getMessage());
    }
}

// ------------------------------------------------------------------
// 2) WHATSAPP (mensagem de texto via Evolution API)
// ------------------------------------------------------------------
$waOk = false;
$waTried = false;
if ($rawPhone !== '') {
    $waTried = true;
    try {
        // Normalização padrão do projeto (ver WhatsAppEventDispatcher::sendMessage).
        $phone = preg_replace('/[^0-9]/', '', $rawPhone);
        if ($phone !== '' && !str_starts_with($phone, '55')) {
            $phone = '55' . $phone;
        }
        if ($phone === '') {
            throw new RuntimeException('Telefone inválido após normalização.');
        }

        $waMsg = "Olá, {$firstName}! 👋\n\n";
        $waMsg .= "Aqui é a equipe *MultiLife Care*. 💚\n\n";
        $waMsg .= "Precisamos que você atualize a sua *ficha cadastral*. É rápido, seguro e ajuda a agilizar seus atendimentos e recebimentos.\n\n";
        $waMsg .= "👉 Acesse o link abaixo para atualizar seus dados:\n{$updateUrl}\n\n";
        $waMsg .= "Este link é pessoal e intransferível.\n\n";
        $waMsg .= "Obrigado! 🙏\nEquipe MultiLife Care";

        // Seleciona uma instância Evolution CONECTADA (mesma lógica do WhatsAppEventDispatcher).
        // Usar a instância padrão cegamente falha silenciosamente quando ela está desconectada.
        $wa = reg_link_pick_connected_instance();
        if ($wa === null) {
            throw new RuntimeException('Nenhuma instância de WhatsApp conectada disponível.');
        }
        $res = $wa->sendText($phone, $waMsg);
        $status = (int)($res['status'] ?? 0);
        $waOk = $status >= 200 && $status < 300;
        if (!$waOk) {
            error_log('[REG_LINK_SEND] WhatsApp HTTP ' . $status . ' (instância ' . $wa->getInstance() . ') para user ' . $userId
                . ' resp=' . json_encode($res['json'] ?? $res['body_raw'] ?? '', JSON_UNESCAPED_UNICODE));
        }
    } catch (Throwable $e) {
        error_log('[REG_LINK_SEND] Erro ao enviar WhatsApp (user ' . $userId . '): ' . $e->getMessage());
    }
}

audit_log('update', 'professional_registration_link_sent', (string)$userId, null, [
    'name' => $name,
    'email_ok' => $emailOk,
    'whatsapp_ok' => $waOk,
]);

// ------------------------------------------------------------------
// Mensagem de retorno para a equipe
// ------------------------------------------------------------------
$channels = [];
if ($emailOk) { $channels[] = 'e-mail'; }
if ($waOk) { $channels[] = 'WhatsApp'; }

if (!empty($channels)) {
    flash_set('success', 'Link de atualização cadastral enviado por ' . implode(' e ', $channels) . ' para ' . ($name !== '' ? $name : 'o profissional') . '.');
} else {
    $reasons = [];
    if ($emailTried) { $reasons[] = 'e-mail'; }
    if ($waTried) { $reasons[] = 'WhatsApp'; }
    $detail = !empty($reasons) ? (' Falha ao enviar por ' . implode(' e ', $reasons) . '.') : '';
    flash_set('error', 'Não foi possível enviar o link.' . $detail . ' Verifique as configurações de SMTP/WhatsApp e os dados de contato. O link continua disponível para cópia manual.');
}

header('Location: ' . $backUrl);
exit;
