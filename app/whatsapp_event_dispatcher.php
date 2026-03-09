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
        $this->api = new EvolutionApiV1();
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
            }
            
            // Enviar para paciente
            if ($event['send_to_patient'] && !empty($data['patient_phone'])) {
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
        $variables = [
            '{{profissional_nome}}' => $data['professional_name'] ?? '',
            '{{profissional_telefone}}' => $data['professional_phone'] ?? '',
            '{{paciente_nome}}' => $data['patient_name'] ?? '',
            '{{paciente_telefone}}' => $data['patient_phone'] ?? '',
            '{{id_atendimento}}' => $data['attendance_id'] ?? '',
            '{{data_atendimento}}' => $data['attendance_date'] ?? '',
            '{{data_consulta}}' => $data['appointment_date'] ?? '',
            '{{horario_consulta}}' => $data['appointment_time'] ?? '',
            '{{link_atendimento}}' => $data['attendance_link'] ?? '',
            '{{link_consulta}}' => $data['appointment_link'] ?? '',
            '{{id_preadmissao}}' => $data['preadmission_id'] ?? '',
            '{{data_inicio}}' => $data['start_date'] ?? '',
            '{{data_aprovacao}}' => $data['approval_date'] ?? '',
            '{{data_prazo}}' => $data['deadline_date'] ?? '',
            '{{id_paciente}}' => $data['patient_id'] ?? '',
            '{{data_cadastro}}' => $data['registration_date'] ?? '',
            '{{motivo_cancelamento}}' => $data['cancellation_reason'] ?? '',
        ];
        
        $message = $template;
        foreach ($variables as $var => $value) {
            $message = str_replace($var, (string)$value, $message);
        }
        
        return $message;
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
            
            // Registrar log
            $this->logMessage(
                $eventId,
                $recipientType,
                $phone,
                $recipientName,
                $message,
                $result['success'] ? 'sent' : 'failed',
                $result['error'] ?? null
            );
            
            return $result;
            
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
