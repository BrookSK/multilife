<?php

declare(strict_types=1);

/**
 * WhatsApp Event Dispatcher
 * 
 * Classe responsável por disparar mensagens WhatsApp baseadas em eventos do sistema.
 * Substitui mensagens hardcoded por templates configuráveis no painel administrativo.
 */
class WhatsAppEventDispatcher
{
    private EvolutionApiV1 $api;
    
    public function __construct()
    {
        // Tentar instancia padrao primeiro
        $baseUrl = rtrim((string)admin_setting_get('evolution.base_url', ''), '/');
        $apiKey = (string)admin_setting_get('evolution.api_key', '');
        $defaultInstance = (string)admin_setting_get('evolution.instance', '');
        
        $api = null;
        
        // Verificar se instancia padrao esta conectada
        if ($baseUrl !== '' && $apiKey !== '' && $defaultInstance !== '') {
            try {
                $tryApi = new EvolutionApiV1($baseUrl, $apiKey, $defaultInstance);
                $connRes = $tryApi->connectionState();
                $state = strtolower(trim((string)($connRes['json']['instance']['state'] ?? ($connRes['json']['state'] ?? ''))));
                if (in_array($state, ['open', 'connected'], true)) {
                    $api = $tryApi;
                }
            } catch (Throwable $e) {}
        }
        
        // Se padrao nao esta conectada, buscar outra instancia conectada
        if ($api === null && $baseUrl !== '' && $apiKey !== '') {
            try {
                $instStmt = db()->prepare("SELECT instance_name FROM whatsapp_instances WHERE status = 'active' AND connection_status = 'connected' ORDER BY is_default DESC, id ASC LIMIT 5");
                $instStmt->execute();
                $instList = $instStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($instList as $instName) {
                    if ((string)$instName === '' || (string)$instName === $defaultInstance) continue;
                    try {
                        $tryApi = new EvolutionApiV1($baseUrl, $apiKey, (string)$instName);
                        $connRes = $tryApi->connectionState();
                        $state = strtolower(trim((string)($connRes['json']['instance']['state'] ?? ($connRes['json']['state'] ?? ''))));
                        if (in_array($state, ['open', 'connected'], true)) {
                            $api = $tryApi;
                            error_log("[WHATSAPP_DISPATCHER] Usando instancia alternativa: $instName (padrao '$defaultInstance' desconectada)");
                            break;
                        }
                    } catch (Throwable $e) { continue; }
                }
            } catch (Throwable $e) {}
        }
        
        // Fallback: usar padrao mesmo sem verificar (pode falhar no envio)
        if ($api === null) {
            $api = new EvolutionApiV1();
        }
        
        $this->api = $api;
    }
    
