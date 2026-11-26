<?php

declare(strict_types=1);

/** Ações da tela API oficial do WhatsApp (admin_whatsapp_cloud.php). */

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin_whatsapp_cloud.php');
    exit;
}

wa_cloud_ensure_schema();
$action = (string)($_POST['action'] ?? '');
$back = static function (string $type, string $msg, string $anchor = ''): void {
    flash_set($type, $msg);
    header('Location: /admin_whatsapp_cloud.php' . $anchor);
    exit;
};

try {
    switch ($action) {
        case 'save_credentials':
            $waba = (string)preg_replace('/\D+/', '', (string)($_POST['waba_id'] ?? ''));
            wa_cloud_setting_save('waba_id', $waba);
            $ver = trim((string)($_POST['graph_version'] ?? ''));
            wa_cloud_setting_save('graph_version', preg_match('/^v\d{1,3}\.\d$/', $ver) ? $ver : WA_CLOUD_DEFAULT_VERSION);
            $token = trim((string)($_POST['access_token'] ?? ''));
            if ($token !== '') {
                wa_cloud_setting_save('access_token', $token, true);
            }
            $secret = trim((string)($_POST['app_secret'] ?? ''));
            if ($secret !== '') {
                wa_cloud_setting_save('app_secret', $secret, true);
            }
            audit_log('update', 'whatsapp_cloud', null, null, ['waba' => $waba, 'token' => $token !== '' ? 'alterado' : 'mantido', 'secret' => $secret !== '' ? 'alterado' : 'mantido']);
            $h = wa_cloud_health(10);
            if ($h['ok']) {
                wa_cloud_templates_sync();
                $back('success', 'Credenciais salvas. Conta conectada: ' . (string)($h['data']['name'] ?? $waba) . '.');
            }
            $back('error', 'Credenciais salvas, mas a Meta recusou: ' . $h['error']);
            break;

        case 'save_webhook_url':
            $url = trim((string)($_POST['webhook_url'] ?? ''));
            if ($url !== '' && !preg_match('#^https://#i', $url)) {
                $back('error', 'O endereço do webhook precisa começar com https:// (exigência da Meta).');
            }
            wa_cloud_setting_save('webhook_url', $url);
            $back('success', 'Endereço do webhook salvo. Atualize também no app da Meta.');
            break;

        case 'subscribe':
            $r = wa_cloud_subscribe_app();
            $r['ok'] ? $back('success', 'App inscrito na conta: a Meta passa a mandar os eventos para o webhook.') : $back('error', 'Não foi possível inscrever o app: ' . $r['error']);
            break;

        case 'link_number':
            $pnid = (string)preg_replace('/\D+/', '', (string)($_POST['phone_number_id'] ?? ''));
            $name = trim((string)($_POST['instance_name'] ?? ''));
            $info = wa_cloud_phone_info($pnid);
            if (!wa_cloud_ok($info)) {
                $back('error', 'A Meta não encontrou esse número: ' . wa_cloud_error_text($info));
            }
            $r = wa_cloud_instance_save($pnid, $name, (array)$info['json'], auth_user_id());
            if (!$r['ok']) {
                $back('error', $r['error']);
            }
            if (wa_default_instance() === '' || wa_cloud_instance(wa_default_instance()) === null) {
                wa_setting_save('baileys.instance', $r['instance']);
                db()->prepare('UPDATE whatsapp_instances SET is_default = (instance_name = ?) WHERE status = \'active\'')->execute([$r['instance']]);
            }
            audit_log('create', 'whatsapp_cloud_number', $r['instance'], null, ['phone_number_id' => $pnid]);
            $back('success', 'Número ' . (string)($info['json']['display_phone_number'] ?? '') . ' cadastrado como "' . $r['instance'] . '". Vincule aos usuários em Configurações → WhatsApp Conexão → Instâncias.');
            break;

        case 'unlink_number':
            $name = trim((string)($_POST['instance'] ?? ''));
            db()->prepare("UPDATE whatsapp_instances SET status = 'inactive', is_default = 0, cloud_phone_number_id = NULL WHERE instance_name = ?")->execute([$name]);
            if (wa_default_instance() === $name) {
                wa_setting_save('baileys.instance', '');
            }
            audit_log('delete', 'whatsapp_cloud_number', $name, null, null);
            $back('success', 'Número "' . $name . '" tirado do sistema (as conversas continuam guardadas).');
            break;

        case 'set_default':
            $name = trim((string)($_POST['instance'] ?? ''));
            if (wa_cloud_instance($name) === null) {
                $back('error', 'Número não encontrado.');
            }
            wa_setting_save('baileys.instance', $name);
            db()->prepare('UPDATE whatsapp_instances SET is_default = (instance_name = ?) WHERE status = \'active\'')->execute([$name]);
            $back('success', '"' . $name . '" agora é o WhatsApp padrão das mensagens automáticas.');
            break;

        case 'register_phone':
            $pnid = (string)preg_replace('/\D+/', '', (string)($_POST['phone_number_id'] ?? ''));
            $pin = (string)preg_replace('/\D+/', '', (string)($_POST['pin'] ?? ''));
            if (strlen($pin) !== 6) {
                $back('error', 'O PIN tem 6 dígitos.');
            }
            $r = wa_cloud_register_phone($pnid, $pin);
            $r['ok'] ? $back('success', 'Número registrado na Cloud API.') : $back('error', 'A Meta não registrou o número: ' . $r['error']);
            break;

        case 'sync_templates':
            $r = wa_cloud_templates_sync();
            $r['ok'] ? $back('success', $r['count'] . ' modelo(s) atualizado(s) da Meta.', '#modelos') : $back('error', 'Não foi possível ler os modelos: ' . $r['error'], '#modelos');
            break;

        case 'save_purposes':
            foreach (array_keys(WA_CLOUD_PURPOSES) as $purpose) {
                $v = trim((string)($_POST['tpl'][$purpose] ?? ''));
                wa_cloud_setting_save('tpl.' . $purpose, preg_match('/^[a-z0-9_]+\|[A-Za-z_]{2,10}$/', $v) ? $v : '');
            }
            $back('success', 'Modelos de cada uso salvos.', '#modelos');
            break;

        case 'create_template':
            $purpose = (string)($_POST['purpose'] ?? '');
            $name = strtolower(trim((string)($_POST['name'] ?? '')));
            $cat = strtoupper((string)($_POST['category'] ?? 'UTILITY')) === 'MARKETING' ? 'MARKETING' : 'UTILITY';
            if (!isset(WA_CLOUD_PURPOSES[$purpose]) || !preg_match('/^[a-z0-9_]{3,100}$/', $name)) {
                $back('error', 'Nome de modelo inválido (use letras minúsculas, números e _).', '#modelos');
            }
            $r = wa_cloud_template_create_standard($purpose, wa_cloud_setting('default_lang', 'pt_BR'), $name, $cat);
            $r['ok'] ? $back('success', 'Modelo "' . $r['name'] . '" enviado para aprovação da Meta. Ele já fica escolhido; a situação muda para "Aprovado" sozinha (ou clique em "Atualizar modelos da Meta").', '#modelos')
                : $back('error', 'A Meta recusou o modelo "' . $r['name'] . '": ' . $r['error'], '#modelos');
            break;

        case 'save_captacao_mode':
            wa_cloud_setting_save('captacao_mode', (string)($_POST['captacao_mode'] ?? 'direct') === 'group' ? 'group' : 'direct');
            $back('success', 'Forma de envio da captação salva.');
            break;

        case 'send_test':
            $inst = trim((string)($_POST['instance'] ?? ''));
            $to = (string)preg_replace('/\D+/', '', (string)($_POST['to'] ?? ''));
            $text = trim((string)($_POST['text'] ?? ''));
            if (wa_cloud_instance($inst) === null || strlen($to) < 10 || $text === '') {
                $back('error', 'Escolha o número, informe o destino com DDD e a mensagem.');
            }
            $res = wa_cloud_gateway('POST', '/message/sendText/' . rawurlencode($inst), [], ['number' => $to, 'text' => $text], 30);
            $ok = (int)$res['status'] >= 200 && (int)$res['status'] < 300;
            $via = (string)($res['json']['cloud']['via'] ?? '');
            $_SESSION['wa_cloud_test_result'] = ['ok' => $ok, 'message' => $ok
                ? 'Mensagem aceita pela Meta (' . (str_starts_with($via, 'template') ? 'enviada pelo modelo ' . substr($via, 9) . ', porque o contato está fora das 24 h' : 'mensagem livre, contato dentro das 24 h') . '). A entrega aparece em Eventos do WhatsApp.'
                : 'Não enviada: ' . wa_api_error_message($res)];
            header('Location: /admin_whatsapp_cloud.php');
            exit;

        case 'set_provider':
            $p = (string)($_POST['provider'] ?? '');
            if (!isset(WA_PROVIDERS[$p])) {
                $back('error', 'Integração desconhecida.');
            }
            wa_setting_save('whatsapp.provider', $p);
            wa_provider_reset_cache();
            audit_log('update', 'admin_settings', 'whatsapp.provider', null, ['provider' => $p]);
            $back('success', 'Integração do WhatsApp agora: ' . wa_provider_label($p) . '.');
            break;
    }
} catch (Throwable $e) {
    error_log('[WA_CLOUD_POST] ' . $action . ': ' . $e->getMessage());
    $back('error', 'Erro: ' . $e->getMessage());
}

$back('error', 'Ação desconhecida.');
