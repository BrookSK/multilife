<?php

declare(strict_types=1);

/**
 * Geradores de HTML profissional para todos os e-mails do sistema.
 * Usa o layout base de email_base_template.php.
 */

require_once __DIR__ . '/email_base_template.php';

/**
 * E-mail: Onboarding do profissional (credenciais de acesso)
 */
function email_html_onboarding(string $name, string $email, string $password, string $loginUrl): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Sua candidatura foi aprovada e seu acesso ao sistema MultiLife Care está ativo. Seguem suas credenciais:</p>';
    
    $body .= '
<div style="background:#f0fdf4;padding:24px;margin:24px 0;border-radius:10px;border:1px solid #bbf7d0">
<table style="width:100%;border-collapse:collapse">
<tr><td style="padding:8px 0;font-size:14px;color:#6b7280;width:120px">Login:</td><td style="padding:8px 0;font-size:14px;font-weight:700;color:#1f2937">' . htmlspecialchars($email) . '</td></tr>
<tr><td style="padding:8px 0;font-size:14px;color:#6b7280">Senha:</td><td style="padding:8px 0;font-size:14px;font-weight:700;color:#059669;font-family:monospace;letter-spacing:1px">' . htmlspecialchars($password) . '</td></tr>
<tr><td style="padding:8px 0;font-size:14px;color:#6b7280">Acesso:</td><td style="padding:8px 0;font-size:14px"><a href="' . htmlspecialchars($loginUrl) . '" style="color:#00a884;font-weight:600">' . htmlspecialchars($loginUrl) . '</a></td></tr>
</table>
</div>';
    
    $body .= '<p style="font-size:14px;color:#dc2626;font-weight:600">⚠️ Importante: Troque sua senha no primeiro acesso.</p>';
    $body .= email_divider();
    $body .= '<p style="font-size:14px;color:#4b5563">Seja bem-vindo(a) à equipe MultiLife Care! Estamos felizes em contar com você.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Bem-vindo(a) à MultiLife Care', $body);
}

/**
 * E-mail: Formulário/documento aprovado
 */
function email_html_doc_approved(string $profName, string $docId, string $patientRef, string $sessionsCount): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($profName) . '</strong>!</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Seu formulário foi revisado e <strong style="color:#059669">aprovado</strong> com sucesso.</p>';
    
    $content = email_data_row('Documento', '#' . $docId);
    $content .= email_data_row('Paciente', $patientRef);
    $content .= email_data_row('Sessões', $sessionsCount);
    $body .= email_section('✅ Documento Aprovado', $content, '#059669');
    
    $body .= '<p style="font-size:14px;color:#4b5563;margin-top:20px">O pagamento referente a esta sessão será processado conforme o ciclo de faturamento.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Documento Aprovado', $body);
}

/**
 * E-mail: Lembrete de formulário pendente (antes do prazo)
 */
function email_html_doc_reminder(string $profName, string $docId, string $patientRef, string $dueAt): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($profName) . '</strong>!</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Este é um lembrete amigável sobre um formulário pendente de envio:</p>';
    
    $content = email_data_row('Documento', '#' . $docId);
    $content .= email_data_row('Paciente', $patientRef);
    $content .= email_data_row('Prazo', $dueAt);
    $body .= email_section('⏰ Formulário Pendente', $content, '#f59e0b');
    
    $body .= '<p style="font-size:14px;color:#374151;margin-top:20px">Por favor, acesse o sistema e envie o formulário dentro do prazo para evitar cobranças.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Lembrete: Formulário Pendente', $body);
}

/**
 * E-mail: Formulário em atraso (cobrança)
 */