    /**
     * Dispara um evento WhatsApp
     * 
     * @param string $systemEvent Identificador do evento do sistema
     * @param array $data Dados para substituir variáveis no template
     * @return array Resultado do envio
     */
    public function dispatch(string $systemEvent, array $data): array
    {
        try {
            // Buscar evento configurado
            $stmt = db()->prepare("
                SELECT * FROM whatsapp_events 
                WHERE system_event = ? AND status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$systemEvent]);
            $event = $stmt->fetch();
            
            if (!$event) {
                error_log("[WHATSAPP_DISPATCHER] Evento não encontrado ou inativo: $systemEvent");
                return ['success' => false, 'error' => 'Evento não configurado'];
            }
            
            $results = [];
            
            // Enviar para profissional
            if ($event['send_to_professional'] && !empty($data['professional_phone'])) {
                // Verificar se profissional está ativo
                $professionalId = (int)($data['professional_id'] ?? 0);
                $guardResult = $professionalId > 0 ? notification_guard_check_professional($professionalId) : ['allowed' => true, 'reason' => null];
                
                if (!$guardResult['allowed']) {
                    error_log("[WHATSAPP_DISPATCHER] Bloqueado envio para profissional: " . $guardResult['reason']);
                    $results['professional'] = ['success' => false, 'error' => $guardResult['reason'], 'blocked' => true];
                } else {
                    $message = $this->processTemplate($event['template_professional'], $data);
                    $result = $this->sendMessage(
                        $data['professional_phone'],
                        $message,
                        $event['id'],
                        'professional',
                        $data['professional_name'] ?? ''
                    );
                    $results['professional'] = $result;
                    
                    // Enviar arquivos anexos
                    $this->sendEventFiles($event['id'], 'professional', $data['professional_phone']);

                    // ESPELHAR no E-MAIL do profissional (mesmo texto do WhatsApp).
                    $this->sendEmailNotification(
                        'professional',
                        (string)($event['name'] ?? 'Notificação'),
                        $message,
                        $data,
                        (int)($data['professional_id'] ?? 0)
                    );
                }
            }
            // Mesmo se não tem telefone, tentar o e-mail do profissional (canal independente).
            elseif ($event['send_to_professional'] && empty($data['professional_phone'])) {
                $professionalId = (int)($data['professional_id'] ?? 0);
                $guardResult = $professionalId > 0 ? notification_guard_check_professional($professionalId) : ['allowed' => true, 'reason' => null];
                if ($guardResult['allowed']) {
                    $message = $this->processTemplate($event['template_professional'], $data);
                    $this->sendEmailNotification('professional', (string)($event['name'] ?? 'Notificação'), $message, $data, $professionalId);
                }
            }
            
            // Enviar para paciente
            if ($event['send_to_patient'] && !empty($data['patient_phone'])) {
                // Verificar se paciente pode receber notificações
                $patientId = (int)($data['patient_id'] ?? 0);
                if ($patientId > 0) {
                    $guardResult = notification_guard_check_patient($patientId);
                } else {
                    $guardResult = notification_guard_check_patient_by_phone($data['patient_phone']);
                }
                
                if (!$guardResult['allowed']) {
                    error_log("[WHATSAPP_DISPATCHER] Bloqueado envio para paciente: " . $guardResult['reason']);
                    $results['patient'] = ['success' => false, 'error' => $guardResult['reason'], 'blocked' => true];
                } else {
                    $message = $this->processTemplate($event['template_patient'], $data);
                    $result = $this->sendMessage(
                        $data['patient_phone'],
                        $message,
                        $event['id'],
                        'patient',
                        $data['patient_name'] ?? ''
                    );
                    $results['patient'] = $result;
                    
                    // Enviar arquivos anexos
                    $this->sendEventFiles($event['id'], 'patient', $data['patient_phone']);

                    // ESPELHAR no E-MAIL do paciente (mesmo texto do WhatsApp).
                    $this->sendEmailNotification(
                        'patient',
                        (string)($event['name'] ?? 'Notificação'),
                        $message,
                        $data,
                        (int)($data['patient_id'] ?? 0)
                    );
                }
            }
            // Mesmo sem telefone do paciente, tentar o e-mail (canal independente).
            elseif ($event['send_to_patient'] && empty($data['patient_phone'])) {
                $patientId = (int)($data['patient_id'] ?? 0);
                $guardResult = $patientId > 0 ? notification_guard_check_patient($patientId) : ['allowed' => true, 'reason' => null];
                if ($guardResult['allowed']) {
                    $message = $this->processTemplate($event['template_patient'], $data);
                    $this->sendEmailNotification('patient', (string)($event['name'] ?? 'Notificação'), $message, $data, $patientId);
                }
            }
            
            return ['success' => true, 'results' => $results];
            
        } catch (Exception $e) {
            error_log("[WHATSAPP_DISPATCHER] Erro ao disparar evento: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Processa template substituindo variáveis
     * 
     * @param string $template Template com variáveis
     * @param array $data Dados para substituição
     * @return string Mensagem processada
     */
    private function processTemplate(string $template, array $data): string
    {
        // Links enviados ao profissional devem ser PÚBLICOS (sem login).
        // Nunca usar o painel interno (/monitoramento, /profissional_registros.php)
        // como fallback. Se o chamador não informar um link público explícito
        // (attendance_link / appointment_link / registration_link), o placeholder
        // fica vazio e é removido ao final do processamento do template.
        $attendanceLink = '';
        
        $variables = [
            '{{profissional_nome}}' => $data['professional_name'] ?? '',
            '{{profissional_telefone}}' => $data['professional_phone'] ?? '',
            '{{paciente_nome}}' => $data['patient_name'] ?? '',
            '{{paciente_telefone}}' => $data['patient_phone'] ?? '',
            '{{id_atendimento}}' => $data['attendance_id'] ?? '',
            '{{data_atendimento}}' => $data['attendance_date'] ?? date('d/m/Y'),
            '{{data_consulta}}' => $data['appointment_date'] ?? '',
            '{{horario_consulta}}' => $data['appointment_time'] ?? '',
            '{{link_atendimento}}' => $data['attendance_link'] ?? $attendanceLink,
            '{{link_consulta}}' => $data['appointment_link'] ?? $attendanceLink,
            '{{link_cadastro}}' => $data['registration_link'] ?? '',
            '{{link_documentos}}' => $data['documents_link'] ?? '',
            '{{id_preadmissao}}' => $data['preadmission_id'] ?? $data['id_preadmissao'] ?? $data['attendance_id'] ?? '',
            '{{data_inicio}}' => $data['start_date'] ?? $data['attendance_date'] ?? '',
            '{{data_aprovacao}}' => $data['approval_date'] ?? $data['data_aprovacao'] ?? date('d/m/Y H:i'),
            '{{data_prazo}}' => $data['deadline_date'] ?? '',
            '{{id_paciente}}' => (string)($data['patient_id'] ?? ''),
            '{{data_cadastro}}' => $data['registration_date'] ?? date('d/m/Y'),
            '{{motivo_cancelamento}}' => $data['cancellation_reason'] ?? '',
            // Variáveis extras úteis
            '{{especialidade}}' => $data['specialty'] ?? '',
            '{{servico}}' => $data['service_type'] ?? '',
            '{{sessoes}}' => $data['session_quantity'] ?? '',
            '{{frequencia}}' => $data['session_frequency'] ?? '',
            '{{valor_acordado}}' => $data['agreed_value'] ?? '',
            '{{valor_autorizado}}' => $data['authorized_value'] ?? '',
            // Campos dos templates oficiais ao profissional
            '{{paciente_endereco}}' => $data['patient_address'] ?? '',
            '{{paciente_contatos}}' => $data['patient_contacts'] ?? '',
            '{{agendamento}}' => $data['schedule'] ?? '',
            '{{data_hospitalizacao}}' => $data['hospitalization_date'] ?? '',
            '{{data_retomada}}' => $data['resume_date'] ?? '',
            // Credenciais de sistema externo do profissional (repassadas na pré-admissão).
            '{{login_externo}}' => $data['external_login'] ?? '',
            '{{senha_externa}}' => $data['external_password'] ?? '',
            '{{atendente_nome}}' => $data['attendant_name'] ?? (string)admin_setting_get('app.attendant_name', 'Maria Jullia'),
        ];
        
        $message = $template;
        foreach ($variables as $var => $value) {
            $message = str_replace($var, (string)$value, $message);
        }
        
        // Limpar variáveis não substituídas (que ficaram como {{xxx}})
        $message = preg_replace('/\{\{[^}]+\}\}/', '', $message);
        
        return trim($message);
    }

    /**
     * Espelha a notificação no E-MAIL do destinatário (mesmo texto enviado no WhatsApp).
     *
     * Resolve o e-mail: usa o passado no $data (professional_email/patient_email) ou,
     * na ausência, busca pelo id em users/patients. Envia via SmtpClient com o layout
     * padrão. É best-effort: qualquer falha é logada e não interrompe o fluxo.
     *
     * @param string $recipientType 'professional' | 'patient'
     * @param string $eventTitle Nome do evento (assunto do e-mail)
     * @param string $messageText Texto já processado (o mesmo do WhatsApp)
     * @param array $data Dados do dispatch
     * @param int $recipientId ID do profissional (users) ou paciente (patients)
     */
    private function sendEmailNotification(string $recipientType, string $eventTitle, string $messageText, array $data, int $recipientId): void
    {
        try {
            // 1) Resolver o e-mail do destinatário.
            $toEmail = '';
            $toName = '';
            if ($recipientType === 'professional') {
                $toEmail = trim((string)($data['professional_email'] ?? ''));
                $toName = trim((string)($data['professional_name'] ?? ''));
                if ($toEmail === '' && $recipientId > 0) {
                    $st = db()->prepare('SELECT name, email FROM users WHERE id = :id LIMIT 1');
                    $st->execute(['id' => $recipientId]);
                    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $toEmail = trim((string)($row['email'] ?? ''));
                        if ($toName === '') { $toName = trim((string)($row['name'] ?? '')); }
                    }
                }
            } else {
                $toEmail = trim((string)($data['patient_email'] ?? ''));
                $toName = trim((string)($data['patient_name'] ?? ''));
                if ($toEmail === '' && $recipientId > 0) {
                    $st = db()->prepare('SELECT full_name, email FROM patients WHERE id = :id LIMIT 1');
                    $st->execute(['id' => $recipientId]);
                    if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                        $toEmail = trim((string)($row['email'] ?? ''));
                        if ($toName === '') { $toName = trim((string)($row['full_name'] ?? '')); }
                    }
                }
            }

            // Sem e-mail válido: nada a fazer (não é erro — o destinatário pode não ter e-mail).
            if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            // Ignorar e-mails placeholder de pré-cadastro (não são caixas reais).
            if (str_ends_with(mb_strtolower($toEmail), '@precadastro.local')) {
                return;
            }

            // 2) Remetente configurado.
            $fromEmail = trim((string)admin_setting_get('smtp.out.from_email', ''));
            $fromName = trim((string)admin_setting_get('smtp.out.from_name', 'MultiLife Care'));
            if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                error_log('[WHATSAPP_DISPATCHER] E-mail não enviado: remetente SMTP não configurado.');
                return;
            }

            // 3) Montar corpo HTML a partir do MESMO texto do WhatsApp.
            //    Converte quebras de linha e negrito *texto* do WhatsApp para HTML.
            require_once __DIR__ . '/email_base_template.php';
            $safe = htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8');
            // *negrito* do WhatsApp -> <strong>
            $safe = preg_replace('/\*([^*\n]+)\*/', '<strong>$1</strong>', $safe);
            $safe = nl2br($safe);
            $body = '<div style="font-size:15px;color:#374151;line-height:1.7">' . $safe . '</div>';
            $html = function_exists('email_base_layout') ? email_base_layout($eventTitle, $body) : $body;

            // 4) Enviar.
            $smtp = new SmtpClient();
            $smtp->send($fromEmail, $fromName, $toEmail, $eventTitle . ' - MultiLife Care', $html);
            error_log('[WHATSAPP_DISPATCHER] E-mail espelhado enviado para ' . $recipientType . ': ' . $toEmail);
        } catch (Throwable $e) {
            error_log('[WHATSAPP_DISPATCHER] Falha ao espelhar e-mail (' . $recipientType . '): ' . $e->getMessage());
        }
    }
    
    /**
     * Envia mensagem WhatsApp
     * 
     * @param string $phone Telefone do destinatário
     * @param string $message Mensagem a enviar
     * @param int $eventId ID do evento
     * @param string $recipientType Tipo de destinatário
     * @param string $recipientName Nome do destinatário
     * @return array Resultado do envio
     */
    private function sendMessage(
        string $phone,
        string $message,
        int $eventId,
        string $recipientType,
        string $recipientName
    ): array {
        try {
            // Normalizar telefone
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (!str_starts_with($phone, '55')) {
                $phone = '55' . $phone;
            }
            
            // Enviar mensagem via Evolution API
            $result = $this->api->sendText($phone, $message);
            
            // Verificar sucesso pelo HTTP status
            $httpStatus = (int)($result['status'] ?? 0);
            $isSuccess = $httpStatus >= 200 && $httpStatus < 300;
            
            // Registrar log
            $this->logMessage(
                $eventId,
                $recipientType,
                $phone,
                $recipientName,
                $message,
                $isSuccess ? 'sent' : 'failed',
                $isSuccess ? null : ('HTTP ' . $httpStatus)
            );
            
            return ['success' => $isSuccess, 'status' => $httpStatus, 'result' => $result];
            
        } catch (Exception $e) {
            error_log("[WHATSAPP_DISPATCHER] Erro ao enviar mensagem: " . $e->getMessage());
            
            $this->logMessage(
                $eventId,
                $recipientType,
                $phone,
                $recipientName,
                $message,
                'failed',
                $e->getMessage()
            );
            
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Envia arquivos anexos do evento
     * 
     * @param int $eventId ID do evento
     * @param string $recipientType Tipo de destinatário
     * @param string $phone Telefone do destinatário
     */
    private function sendEventFiles(int $eventId, string $recipientType, string $phone): void
    {
        try {
            // Buscar arquivos do evento
            $stmt = db()->prepare("
                SELECT * FROM whatsapp_event_files 
                WHERE event_id = ? 
                AND (recipient_type = ? OR recipient_type = 'both')
            ");
            $stmt->execute([$eventId, $recipientType]);
            $files = $stmt->fetchAll();
            
            if (empty($files)) {
                return;
            }
            
            // Normalizar telefone
            $phone = preg_replace('/[^0-9]/', '', $phone);
            if (!str_starts_with($phone, '55')) {
                $phone = '55' . $phone;
            }
            
            // Enviar cada arquivo
            foreach ($files as $file) {
                $filePath = __DIR__ . '/..' . $file['file_path'];
                
                if (!file_exists($filePath)) {
                    error_log("[WHATSAPP_DISPATCHER] Arquivo não encontrado: $filePath");
                    continue;
                }
                
                // Determinar tipo de mídia baseado no MIME type
                $mimeType = $file['file_type'];
                if (str_starts_with($mimeType, 'image/')) {
                    $this->api->sendImage($phone, $filePath, $file['file_name']);
                } elseif (str_starts_with($mimeType, 'application/pdf') || str_starts_with($mimeType, 'application/')) {
                    $this->api->sendDocument($phone, $filePath, $file['file_name']);
                } else {
                    $this->api->sendDocument($phone, $filePath, $file['file_name']);
                }
                
                error_log("[WHATSAPP_DISPATCHER] Arquivo enviado: {$file['file_name']} para $phone");
            }
            
        } catch (Exception $e) {
            error_log("[WHATSAPP_DISPATCHER] Erro ao enviar arquivos: " . $e->getMessage());
        }
    }
    
    /**
     * Registra log de envio de mensagem
     * 
     * @param int $eventId ID do evento
     * @param string $recipientType Tipo de destinatário
     * @param string $phone Telefone
     * @param string $name Nome
     * @param string $message Mensagem enviada
     * @param string $status Status do envio
     * @param string|null $error Mensagem de erro
     */
    private function logMessage(
        int $eventId,
        string $recipientType,
        string $phone,
        string $name,
        string $message,
        string $status,
        ?string $error = null
    ): void {
        try {
            $stmt = db()->prepare("
                INSERT INTO whatsapp_event_logs 
                (event_id, recipient_type, recipient_phone, recipient_name, message_sent, status, error_message)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $eventId,
                $recipientType,
                $phone,
                $name,
                $message,
                $status,
                $error
            ]);
        } catch (Exception $e) {
            error_log("[WHATSAPP_DISPATCHER] Erro ao registrar log: " . $e->getMessage());
        }
    }
    
    /**
     * Métodos helper para eventos específicos do sistema
     */
    
    public static function attendanceAssigned(int $attendanceId, int $professionalId, int $patientId): array
    {
        $dispatcher = new self();
        
        // Buscar dados do atendimento, profissional e paciente
        $attendance = db()->prepare("SELECT * FROM attendances WHERE id = ?")->execute([$attendanceId])->fetch();
        $professional = db()->prepare("SELECT * FROM users WHERE id = ?")->execute([$professionalId])->fetch();
        $patient = db()->prepare("SELECT * FROM patients WHERE id = ?")->execute([$patientId])->fetch();
        
        $data = [
            'professional_name' => $professional['name'] ?? '',
            'professional_phone' => $professional['phone'] ?? '',
            'patient_name' => $patient['name'] ?? '',
            'patient_phone' => $patient['phone'] ?? '',
            'attendance_id' => (string)$attendanceId,
            'attendance_date' => $attendance['created_at'] ?? date('Y-m-d'),
            'attendance_link' => 'https://sistema.com/atendimento/' . $attendanceId,
        ];
        
        return $dispatcher->dispatch('attendance_assigned', $data);
    }
    
    public static function appointmentScheduled(int $appointmentId): array
    {
        $dispatcher = new self();
        
        // Buscar dados da consulta
        // TODO: Implementar busca real dos dados
        
        $data = [
            'professional_name' => 'Dr. João Silva',
            'professional_phone' => '5511999999999',
            'patient_name' => 'Maria Santos',
            'patient_phone' => '5511888888888',
            'appointment_date' => '2024-03-15',
            'appointment_time' => '14:00',
            'appointment_link' => 'https://sistema.com/consulta/' . $appointmentId,
        ];
        
        return $dispatcher->dispatch('appointment_scheduled', $data);
    }
}
