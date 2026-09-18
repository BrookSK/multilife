<?php

declare(strict_types=1);

/**
 * Helpers de links PÚBLICOS (sem login) enviados aos profissionais.
 *
 * O profissional não tem acesso ao painel interno. Portanto, qualquer link
 * enviado em notificações (WhatsApp/e-mail) deve ser uma rota pública acessível
 * por token — nunca uma página que exige login (ex.: /monitoramento,
 * /profissional_registros.php, /dashboard).
 *
 * Hoje a única rota pública por token voltada ao profissional é a de atualização
 * cadastral: /atualizar-cadastro?token=... (complete_registration.php).
 */

/**
 * Retorna a base URL pública do sistema (sem barra final).
 */
function public_base_url(): string
{
    $url = trim((string)admin_setting_get('app.public_base_url', ''));
    if ($url === '') {
        $url = trim((string)admin_setting_get('app.base_url', ''));
    }
    if ($url === '') {
        $url = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'multilife.onsolutionsbrasil.com.br');
    }
    return rtrim($url, '/');
}

/**
 * Gera (ou reaproveita) o token de atualização cadastral do profissional e
 * devolve a URL pública /atualizar-cadastro?token=...
 *
 * Reaproveita o registration_token válido existente; caso não exista, cria um
 * novo. Retorna string vazia se o usuário for inválido ou em caso de erro.
 *
 * @param int $userId ID do profissional (tabela users).
 */
function professional_registration_link(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    try {
        $db = db();

        // Garantir colunas de apoio (idempotente) — mesmo padrão do restante do fluxo.
        try { $db->exec("ALTER TABLE users ADD COLUMN registration_token VARCHAR(64) NULL"); } catch (Throwable $e) {}
        try { $db->exec("ALTER TABLE users ADD COLUMN registration_token_created_at DATETIME NULL"); } catch (Throwable $e) {}

        // Reaproveita token válido; senão gera um novo.
        $stmt = $db->prepare('SELECT registration_token FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $regToken = (string)($stmt->fetchColumn() ?: '');

        if ($regToken === '' || strlen($regToken) < 32) {
            $regToken = bin2hex(random_bytes(32));
            $db->prepare('UPDATE users SET registration_token = :t, registration_token_created_at = NOW() WHERE id = :id')
                ->execute(['t' => $regToken, 'id' => $userId]);
        }

        return public_base_url() . '/atualizar-cadastro?token=' . urlencode($regToken);
    } catch (Throwable $e) {
        error_log('[PROFESSIONAL_LINKS] Erro ao gerar link público (user ' . $userId . '): ' . $e->getMessage());
        return '';
    }
}

/**
 * Indica se o link de acesso ao portal deve ser incluído nas notificações.
 * Controlado pela flag "Enviar link de acesso ao portal nas notificações".
 */
function notifications_should_include_portal_link(): bool
{
    return (string)admin_setting_get('feature.enviar_link_portal_notificacoes', '0') === '1';
}