function email_html_doc_overdue(string $profName, string $docId, string $patientRef, string $dueAt, string $daysOverdue): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($profName) . '</strong>!</p>';
    $body .= '<p style="font-size:14px;color:#dc2626;font-weight:600">Atenção: você possui um formulário em atraso.</p>';
    
    $content = email_data_row('Documento', '#' . $docId);
    $content .= email_data_row('Paciente', $patientRef);
    $content .= email_data_row('Prazo original', $dueAt);
    $content .= email_data_row('Dias em atraso', $daysOverdue . ' dias');
    $body .= email_section('🚨 Formulário em Atraso', $content, '#dc2626');
    
    $body .= '<p style="font-size:14px;color:#374151;margin-top:20px">Solicitamos o envio urgente do formulário para dar continuidade ao processo de faturamento.</p>';
    $body .= '<p style="font-size:13px;color:#6b7280;margin-top:8px">Caso tenha dificuldades, entre em contato com a equipe administrativa.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Atenção: Formulário em Atraso', $body);
}

/**
 * E-mail: Confirmação de agendamento
 */
function email_html_appointment_confirmation(string $patientName, string $professionalName, string $firstAt, string $appointmentId): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($patientName) . '</strong>!</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Seu agendamento foi confirmado com sucesso. Seguem os detalhes:</p>';
    
    $content = email_data_row('Código', '#' . $appointmentId);
    $content .= email_data_row('Profissional', $professionalName);
    $content .= email_data_row('Data/Hora', $firstAt);
    $body .= email_section('📅 Agendamento Confirmado', $content, '#0284c7');
    
    $body .= '<p style="font-size:14px;color:#4b5563;margin-top:20px">Em caso de necessidade de reagendamento, entre em contato conosco com antecedência.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Agendamento Confirmado', $body);
}

/**
 * E-mail: Candidatura precisa de mais informações
 */
function email_html_application_need_info(string $name, string $applicationId, string $message): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($name) . '</strong>!</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Analisamos sua candidatura e precisamos de informações complementares para prosseguir:</p>';
    
    $body .= '
<div style="background:#fffbeb;padding:20px;margin:20px 0;border-radius:10px;border:1px solid #fde68a">
<h3 style="margin:0 0 10px;font-size:14px;font-weight:700;color:#92400e">📋 Informações necessárias — Candidatura #' . htmlspecialchars($applicationId) . '</h3>
<p style="margin:0;font-size:14px;color:#78350f;line-height:1.7">' . nl2br(htmlspecialchars($message)) . '</p>
</div>';
    
    $body .= '<p style="font-size:14px;color:#374151;margin-top:20px">Por favor, envie as informações solicitadas para que possamos concluir a análise da sua candidatura.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Complemento Necessário — Candidatura', $body);
}

/**
 * E-mail: Candidatura rejeitada
 */
function email_html_application_rejected(string $name, string $applicationId, string $message): string
{
    $body = '<p style="font-size:15px;color:#374151">Olá, <strong>' . htmlspecialchars($name) . '</strong>.</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Agradecemos seu interesse em fazer parte da equipe MultiLife Care.</p>';
    $body .= '<p style="font-size:14px;color:#4b5563">Após análise da sua candidatura #' . htmlspecialchars($applicationId) . ', infelizmente não poderemos dar prosseguimento no momento.</p>';
    
    if (trim($message) !== '') {
        $body .= '
<div style="background:#fef2f2;padding:20px;margin:20px 0;border-radius:10px;border:1px solid #fecaca">
<h3 style="margin:0 0 10px;font-size:14px;font-weight:700;color:#991b1b">Retorno da análise</h3>
<p style="margin:0;font-size:14px;color:#7f1d1d;line-height:1.7">' . nl2br(htmlspecialchars($message)) . '</p>
</div>';
    }
    
    $body .= '<p style="font-size:14px;color:#4b5563;margin-top:20px">Desejamos sucesso na sua trajetória profissional.</p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Retorno da Candidatura', $body);
}

/**
 * E-mail: Reenvio de proposta (com destaque de alteração)
 */
