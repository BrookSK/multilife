<?php

declare(strict_types=1);

final class SmtpClient
{
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;

    public function __construct(?string $host = null, ?int $port = null, ?string $encryption = null, ?string $username = null, ?string $password = null)
    {
        $this->host = trim((string)($host ?? admin_setting_get('smtp.out.host', '')));
        $this->port = (int)($port ?? (int)admin_setting_get('smtp.out.port', '587'));
        $this->encryption = strtolower(trim((string)($encryption ?? admin_setting_get('smtp.out.encryption', 'tls'))));
        $this->username = trim((string)($username ?? admin_setting_get('smtp.out.username', '')));
        $this->password = (string)($password ?? admin_setting_get('smtp.out.password', ''));

        // Auto-detectar SSL para porta 465
        if ($this->port === 465 && ($this->encryption === '' || $this->encryption === 'none')) {
            $this->encryption = 'ssl';
            error_log("[SMTP] Auto-detectado SSL para porta 465");
        }
        
        // Auto-detectar TLS para porta 587
        if ($this->port === 587 && ($this->encryption === '' || $this->encryption === 'none')) {
            $this->encryption = 'tls';
            error_log("[SMTP] Auto-detectado TLS para porta 587");
        }

        if ($this->host === '' || $this->port <= 0) {
            throw new RuntimeException('SMTP saída não configurado (host/port).');
        }
    }

