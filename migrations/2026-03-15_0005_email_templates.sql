-- Migration: Sistema de Templates de E-mail HTML
-- Data: 2026-03-15
-- Descrição: Criar tabela para templates de e-mail personalizados com HTML e variáveis

-- Tabela de templates de e-mail
CREATE TABLE IF NOT EXISTS email_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL COMMENT 'Nome identificador do template',
    event_type VARCHAR(100) NOT NULL COMMENT 'Tipo de evento (proposal_send, proposal_resend)',
    health_insurer_id INT NULL COMMENT 'Operadora específica (NULL = todas)',
    subject VARCHAR(500) NOT NULL COMMENT 'Assunto do e-mail com variáveis',
    body_html TEXT NOT NULL COMMENT 'Corpo do e-mail em HTML com variáveis',
    body_plain TEXT NULL COMMENT 'Versão texto plano (fallback)',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Template ativo/inativo',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_user_id INT UNSIGNED NULL,
    
    INDEX idx_event_type (event_type),
    INDEX idx_health_insurer (health_insurer_id),
    INDEX idx_is_active (is_active),
    
    FOREIGN KEY (health_insurer_id) REFERENCES health_insurers(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    
    UNIQUE KEY uk_event_insurer (event_type, health_insurer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Templates de e-mail HTML para propostas e comunicações';

-- Inserir template padrão de envio de proposta
INSERT INTO email_templates (name, event_type, health_insurer_id, subject, body_html, body_plain, created_by_user_id) VALUES
(
    'Proposta de Atendimento - Padrão',
    'proposal_send',
    NULL,
    'Proposta de Atendimento - {patient_name} - {specialty}',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #2563eb; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; border-left: 4px solid #2563eb; }
        .section-title { font-size: 18px; font-weight: bold; color: #2563eb; margin-bottom: 15px; }
        .info-row { margin: 8px 0; }
        .label { font-weight: bold; color: #4b5563; }
        .value { color: #1f2937; }
        .highlight { background: #fef3c7; padding: 15px; border-radius: 6px; border-left: 4px solid #f59e0b; margin: 20px 0; }
        .total { font-size: 24px; font-weight: bold; color: #059669; text-align: center; padding: 20px; background: #d1fae5; border-radius: 8px; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Proposta de Atendimento Domiciliar</h1>
            <p>MultiLife Care - Sistema de Gestão de Atendimentos</p>
        </div>
        
        <div class="content">
            <p>Prezado(a),</p>
            <p>Segue proposta de atendimento domiciliar para análise e autorização:</p>
            
            <div class="section">
                <div class="section-title">📋 Dados do Paciente</div>
                <div class="info-row"><span class="label">Nome:</span> <span class="value">{patient_name}</span></div>
                <div class="info-row"><span class="label">E-mail:</span> <span class="value">{patient_email}</span></div>
                <div class="info-row"><span class="label">Telefone:</span> <span class="value">{patient_phone}</span></div>
                <div class="info-row"><span class="label">Localização:</span> <span class="value">{location}</span></div>
            </div>
            
            <div class="section">
                <div class="section-title">👨‍⚕️ Profissional Designado</div>
                <div class="info-row"><span class="label">Nome:</span> <span class="value">{professional_name}</span></div>
                <div class="info-row"><span class="label">Especialidade:</span> <span class="value">{specialty}</span></div>
                <div class="info-row"><span class="label">Serviço:</span> <span class="value">{service_name}</span></div>
                <div class="info-row"><span class="label">Registro:</span> <span class="value">{professional_council}</span></div>
                <div class="info-row"><span class="label">E-mail:</span> <span class="value">{professional_email}</span></div>
                <div class="info-row"><span class="label">Telefone:</span> <span class="value">{professional_phone}</span></div>
            </div>
            
            <div class="section">
                <div class="section-title">📅 Agendamento Proposto</div>
                <div class="info-row"><span class="label">Data de Início:</span> <span class="value">{start_date}</span></div>
                <div class="info-row"><span class="label">Horário:</span> <span class="value">{start_time} às {end_time}</span></div>
                <div class="info-row"><span class="label">Frequência:</span> <span class="value">{frequency_text}</span></div>
                <div class="info-row"><span class="label">Sessões por Semana:</span> <span class="value">{sessions_per_week}x</span></div>
                <div class="info-row"><span class="label">Duração:</span> <span class="value">{duration_weeks} semanas</span></div>
                <div class="info-row"><span class="label">Total de Sessões:</span> <span class="value">{total_sessions}</span></div>
            </div>
            
            <div class="section">
                <div class="section-title">📆 Cronograma de Sessões</div>
                <table>
                    <thead>
                        <tr>
                            <th>Sessão</th>
                            <th>Data e Horário</th>
                        </tr>
                    </thead>
                    <tbody>
                        {session_schedule}
                    </tbody>
                </table>
            </div>
            
            <div class="highlight">
                <div class="section-title">💰 Valores</div>
                <div class="info-row"><span class="label">Valor por Sessão:</span> <span class="value">R$ {value_per_session}</span></div>
                <div class="info-row"><span class="label">Total de Sessões:</span> <span class="value">{total_sessions}</span></div>
                <div class="total">VALOR TOTAL: R$ {total_value}</div>
            </div>
            
            {notes_section}
            
            <p style="margin-top: 30px;">Aguardamos retorno com a autorização ou eventuais ajustes necessários.</p>
            <p>Atenciosamente,<br><strong>MultiLife Care</strong></p>
        </div>
        
        <div class="footer">
            <p>Este é um e-mail automático. Por favor, não responda diretamente.</p>
            <p>© 2026 MultiLife Care - Sistema de Gestão de Atendimentos</p>
        </div>
    </div>
</body>
</html>',
    'Prezado(a),

Segue proposta de atendimento domiciliar:

DADOS DO PACIENTE
Nome: {patient_name}
E-mail: {patient_email}
Telefone: {patient_phone}
Localização: {location}

PROFISSIONAL DESIGNADO
Nome: {professional_name}
Especialidade: {specialty}
Serviço: {service_name}
Registro: {professional_council}

AGENDAMENTO PROPOSTO
Data de Início: {start_date}
Horário: {start_time} às {end_time}
Frequência: {frequency_text}
Sessões por Semana: {sessions_per_week}x
Duração: {duration_weeks} semanas
Total de Sessões: {total_sessions}

VALORES
Valor por Sessão: R$ {value_per_session}
Total de Sessões: {total_sessions}
VALOR TOTAL: R$ {total_value}

Aguardamos retorno com a autorização.

Atenciosamente,
MultiLife Care',
    1
),
(
    'Reenvio de Proposta - Padrão',
    'proposal_resend',
    NULL,
    'REENVIO - Proposta de Atendimento - {patient_name} - {specialty}',
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .header { background: #dc2626; color: white; padding: 20px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9fafb; padding: 30px; border: 1px solid #e5e7eb; }
        .section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; border-left: 4px solid #dc2626; }
        .section-title { font-size: 18px; font-weight: bold; color: #dc2626; margin-bottom: 15px; }
        .info-row { margin: 8px 0; }
        .label { font-weight: bold; color: #4b5563; }
        .value { color: #1f2937; }
        .alert { background: #fef2f2; padding: 20px; border-radius: 8px; border: 2px solid #dc2626; margin: 20px 0; }
        .comparison { display: flex; gap: 20px; margin: 20px 0; }
        .old-value { background: #fee2e2; padding: 15px; border-radius: 6px; flex: 1; }
        .new-value { background: #d1fae5; padding: 15px; border-radius: 6px; flex: 1; }
        .total { font-size: 24px; font-weight: bold; color: #059669; text-align: center; padding: 20px; background: #d1fae5; border-radius: 8px; }
        .footer { text-align: center; padding: 20px; color: #6b7280; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ REENVIO - Proposta de Atendimento</h1>
            <p>MultiLife Care - Sistema de Gestão de Atendimentos</p>
        </div>
        
        <div class="content">
            <div class="alert">
                <h2 style="margin-top: 0; color: #dc2626;">📢 Justificativa do Reenvio</h2>
                <p style="font-size: 16px; line-height: 1.8;">{resend_notes}</p>
            </div>
            
            <p>Prezado(a),</p>
            <p>Segue <strong>REENVIO</strong> de proposta de atendimento domiciliar com valores ajustados:</p>
            
            <div class="section">
                <div class="section-title">📋 Dados do Paciente</div>
                <div class="info-row"><span class="label">Nome:</span> <span class="value">{patient_name}</span></div>
                <div class="info-row"><span class="label">E-mail:</span> <span class="value">{patient_email}</span></div>
                <div class="info-row"><span class="label">Telefone:</span> <span class="value">{patient_phone}</span></div>
                <div class="info-row"><span class="label">Localização:</span> <span class="value">{location}</span></div>
            </div>
            
            <div class="section">
                <div class="section-title">👨‍⚕️ Profissional Designado</div>
                <div class="info-row"><span class="label">Nome:</span> <span class="value">{professional_name}</span></div>
                <div class="info-row"><span class="label">Especialidade:</span> <span class="value">{specialty}</span></div>
                <div class="info-row"><span class="label">Registro:</span> <span class="value">{professional_council}</span></div>
            </div>
            
            <div class="section">
                <div class="section-title">💰 Comparação de Valores</div>
                <div class="comparison">
                    <div class="old-value">
                        <h3 style="margin-top: 0; color: #dc2626;">❌ Valor Anterior</h3>
                        <p><strong>Por Sessão:</strong> R$ {previous_value_per_session}</p>
                        <p><strong>Total:</strong> R$ {previous_total_value}</p>
                    </div>
                    <div class="new-value">
                        <h3 style="margin-top: 0; color: #059669;">✅ Novo Valor</h3>
                        <p><strong>Por Sessão:</strong> R$ {value_per_session}</p>
                        <p><strong>Total:</strong> R$ {total_value}</p>
                    </div>
                </div>
                <div class="total">NOVO VALOR TOTAL: R$ {total_value}</div>
            </div>
            
            <p style="margin-top: 30px;">Aguardamos retorno com a nova autorização ou eventuais ajustes necessários.</p>
            <p>Atenciosamente,<br><strong>MultiLife Care</strong></p>
        </div>
        
        <div class="footer">
            <p>Este é um e-mail automático. Por favor, não responda diretamente.</p>
            <p>© 2026 MultiLife Care - Sistema de Gestão de Atendimentos</p>
        </div>
    </div>
</body>
</html>',
    'REENVIO - Proposta de Atendimento

JUSTIFICATIVA: {resend_notes}

DADOS DO PACIENTE
Nome: {patient_name}

PROFISSIONAL
Nome: {professional_name}
Especialidade: {specialty}

COMPARAÇÃO DE VALORES
Valor Anterior por Sessão: R$ {previous_value_per_session}
Total Anterior: R$ {previous_total_value}

NOVO Valor por Sessão: R$ {value_per_session}
NOVO VALOR TOTAL: R$ {total_value}

Aguardamos retorno.

Atenciosamente,
MultiLife Care',
    1
);

-- Verificar templates criados
SELECT 
    id,
    name,
    event_type,
    COALESCE((SELECT name FROM health_insurers WHERE id = email_templates.health_insurer_id), 'Todas') as operadora,
    is_active
FROM email_templates
ORDER BY event_type, name;
