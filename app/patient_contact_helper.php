<?php

declare(strict_types=1);

/**
 * Endereço e contatos do paciente formatados para mensagens e telas.
 *
 * Usado na mensagem ao profissional (placeholders {{paciente_endereco}} e
 * {{paciente_contatos}}), no e-mail ao profissional, na pré-admissão e no
 * monitoramento. "Contato da família" = campos emergency_* do cadastro
 * (preenchidos no cadastro rápido da aba Autorização ou no cadastro completo).
 */

/** Colunas de patients usadas pelas funções abaixo (para montar o SELECT). */
const PATIENT_CONTACT_COLUMNS = 'id, full_name, whatsapp, phone_primary, phone_secondary,
    address_zip, address_street, address_number, address_complement, address_neighborhood,
    address_city, address_state, emergency_name, emergency_relationship, emergency_phone';

/** Busca os dados de contato do paciente (array vazio se não encontrado). */
function patient_contact_fetch(int $patientId): array
{
    if ($patientId <= 0) {
        return [];
    }
    try {
        $st = db()->prepare('SELECT ' . PATIENT_CONTACT_COLUMNS . ' FROM patients WHERE id = :id LIMIT 1');
        $st->execute(['id' => $patientId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('[PATIENT_CONTACT] ' . $e->getMessage());
        return [];
    }
}

/** "Rua X, 123, Apto 4, Bairro, Cidade/UF, CEP 15000-000" (vazio se não houver nada). */
function patient_format_address(array $p): string
{
    $t = static fn(string $k): string => trim((string)($p[$k] ?? ''));
    $street = $t('address_street');
    if ($street !== '' && $t('address_number') !== '') {
        $street .= ', ' . $t('address_number');
    }
    $cityUf = $t('address_city');
    if ($t('address_state') !== '') {
        $cityUf .= ($cityUf !== '' ? '/' : '') . strtoupper($t('address_state'));
    }
    $zip = preg_replace('/\D+/', '', $t('address_zip'));
    if (strlen($zip) === 8) {
        $zip = substr($zip, 0, 5) . '-' . substr($zip, 5);
    }
    $parts = array_filter([
        $street,
        $t('address_complement'),
        $t('address_neighborhood'),
        $cityUf,
        $zip !== '' ? 'CEP ' . $zip : '',
    ], static fn(string $s): bool => $s !== '');
    return implode(', ', $parts);
}

/** Telefones do próprio paciente, sem repetir ("17 99999-0000 / 17 3333-0000"). */
function patient_format_phones(array $p): string
{
    $seen = [];
    $out = [];
    foreach (['whatsapp', 'phone_primary', 'phone_secondary'] as $k) {
        $v = trim((string)($p[$k] ?? ''));
        $digits = preg_replace('/\D+/', '', $v);
        if ($digits === '' || isset($seen[$digits])) {
            continue;
        }
        $seen[$digits] = true;
        $out[] = $v;
    }
    return implode(' / ', $out);
}

/** Contato da família: "Maria (filha): 17 99999-0000" (vazio se não houver). */
function patient_format_family(array $p): string
{
    $name = trim((string)($p['emergency_name'] ?? ''));
    $rel = trim((string)($p['emergency_relationship'] ?? ''));
    $phone = trim((string)($p['emergency_phone'] ?? ''));
    if ($name === '' && $phone === '') {
        return '';
    }
    $label = $name !== '' ? $name : 'Família';
    if ($rel !== '') {
        $label .= ' (' . $rel . ')';
    }
    return $phone !== '' ? $label . ': ' . $phone : $label;
}

/** Bloco com os dados do paciente incluído no texto "Pré-admissão aprovada" ao profissional. */
const PREADMISSION_PATIENT_BLOCK = "📍 *Dados do paciente:*\n• Endereço: {{paciente_endereco}}\n• Contatos: {{paciente_contatos}}\n• Agendamento: {{agendamento}}";

/**
 * Inclui (uma única vez) o bloco de endereço/contatos/agendamento no texto do
 * evento 'preadmission_approved' ao profissional, logo depois dos detalhes.
 * Não mexe em textos que já usam {{paciente_endereco}} e não roda de novo depois
 * (flag em admin_settings), então uma edição posterior no painel é respeitada.
 * Mesmo efeito da migration 2026-11-17_0002_preadmission_patient_block.sql.
 */
function preadmission_template_ensure_patient_block(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        if ((string)admin_setting_get('whatsapp.preadmission_patient_block', '0') === '1') {
            return;
        }
        $db = db();
        $rows = $db->query("SELECT id, template_professional FROM whatsapp_events
            WHERE system_event = 'preadmission_approved' AND template_professional IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $db->prepare('UPDATE whatsapp_events SET template_professional = :t WHERE id = :id');
        foreach ($rows as $r) {
            $tpl = (string)$r['template_professional'];
            if (str_contains($tpl, '{{paciente_endereco}}')) {
                continue;
            }
            $inserted = false;
            foreach (['{{data_aprovacao}}', '{{especialidade}}', '{{paciente_nome}}'] as $anchor) {
                $pos = strpos($tpl, $anchor);
                if ($pos === false) {
                    continue;
                }
                $eol = strpos($tpl, "\n", $pos);
                $tpl = $eol === false
                    ? $tpl . "\n\n" . PREADMISSION_PATIENT_BLOCK
                    : substr($tpl, 0, $eol) . "\n\n" . PREADMISSION_PATIENT_BLOCK . substr($tpl, $eol);
                $inserted = true;
                break;
            }
            if (!$inserted) {
                $tpl = rtrim($tpl) . "\n\n" . PREADMISSION_PATIENT_BLOCK;
            }
            $upd->execute(['t' => $tpl, 'id' => (int)$r['id']]);
        }
        $db->prepare("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('whatsapp.preadmission_patient_block', '1')
            ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
    } catch (Throwable $e) {
        error_log('[PREADMISSION_TEMPLATE] ' . $e->getMessage());
    }
}

/**
 * Resumo do envio ao profissional para a mensagem de sucesso da pré-admissão
 * (confirma se a mensagem e os anexos saíram).
 */
function preadmission_dispatch_summary(array $dispatchResult): string
{
    if (empty($dispatchResult['success'])) {
        return 'Atenção: a mensagem ao profissional não foi enviada (' . (string)($dispatchResult['error'] ?? 'erro desconhecido') . ').';
    }
    $prof = $dispatchResult['results']['professional'] ?? null;
    if (!is_array($prof)) {
        return 'Atenção: a mensagem ao profissional não foi enviada pelo WhatsApp (profissional sem telefone ou envio desativado no evento).';
    }
    if (!empty($prof['blocked'])) {
        return 'Atenção: envio ao profissional bloqueado (' . (string)($prof['error'] ?? '') . ').';
    }
    $msg = !empty($prof['success'])
        ? 'Mensagem enviada ao profissional pelo WhatsApp'
        : 'Atenção: falha ao enviar a mensagem ao profissional pelo WhatsApp';
    $files = $prof['files'] ?? ['total' => 0];
    if ((int)($files['total'] ?? 0) > 0) {
        $msg .= ', ' . (int)$files['sent'] . ' de ' . (int)$files['total'] . ' arquivo(s) enviados';
        if ((int)$files['failed'] > 0) {
            $msg .= ' (' . (int)$files['failed'] . ' falharam, veja o log de mensagens WhatsApp)';
        }
    }
    return $msg . '.';
}

/**
 * Texto do placeholder {{paciente_contatos}}:
 * "Paciente: 17 99999-0000 | Família - Maria (filha): 17 98888-0000".
 */
function patient_format_contacts(array $p): string
{
    $parts = [];
    $phones = patient_format_phones($p);
    if ($phones !== '') {
        $parts[] = 'Paciente: ' . $phones;
    }
    $family = patient_format_family($p);
    if ($family !== '') {
        $parts[] = 'Família - ' . $family;
    }
    return implode(' | ', $parts);
}
