<?php

declare(strict_types=1);

/**
 * Processador de Templates de E-mail HTML
 * Busca, processa variáveis e envia e-mails personalizados
 */

/**
 * Buscar template por tipo de evento e operadora
 */
function email_get_template(string $eventType, ?int $healthInsurerId = null): ?array
{
    $sql = 'SELECT id, name, subject, body_html, body_plain 
            FROM email_templates 
            WHERE event_type = :event AND is_active = 1';
    
    $params = ['event' => $eventType];
    
    if ($healthInsurerId !== null) {
        $sql .= ' AND health_insurer_id = :insurer_id';
        $params['insurer_id'] = $healthInsurerId;
    } else {
        $sql .= ' AND health_insurer_id IS NULL';
    }
    
    $sql .= ' LIMIT 1';
    
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $template = $stmt->fetch();
    
    return $template ?: null;
}

/**
 * Processar variáveis no template (HTML e texto)
 */
function email_process_variables(string $content, array $variables): string
{
    $processed = $content;
    
    foreach ($variables as $key => $value) {
        $placeholder = '{' . $key . '}';
        $processed = str_replace($placeholder, (string)$value, $processed);
    }
    
    return $processed;
}

/**
 * Enviar e-mail com template
 * 
 * @param string $toEmail E-mail do destinatário
 * @param string $eventType Tipo de evento (proposal_send, proposal_resend)
 * @param array $variables Variáveis para substituir no template
 * @param int|null $healthInsurerId ID da operadora (opcional)
 * @return array ['success' => bool, 'message' => string, 'template_id' => int|null]
 */
function email_send_with_template(
    string $toEmail,
    string $eventType,
    array $variables,
    ?int $healthInsurerId = null
): array {
    // 1. Buscar template
    $template = email_get_template($eventType, $healthInsurerId);
    
    if (!$template) {
        // Fallback: gerar template padrão para proposal_send
        if ($eventType === 'proposal_send') {
            $template = [
                'id' => 0,
                'subject' => 'Proposta de Atendimento - ' . ($variables['patient_name'] ?? 'Paciente'),
                'body_html' => email_generate_default_proposal_html($variables),
                'body_plain' => null,
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Template não encontrado para evento: ' . $eventType,
                'template_id' => null
            ];
        }
    }
    
    $templateId = (int)$template['id'];
    
    // 2. Processar variáveis no assunto
    $subject = email_process_variables($template['subject'], $variables);
    
    // 3. Processar variáveis no corpo HTML
    $bodyHtml = email_process_variables($template['body_html'], $variables);
    
    // 4. Processar variáveis no corpo texto (fallback)
    $bodyPlain = $template['body_plain'] 
        ? email_process_variables($template['body_plain'], $variables)
        : strip_tags($bodyHtml);
    
    // 5. Enviar e-mail
    try {
        $smtp = new SmtpClient();
        $fromEmail = admin_setting_get('smtp.out.from_email', 'noreply@multilife.com.br');
        $fromName = admin_setting_get('smtp.out.from_name', 'MultiLife Care');
        
        // SmtpClient suporta HTML se body começar com <!DOCTYPE ou <html
        $messageId = $smtp->send($fromEmail, $fromName, $toEmail, $subject, $bodyHtml);
        
        return [
            'success' => true,
            'message' => 'E-mail enviado com sucesso',
            'template_id' => $templateId,
            'message_id' => $messageId
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'Erro ao enviar e-mail: ' . $e->getMessage(),
            'template_id' => $templateId
        ];
    }
}

/**
 * Gerar HTML padrão para proposta quando template não existe no banco
 */