function email_html_proposal_resend(array $vars): string
{
    require_once __DIR__ . '/email_base_template.php';
    
    $body = '<p style="font-size:15px;color:#374151">Prezado(a),</p>';
    
    // Destaque de reenvio
    $resendNotes = $vars['resend_notes'] ?? '';
    if ($resendNotes !== '') {
        $body .= '
<div style="background:#eff6ff;padding:16px 20px;margin:16px 0;border-radius:8px;border:1px solid #bfdbfe">
<p style="margin:0;font-size:13px;font-weight:700;color:#1e40af">🔄 Reenvio de Proposta</p>
<p style="margin:6px 0 0;font-size:14px;color:#1e3a5f">' . nl2br(htmlspecialchars($resendNotes)) . '</p>
</div>';
    }
    
    $body .= '<p style="font-size:14px;color:#4b5563">Segue proposta atualizada de atendimento domiciliar para análise e autorização:</p>';
    
    // Reusa a mesma estrutura do envio original
    $patientContent = email_data_row('Nome', $vars['patient_name'] ?? '');
    $patientContent .= email_data_row('E-mail', $vars['patient_email'] ?? '');
    $patientContent .= email_data_row('Telefone', $vars['patient_phone'] ?? '');
    $patientContent .= email_data_row('Localização', $vars['location'] ?? '');
    $body .= email_section('👤 Dados do Paciente', $patientContent, '#00a884');
    
    // Seção: Serviço (sem dados do profissional na proposta)
    $profContent = email_data_row('Especialidade', $vars['specialty'] ?? '');
    $profContent .= email_data_row('Serviço', $vars['service_name'] ?? '');
    $body .= email_section('🏥 Serviço Solicitado', $profContent, '#0284c7');
    
    $schedContent = email_data_row('Data de Início', $vars['start_date'] ?? '');
    $schedContent .= email_data_row('Horário', ($vars['start_time'] ?? '') . ' às ' . ($vars['end_time'] ?? ''));
    $schedContent .= email_data_row('Frequência', $vars['frequency_text'] ?? '');
    $schedContent .= email_data_row('Duração', ($vars['duration_weeks'] ?? '') . ' semanas');
    $schedContent .= email_data_row('Total de Sessões', $vars['total_sessions'] ?? '');
    $body .= email_section('📋 Agendamento Proposto', $schedContent, '#7c3aed');
    
    // Valor com comparação (se houver alteração)
    $prevValue = $vars['previous_total_value'] ?? '';
    $currentValue = $vars['total_value'] ?? '';
    if ($prevValue !== '' && $prevValue !== $currentValue) {
        $body .= '
<div style="background:#d1fae5;padding:20px;margin:24px 0;border-radius:10px;text-align:center">
<p style="margin:0;font-size:12px;color:#6b7280;text-decoration:line-through">Valor anterior: R$ ' . htmlspecialchars($prevValue) . '</p>
<p style="margin:4px 0;font-size:13px;color:#065f46;font-weight:600">Valor por Sessão: R$ ' . htmlspecialchars($vars['value_per_session'] ?? '') . '</p>
<p style="margin:8px 0 0;font-size:28px;font-weight:900;color:#059669;letter-spacing:-0.5px">R$ ' . htmlspecialchars($currentValue) . '</p>
</div>';
    } else {
        $body .= email_highlight_box('Valor por Sessão: R$ ' . ($vars['value_per_session'] ?? ''), 'R$ ' . $currentValue);
    }
    
    // Sessões
    $sessionDates = $vars['session_dates_array'] ?? [];
    if (!empty($sessionDates)) {
        $body .= email_session_table($sessionDates);
    }
    
    $body .= email_notes_block($vars['notes'] ?? '');
    
    $body .= email_divider();
    $body .= '<p style="font-size:14px;color:#374151"><strong>Para autorizar ou solicitar ajustes, basta responder este e-mail.</strong></p>';
    $body .= '<p style="font-size:14px;color:#6b7280;margin-top:20px">Atenciosamente,<br><strong style="color:#00a884">Equipe MultiLife Care</strong></p>';
    
    return email_base_layout('Proposta de Atendimento (Reenvio)', $body, 'Para autorizar, responda diretamente este e-mail.');
}
