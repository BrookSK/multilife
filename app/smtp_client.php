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

        if ($this->host === '' || $this->port <= 0) {
            throw new RuntimeException('SMTP saída não configurado (host/port).');
        }
    }

    private function connect()
    {
        $targetHost = $this->host;
        if ($this->encryption === 'ssl') {
            $targetHost = 'ssl://' . $targetHost;
        }

        $fp = @fsockopen($targetHost, $this->port, $errno, $errstr, 15);
        if (!$fp) {
            throw new RuntimeException('Falha ao conectar SMTP: ' . $errstr);
        }

        stream_set_timeout($fp, 15);
        $this->expect($fp, [220]);

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
            $this->write($fp, 'STARTTLS');
            $this->expect($fp, [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Falha ao iniciar TLS no SMTP.');
            }
            $this->write($fp, 'EHLO multilife');
            $this->readMulti($fp);
        }

        if ($this->username !== '' && $this->password !== '') {
            $this->write($fp, 'AUTH LOGIN');
            $this->expect($fp, [334]);
            $this->write($fp, base64_encode($this->username));
            $this->expect($fp, [334]);
            $this->write($fp, base64_encode($this->password));
            $this->expect($fp, [235]);
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

    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $bodyText): void
    {
        $fromEmail = trim($fromEmail);
        $toEmail = trim($toEmail);
        if ($fromEmail === '' || $toEmail === '') {
            throw new RuntimeException('SMTP: from/to inválidos.');
        }

        $fp = $this->connect();
        try {
            $this->write($fp, 'MAIL FROM:<' . $fromEmail . '>');
            $this->expect($fp, [250]);

            $this->write($fp, 'RCPT TO:<' . $toEmail . '>');
            $this->expect($fp, [250, 251]);

            $this->write($fp, 'DATA');
            $this->expect($fp, [354]);

            $fromHeader = $fromName !== '' ? $this->encodeHeader($fromName) . ' <' . $fromEmail . '>' : $fromEmail;

            $headers = [];
            $headers[] = 'From: ' . $fromHeader;
            $headers[] = 'To: <' . $toEmail . '>';
            $headers[] = 'Subject: ' . $this->encodeHeader($subject);
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: 8bit';

            $data = implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
            $data = str_replace("\r\n.", "\r\n..", $data);

            fwrite($fp, $data . "\r\n.\r\n");
            $this->expect($fp, [250]);

            $this->write($fp, 'QUIT');
            $this->expect($fp, [221, 250]);
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
