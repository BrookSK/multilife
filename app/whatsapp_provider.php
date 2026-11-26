<?php

declare(strict_types=1);

/**
 * Qual WhatsApp o sistema usa (Configurações → WhatsApp Conexão → "Integração"):
 *
 *  - baileys   : serviço ml-baileys-service desta pasta (Node + Baileys), padrão;
 *  - evolution : Evolution API v2 num servidor próprio (mesmo contrato de rotas e
 *                webhook que o Baileys, então nada muda no resto do sistema);
 *  - cloud     : API oficial do WhatsApp (Cloud API da Meta). Não existe servidor
 *                intermediário: app/whatsapp_cloud_gateway.php responde às MESMAS
 *                rotas que o resto do sistema já chama (sendText, group/create...)
 *                falando com a Graph API, e whatsapp_cloud_webhook.php converte o
 *                webhook da Meta para o formato que app/whatsapp_webhook_handler.php
 *                já entende.
 *
 * Gravado em admin_settings.whatsapp.provider.
 */

const WA_PROVIDERS = [
    'baileys' => 'Baileys (serviço próprio, QR Code)',
    'evolution' => 'Evolution API (servidor próprio, QR Code)',
    'cloud' => 'API oficial do WhatsApp (Meta)',
];

/** Endereço "falso" usado no modo API oficial: as chamadas não saem pela rede. */
const WA_CLOUD_BASE = 'cloud-gateway://local';

/** Integração em uso: baileys | evolution | cloud. */
function wa_provider(): string
{
    if (isset($GLOBALS['__wa_provider_cache']) && is_string($GLOBALS['__wa_provider_cache'])) {
        return $GLOBALS['__wa_provider_cache'];
    }
    $p = 'baileys';
    try {
        $v = strtolower(trim((string)admin_setting_get('whatsapp.provider', 'baileys')));
        if (isset(WA_PROVIDERS[$v])) {
            $p = $v;
        }
    } catch (Throwable $e) {
        // sem banco: padrão
    }
    return $GLOBALS['__wa_provider_cache'] = $p;
}

/** Esquece o valor lido (depois de salvar Configurações / em testes). */
function wa_provider_reset_cache(): void
{
    unset($GLOBALS['__wa_provider_cache']);
}

function wa_provider_label(?string $provider = null): string
{
    $provider = $provider ?? wa_provider();
    return WA_PROVIDERS[$provider] ?? $provider;
}

/** API oficial (Meta) em uso? */
function wa_is_cloud(): bool
{
    return wa_provider() === 'cloud';
}

/** Integrações com QR Code (Baileys/Evolution): conectar, desconectar, reconectar. */
function wa_provider_uses_qr(): bool
{
    return wa_provider() !== 'cloud';
}

/** O serviço ml-baileys-service deste sistema está em uso? */
function wa_provider_uses_service(): bool
{
    return wa_provider() === 'baileys';
}

/**
 * Recursos que mudam conforme a integração. As telas usam isto para esconder ou
 * explicar o que a API oficial não permite (ex.: adicionar alguém direto no grupo).
 */
function wa_provider_can(string $feature): bool
{
    if (!wa_is_cloud()) {
        return true;
    }
    $cloudBlocked = [
        'qr_code',            // número entra pelo painel da Meta, sem QR
        'logout',             // desconectar o número
        'group_add_members',  // só por link de convite
        'group_promote',      // tornar/remover administrador
        'group_announcement', // "somente administradores enviam"
        'group_leave',
        'delete_message',     // apagar para todos
        'edit_message',
        'history_sync',       // histórico antigo do celular
        'profile_picture',
        'poll',
        'status',
        'check_number',       // a API oficial não confere se o número tem WhatsApp
    ];
    return !in_array($feature, $cloudBlocked, true);
}

/** Texto curto explicando por que um recurso não existe na API oficial. */
function wa_provider_cannot_reason(string $feature): string
{
    $map = [
        'qr_code' => 'Na API oficial o número é conectado no painel da Meta (sem QR Code).',
        'logout' => 'Na API oficial o número não é desconectado pelo sistema: ele fica ativo na conta da Meta.',
        'group_add_members' => 'A API oficial não deixa adicionar pessoas direto no grupo: o sistema envia o link de convite para cada uma.',
        'group_promote' => 'A API oficial não permite tornar ou remover administradores do grupo.',
        'group_announcement' => 'A API oficial não tem a opção "somente administradores enviam".',
        'group_leave' => 'Na API oficial o número da empresa não sai do grupo; use "Excluir grupo".',
        'delete_message' => 'A API oficial não permite apagar mensagens já enviadas.',
        'edit_message' => 'A API oficial não permite editar mensagens já enviadas.',
        'history_sync' => 'A API oficial não traz o histórico antigo do celular: só as mensagens a partir da conexão.',
        'profile_picture' => 'A API oficial não mostra a foto de perfil dos contatos.',
        'poll' => 'A API oficial não envia enquetes.',
        'status' => 'A API oficial não publica status.',
        'check_number' => 'A API oficial não confere antes se o número tem WhatsApp; a falha aparece depois do envio.',
    ];
    return $map[$feature] ?? 'Recurso não disponível na API oficial do WhatsApp.';
}