function email_generate_default_proposal_html(array $vars): string
{
    $patientName = $vars['patient_name'] ?? '';
    $patientEmail = $vars['patient_email'] ?? '';
    $patientPhone = $vars['patient_phone'] ?? '';
    $professionalName = $vars['professional_name'] ?? '';
    $professionalEmail = $vars['professional_email'] ?? '';
    $professionalPhone = $vars['professional_phone'] ?? '';
    $professionalCouncil = $vars['professional_council'] ?? '';
    $specialty = $vars['specialty'] ?? '';
    $serviceName = $vars['service_name'] ?? '';
    $location = $vars['location'] ?? '';
    $startDate = $vars['start_date'] ?? '';
    $startTime = $vars['start_time'] ?? '';
    $endTime = $vars['end_time'] ?? '';
    $frequencyText = $vars['frequency_text'] ?? '';
    $totalSessions = $vars['total_sessions'] ?? '';
    $durationWeeks = $vars['duration_weeks'] ?? '';
    $valuePerSession = $vars['value_per_session'] ?? '';
    $totalValue = $vars['total_value'] ?? '';
    $sessionSchedule = $vars['session_schedule'] ?? '';
    $notesSection = $vars['notes_section'] ?? '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;margin:0;padding:0">
<div style="max-width:700px;margin:0 auto;padding:20px">
<div style="background:#00a884;color:white;padding:24px;text-align:center;border-radius:8px 8px 0 0">
<h1 style="margin:0;font-size:22px">Proposta de Atendimento</h1>
<p style="margin:8px 0 0;opacity:0.9">MultiLife Care</p>
</div>
<div style="background:#f9fafb;padding:30px;border:1px solid #e5e7eb;border-top:none">
<p>Prezado(a),</p>
<p>Segue proposta de atendimento domiciliar para análise e autorização:</p>

<div style="background:white;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #00a884">
<h3 style="margin:0 0 12px;color:#00a884">Dados do Paciente</h3>
<p style="margin:4px 0"><strong>Nome:</strong> ' . htmlspecialchars($patientName) . '</p>
<p style="margin:4px 0"><strong>E-mail:</strong> ' . htmlspecialchars($patientEmail) . '</p>
<p style="margin:4px 0"><strong>Telefone:</strong> ' . htmlspecialchars($patientPhone) . '</p>
<p style="margin:4px 0"><strong>Localização:</strong> ' . htmlspecialchars($location) . '</p>
</div>

<div style="background:white;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #0284c7">
<h3 style="margin:0 0 12px;color:#0284c7">Profissional Designado</h3>
<p style="margin:4px 0"><strong>Nome:</strong> ' . htmlspecialchars($professionalName) . '</p>
<p style="margin:4px 0"><strong>Especialidade:</strong> ' . htmlspecialchars($specialty) . '</p>
<p style="margin:4px 0"><strong>Serviço:</strong> ' . htmlspecialchars($serviceName) . '</p>
' . ($professionalCouncil ? '<p style="margin:4px 0"><strong>Registro:</strong> ' . htmlspecialchars($professionalCouncil) . '</p>' : '') . '
</div>

<div style="background:white;padding:20px;margin:20px 0;border-radius:8px;border-left:4px solid #7c3aed">
<h3 style="margin:0 0 12px;color:#7c3aed">Detalhes do Atendimento</h3>
<p style="margin:4px 0"><strong>Data de Início:</strong> ' . htmlspecialchars($startDate) . '</p>
<p style="margin:4px 0"><strong>Horário:</strong> ' . htmlspecialchars($startTime) . ' às ' . htmlspecialchars($endTime) . '</p>
<p style="margin:4px 0"><strong>Frequência:</strong> ' . htmlspecialchars($frequencyText) . '</p>
<p style="margin:4px 0"><strong>Duração:</strong> ' . htmlspecialchars($durationWeeks) . ' semanas</p>
<p style="margin:4px 0"><strong>Total de Sessões:</strong> ' . htmlspecialchars($totalSessions) . '</p>
</div>

<div style="background:#d1fae5;padding:20px;margin:20px 0;border-radius:8px;text-align:center">
<p style="margin:0;font-size:14px;color:#065f46"><strong>Valor por Sessão:</strong> ' . htmlspecialchars($valuePerSession) . '</p>
<p style="margin:8px 0 0;font-size:24px;font-weight:bold;color:#059669">VALOR TOTAL: ' . htmlspecialchars($totalValue) . '</p>
</div>

' . $sessionSchedule . '
' . $notesSection . '

<p style="margin-top:30px">Aguardamos retorno com a autorização ou eventuais ajustes necessários.</p>
<p>Atenciosamente,<br><strong>MultiLife Care</strong></p>
</div>
<div style="text-align:center;padding:16px;color:#6b7280;font-size:12px">
<p>Este é um e-mail automático. © 2026 MultiLife Care</p>
</div>
</div>
</body></html>';
}

