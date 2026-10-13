<?php

declare(strict_types=1);

/**
 * Helper das Orientações por operadora (com variações) e da tabela de
 * padronização dos dias de atendimento.
 */

require_once __DIR__ . '/frequency_helper.php';

/**
 * Garante (idempotente) a tabela health_insurer_orientations e a coluna
 * patient_assignments.orientation_id.
 */
function insurer_orientations_ensure_schema(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS health_insurer_orientations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            health_insurer_id INT UNSIGNED NOT NULL,
            label VARCHAR(160) NOT NULL,
            body MEDIUMTEXT NOT NULL,
            requires_credentials TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_hio_insurer (health_insurer_id),
            KEY idx_hio_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[INSURER_ORIENTATIONS] ensure table: ' . $e->getMessage());
    }
    try {
        db()->exec("ALTER TABLE patient_assignments ADD COLUMN orientation_id BIGINT UNSIGNED NULL");
    } catch (Throwable $e) { /* já existe */ }
}

/**
 * Lista as variações de orientação de uma operadora.
 *
 * @return array<int,array<string,mixed>>
 */
function insurer_orientations_list(int $healthInsurerId, bool $onlyActive = true): array
{
    if ($healthInsurerId <= 0) {
        return [];
    }
    insurer_orientations_ensure_schema();
    $sql = "SELECT * FROM health_insurer_orientations WHERE health_insurer_id = :id";
    if ($onlyActive) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute(['id' => $healthInsurerId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Busca uma orientação pelo id.
 *
 * @return array<string,mixed>|null
 */
function insurer_orientation_get(int $orientationId): ?array
{
    if ($orientationId <= 0) {
        return null;
    }
    insurer_orientations_ensure_schema();
    $stmt = db()->prepare("SELECT * FROM health_insurer_orientations WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $orientationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * Processa o corpo da orientação substituindo placeholders de credenciais e
 * dados do atendimento. Placeholders suportados: {{login_externo}},
 * {{senha_externa}}, {{profissional_nome}}, {{paciente_nome}},
 * {{especialidade}}, {{tabela_dias}}.
 *
 * @param array<string,string> $vars
 */
function insurer_orientation_render(string $body, array $vars): string
{
    $map = [
        '{{login_externo}}'    => $vars['external_login'] ?? '',
        '{{senha_externa}}'    => $vars['external_password'] ?? '',
        '{{profissional_nome}}'=> $vars['professional_name'] ?? '',
        '{{paciente_nome}}'    => $vars['patient_name'] ?? '',
        '{{especialidade}}'    => $vars['specialty'] ?? '',
        '{{tabela_dias}}'      => $vars['attendance_days_table'] ?? '',
    ];
    $out = strtr($body, $map);
    // Remove placeholders não resolvidos.
    $out = preg_replace('/\{\{[^}]+\}\}/', '', $out);
    return trim((string)$out);
}

/**
 * Gera a tabela de padronização dos dias de atendimento em TEXTO (para WhatsApp).
 * Baseada no frequency_helper. Se $highlightCode for informado, marca a linha
 * correspondente à frequência do atendimento.
 */
function attendance_days_table_text(string $highlightCode = ''): string
{
    $rows = [];
    $rows[] = '📅 *Padronização dos dias de atendimento*';
    foreach (FREQUENCY_LABELS as $code => $label) {
        // Pula as frequências sem dia fixo da semana para o quadro principal,
        // mas inclui quinzenal/mensal com sua descrição.
        $desc = function_exists('frequency_get_weekday_description')
            ? frequency_get_weekday_description($code)
            : '';
        if ($desc === '' || $desc === '-') {
            continue;
        }
        $mark = ($highlightCode !== '' && $highlightCode === $code) ? '➡️ ' : '• ';
        $rows[] = $mark . $label . ': ' . $desc;
    }
    return implode("\n", $rows);
}

/**
 * Gera a tabela de padronização dos dias de atendimento em HTML (para e-mail /
 * página de documentos).
 */
function attendance_days_table_html(string $highlightCode = ''): string
{
    $html = '<table style="width:100%;border-collapse:collapse;font-size:13px">';
    $html .= '<thead><tr>'
        . '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e2e8f0">Frequência</th>'
        . '<th style="text-align:left;padding:6px 8px;border-bottom:2px solid #e2e8f0">Dias</th>'
        . '</tr></thead><tbody>';
    foreach (FREQUENCY_LABELS as $code => $label) {
        $desc = function_exists('frequency_get_weekday_description')
            ? frequency_get_weekday_description($code)
            : '';
        if ($desc === '' || $desc === '-') {
            continue;
        }
        $isHl = ($highlightCode !== '' && $highlightCode === $code);
        $rowStyle = $isHl ? 'background:#ecfdf5;font-weight:700' : '';
        $html .= '<tr style="' . $rowStyle . '">'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eef2f7">' . htmlspecialchars($label, ENT_QUOTES) . ($isHl ? ' ⬅' : '') . '</td>'
            . '<td style="padding:6px 8px;border-bottom:1px solid #eef2f7">' . htmlspecialchars($desc, ENT_QUOTES) . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}
