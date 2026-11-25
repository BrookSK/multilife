<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

auth_require_login();
rbac_require_permission('admin.settings.manage');

$settings = $_POST['settings'] ?? [];
if (!is_array($settings)) {
    $settings = [];
}

// Checkboxes não enviados quando desmarcados — garantir valor "0" para campos de habilitação
$checkboxDefaults = [
    'council_provider.consultario.enabled',
    'council_provider.infosimples.enabled',
    'council_provider.portal_direct.enabled',
];
foreach ($checkboxDefaults as $cbKey) {
    if (!isset($settings[$cbKey]) && isset($_POST['settings']) && is_array($_POST['settings'])) {
        // Só define "0" se a aba de Consultas API foi submetida (alguma key council_provider.* presente)
        $hasCouncilKeys = false;
        foreach (array_keys($settings) as $sk) {
            if (str_starts_with((string)$sk, 'council_provider.')) {
                $hasCouncilKeys = true;
                break;
            }
        }
        if ($hasCouncilKeys) {
            $settings[$cbKey] = '0';
        }
    }
}

// Endereço do webhook antes de salvar (se mudar, os números conectados são avisados)
$webhookBefore = function_exists('whatsapp_webhook_public_url') ? whatsapp_webhook_public_url() : '';

$db = db();
$db->beginTransaction();
try {
    $stmt = $db->prepare('INSERT INTO admin_settings (setting_key, setting_value, updated_by_user_id) VALUES (:k, :v, :uid) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by_user_id = VALUES(updated_by_user_id)');

    foreach ($settings as $k => $v) {
        $key = trim((string)$k);
        $val = trim((string)$v);
        if ($key === '') {
            continue;
        }

        if (in_array($key, ['cron.token', 'smtp.in.password', 'smtp.out.password', 'baileys.api_key', 'council_provider.consultario.api_key', 'council_provider.infosimples.api_token'], true) && $val === '') {
            continue;
        }
        $stmt->execute(['k' => $key, 'v' => $val, 'uid' => auth_user_id()]);
    }

    audit_log('update', 'admin_settings', null, null, ['count' => count($settings)]);

    $db->commit();
} catch (Throwable $e) {
    $db->rollBack();
    throw $e;
}

// Serviço WhatsApp lê chave, URL e retenção do runtime-config.php
if (function_exists('wa_service_runtime_sync')) {
    wa_service_runtime_sync(true);
}

// Endereço do webhook mudou (domínio novo): grava o novo em todos os números
$webhookAfter = function_exists('whatsapp_webhook_public_url') ? whatsapp_webhook_public_url() : '';
if ($webhookAfter !== '' && $webhookAfter !== $webhookBefore && function_exists('whatsapp_webhook_configure')) {
    @set_time_limit(120);
    $okCount = 0;
    $total = 0;
    try {
        foreach (whatsapp_list_all_instances() as $wi) {
            $name = (string)($wi['instance_name'] ?? '');
            if ($name === '') {
                continue;
            }
            $total++;
            if (whatsapp_webhook_configure($name, $webhookAfter)) {
                $okCount++;
            }
        }
    } catch (Throwable $e) {
        error_log('[SETTINGS] webhook novo: ' . $e->getMessage());
    }
    if ($total > 0) {
        flash_set('success', 'Configurações salvas. Endereço do webhook atualizado em ' . $okCount . ' de ' . $total . ' WhatsApp(s).');
        header('Location: /admin_settings.php');
        exit;
    }
}

// Confere se cada número entrega no endereço de webhook salvo (corrige o que estiver diferente)
$heal = function_exists('wa_webhook_self_heal') ? wa_webhook_self_heal(true) : ['fixed' => 0, 'failed' => 0];
if (($heal['fixed'] ?? 0) > 0 || ($heal['failed'] ?? 0) > 0) {
    flash_set(($heal['failed'] ?? 0) > 0 ? 'error' : 'success', 'Configurações salvas. Endereço do webhook corrigido em ' . (int)$heal['fixed'] . ' número(s)'
        . (($heal['failed'] ?? 0) > 0 ? '; ' . (int)$heal['failed'] . ' não aceitaram a troca (veja Eventos do WhatsApp)' : '') . '.');
    header('Location: /admin_settings.php');
    exit;
}

flash_set('success', 'Configurações salvas.');
header('Location: /admin_settings.php');
exit;