/**
 * Listar todos os tipos de evento disponíveis
 */
function email_get_available_event_types(): array
{
    return [
        'proposal_send' => 'Envio de Proposta',
        'proposal_resend' => 'Reenvio de Proposta',
        'authorization_approved' => 'Autorização Aprovada',
        'authorization_denied' => 'Autorização Negada',
        'appointment_reminder' => 'Lembrete de Agendamento',
        'document_request' => 'Solicitação de Documentos'
    ];
}

/**
 * Obter variáveis disponíveis por tipo de evento
 */
function email_get_available_variables(string $eventType): array
{
    $common = [
        'patient_name' => 'Nome do paciente',
        'patient_email' => 'E-mail do paciente',
        'patient_phone' => 'Telefone do paciente',
        'professional_name' => 'Nome do profissional',
        'professional_email' => 'E-mail do profissional',
        'professional_phone' => 'Telefone do profissional',
        'professional_council' => 'Registro profissional (ex: CRP 12345/SP)',
        'specialty' => 'Especialidade',
        'location' => 'Localização (cidade/estado)',
    ];
    
    $eventSpecific = match($eventType) {
        'proposal_send', 'proposal_resend' => [
            'service_name' => 'Nome do serviço',
            'start_date' => 'Data de início (formatada)',
            'start_time' => 'Horário de início',
            'end_time' => 'Horário de término',
            'frequency_text' => 'Frequência (Semanal, Diário, etc)',
            'sessions_per_week' => 'Sessões por semana',
            'duration_weeks' => 'Duração em semanas',
            'total_sessions' => 'Total de sessões',
            'value_per_session' => 'Valor por sessão (formatado)',
            'total_value' => 'Valor total (formatado)',
            'session_schedule' => 'Cronograma de sessões (HTML table rows)',
            'notes_section' => 'Seção de observações (HTML completo ou vazio)',
        ],
        'proposal_resend' => [
            'resend_notes' => 'Justificativa do reenvio',
            'previous_value_per_session' => 'Valor anterior por sessão',
            'previous_total_value' => 'Valor total anterior',
        ],
        'authorization_approved', 'authorization_denied' => [
            'authorization_number' => 'Número da autorização',
            'response_date' => 'Data da resposta',
            'operator_notes' => 'Observações da operadora',
        ],
        'appointment_reminder' => [
            'appointment_date' => 'Data do agendamento',
            'appointment_time' => 'Horário do agendamento',
            'days_until' => 'Dias até o agendamento',
        ],
        'document_request' => [
            'documents_list' => 'Lista de documentos solicitados',
            'deadline_date' => 'Prazo para envio',
        ],
        default => []
    };
    
    return array_merge($common, $eventSpecific);
}

/**
 * Gerar HTML de cronograma de sessões
 */
function email_generate_session_schedule(array $sessionDates): string
{
    if (empty($sessionDates)) {
        return '<tr><td colspan="2">Nenhuma sessão agendada</td></tr>';
    }
    
    $html = '';
    foreach ($sessionDates as $index => $session) {
        $sessionNumber = $index + 1;
        $formatted = $session['formatted'] ?? $session['date'] . ' às ' . $session['start_time'];
        $html .= "<tr><td>Sessão {$sessionNumber}</td><td>{$formatted}</td></tr>\n";
    }
    
    return $html;
}

/**
 * Gerar HTML de seção de observações
 */
function email_generate_notes_section(string $notes): string
{
    if (trim($notes) === '') {
        return '';
    }
    
    return '
    <div class="section">
        <div class="section-title">📝 Observações</div>
        <p style="line-height: 1.8;">' . nl2br(htmlspecialchars($notes)) . '</p>
    </div>';
}

/**
 * Formatar valor monetário
 */
function email_format_currency(float $value): string
{
    return number_format($value, 2, ',', '.');
}

/**
 * Formatar data
 */
function email_format_date(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

/**
 * Formatar horário
 */
function email_format_time(string $time): string
{
    return substr($time, 0, 5);
}