    private function connect()
    {
        error_log("[SMTP] Conectando a {$this->host}:{$this->port} (encryption: {$this->encryption})");
        
        $targetHost = $this->host;
        if ($this->encryption === 'ssl') {
            $targetHost = 'ssl://' . $targetHost;
        }

        $fp = @fsockopen($targetHost, $this->port, $errno, $errstr, 30);
        if (!$fp) {
            error_log("[SMTP] ERRO ao conectar: $errstr (errno: $errno)");
            throw new RuntimeException('Falha ao conectar SMTP: ' . $errstr);
        }

        error_log("[SMTP] Conexão estabelecida");
        stream_set_timeout($fp, 30);
        
        error_log("[SMTP] Aguardando banner inicial (220)...");
        $this->expect($fp, [220]);
        error_log("[SMTP] Banner recebido");

        $this->write($fp, 'EHLO multilife');
        $ehlo = $this->readMulti($fp);

        $supportsStartTls = false;
        foreach ($ehlo as $ln) {
            if (stripos($ln, 'STARTTLS') !== false) {
                $supportsStartTls = true;
                break;
            }
        }

        if ($this->encryption === 'tls' && $supportsStartTls) {
            error_log("[SMTP] Iniciando STARTTLS...");
            $this->write($fp, 'STARTTLS');
            $this->expect($fp, [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log("[SMTP] ERRO ao habilitar TLS");
                throw new RuntimeException('Falha ao iniciar TLS no SMTP.');
            }
            error_log("[SMTP] TLS habilitado com sucesso");
            $this->write($fp, 'EHLO multilife');
            $this->readMulti($fp);
        }

        if ($this->username !== '' && $this->password !== '') {
            error_log("[SMTP] Autenticando como: {$this->username}");
            $this->write($fp, 'AUTH LOGIN');
            $this->expect($fp, [334]);
            $this->write($fp, base64_encode($this->username));
            $this->expect($fp, [334]);
            $this->write($fp, base64_encode($this->password));
            $this->expect($fp, [235]);
            error_log("[SMTP] Autenticação bem-sucedida");
        }

        return $fp;
    }

    private function write($fp, string $line): void
    {
        fwrite($fp, $line . "\r\n");
    }

    private function readLine($fp): string
    {
        $line = fgets($fp);
        if ($line === false) {
            throw new RuntimeException('SMTP: resposta vazia.');
        }
        return rtrim($line, "\r\n");
    }

    private function readMulti($fp): array
    {
        $lines = [];
        while (true) {
            $ln = $this->readLine($fp);
            $lines[] = $ln;
            if (!preg_match('/^\d{3}-/', $ln)) {
                break;
            }
        }
        return $lines;
    }

    private function expect($fp, array $codes): void
    {
        $line = $this->readLine($fp);
        $code = (int)substr($line, 0, 3);
        if (!in_array($code, $codes, true)) {
            throw new RuntimeException('SMTP erro: ' . $line);
        }
    }

    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText, ?string $inReplyTo = null, ?string $references = null): string
    {
        $fromEmail = trim($fromEmail);
        $toEmail = trim($toEmail);
        if ($fromEmail === '' || $toEmail === '') {
            throw new RuntimeException('SMTP: from/to inválidos.');
        }

        error_log("[SMTP] Enviando e-mail de $fromEmail para $toEmail");
        error_log("[SMTP] Assunto: $subject");
        if ($inReplyTo) {
            error_log("[SMTP] In-Reply-To: $inReplyTo");
        }
        
        // Gerar Message-ID único para rastreamento
        $messageId = '<' . uniqid('multilife-', true) . '@' . gethostname() . '>';
        error_log("[SMTP] Message-ID gerado: $messageId");
        
        $fp = $this->connect();
        try {
            error_log("[SMTP] Enviando MAIL FROM...");
            $this->write($fp, 'MAIL FROM:<' . $fromEmail . '>');
            $this->expect($fp, [250]);
            error_log("[SMTP] MAIL FROM aceito");

            error_log("[SMTP] Enviando RCPT TO...");
            $this->write($fp, 'RCPT TO:<' . $toEmail . '>');
            $this->expect($fp, [250, 251]);
            error_log("[SMTP] RCPT TO aceito");

            error_log("[SMTP] Iniciando DATA...");
            $this->write($fp, 'DATA');
            $this->expect($fp, [354]);
            error_log("[SMTP] Servidor pronto para receber dados");

            $fromHeader = $fromName !== '' ? $this->encodeHeader($fromName) . ' <' . $fromEmail . '>' : $fromEmail;

            $headers = [];
            $headers[] = 'From: ' . $fromHeader;
            $headers[] = 'To: <' . $toEmail . '>';
            $headers[] = 'Subject: ' . $this->encodeHeader($subject);
            $headers[] = 'Message-ID: ' . $messageId;
            $headers[] = 'Date: ' . date('r');
            $headers[] = 'MIME-Version: 1.0';
            
            // Threading headers para manter conversação
            if ($inReplyTo !== null && $inReplyTo !== '') {
                $headers[] = 'In-Reply-To: ' . $inReplyTo;
            }
            if ($references !== null && $references !== '') {
                $headers[] = 'References: ' . $references;
            } elseif ($inReplyTo !== null && $inReplyTo !== '') {
                // Se não tem References mas tem In-Reply-To, usar In-Reply-To como References
                $headers[] = 'References: ' . $inReplyTo;
            }
            
            // Detectar se o corpo é HTML
            $isHtml = stripos($bodyText, '<html') !== false || stripos($bodyText, '<!DOCTYPE') !== false || stripos($bodyText, '<div') !== false;
            if ($isHtml) {
                $headers[] = 'Content-Type: text/html; charset=UTF-8';
            } else {
                $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            }
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
            $data = str_replace("\r\n.", "\r\n..", $data);

            error_log("[SMTP] Enviando corpo do e-mail (" . strlen($data) . " bytes)...");
            fwrite($fp, $data . "\r\n.\r\n");
            $this->expect($fp, [250]);
            error_log("[SMTP] E-mail aceito pelo servidor");

            error_log("[SMTP] Encerrando conexão...");
            $this->write($fp, 'QUIT');
            $this->expect($fp, [221, 250]);
            error_log("[SMTP] Conexão encerrada com sucesso");
            
            return $messageId;
        } finally {
            fclose($fp);
        }
    }

    private function encodeHeader(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[\x20-\x7E]+$/', $value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
