<?php

namespace App\Service;

use App\Entity\AppSetting;
use App\Repository\AppSettingRepository;

class SmtpMailerService
{
    public function __construct(
        private readonly AppSettingRepository $settingRepo,
    ) {}

    public function isConfigured(): bool
    {
        $s = $this->settingRepo->getInstance();
        return $s->getSmtpHost() !== null && $s->getSmtpFromEmail() !== null;
    }

    public function send(string $to, string $subject, string $body): void
    {
        $s = $this->settingRepo->getInstance();

        $host       = $s->getSmtpHost() ?? throw new \RuntimeException('SMTP host not configured.');
        $port       = $s->getSmtpPort() ?? 587;
        $encryption = $s->getSmtpEncryption() ?? 'tls';
        $username   = $s->getSmtpUsername();
        $password   = $s->getSmtpPassword();
        $fromEmail  = $s->getSmtpFromEmail() ?? throw new \RuntimeException('SMTP from address not configured.');
        $fromName   = $s->getSmtpFromName() ?: 'DashDDI';

        $timeout = 10;
        $errno   = 0;
        $errstr  = '';

        if ($encryption === 'ssl') {
            $socket = stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout);
        } else {
            $socket = stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        }

        if ($socket === false) {
            throw new \RuntimeException("SMTP connection failed: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, $timeout);

        $this->expect($socket, '220');
        $this->send_line($socket, "EHLO " . gethostname());
        $ehloResponse = $this->read_all($socket);

        if ($encryption === 'tls') {
            $this->send_line($socket, 'STARTTLS');
            $this->expect_raw($socket, '220');
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $this->send_line($socket, "EHLO " . gethostname());
            $this->read_all($socket);
        }

        if ($username !== null && $password !== null) {
            $this->send_line($socket, 'AUTH LOGIN');
            $this->expect($socket, '334');
            $this->send_line($socket, base64_encode($username));
            $this->expect($socket, '334');
            $this->send_line($socket, base64_encode($password));
            $this->expect($socket, '235');
        }

        $this->send_line($socket, "MAIL FROM:<{$fromEmail}>");
        $this->expect($socket, '250');

        $this->send_line($socket, "RCPT TO:<{$to}>");
        $this->expect($socket, '250');

        $this->send_line($socket, 'DATA');
        $this->expect($socket, '354');

        $date    = date('r');
        $msgId   = sprintf('<%s@%s>', uniqid('dashddi', true), gethostname());
        $headers = implode("\r\n", [
            "Date: {$date}",
            "From: {$fromName} <{$fromEmail}>",
            "To: {$to}",
            "Subject: {$subject}",
            "Message-ID: {$msgId}",
            "MIME-Version: 1.0",
            "Content-Type: text/plain; charset=UTF-8",
            "Content-Transfer-Encoding: quoted-printable",
        ]);

        $encoded = quoted_printable_encode($body);
        fwrite($socket, "{$headers}\r\n\r\n{$encoded}\r\n.\r\n");
        $this->expect($socket, '250');

        $this->send_line($socket, 'QUIT');
        fclose($socket);
    }

    private function send_line($socket, string $line): void
    {
        fwrite($socket, $line . "\r\n");
    }

    private function read_all($socket): string
    {
        $response = '';
        while (($line = fgets($socket, 512)) !== false) {
            $response .= $line;
            // Multi-line responses use "NNN-" prefix; single/last line uses "NNN "
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        return $response;
    }

    private function expect($socket, string $code): string
    {
        return $this->expect_raw($socket, $code);
    }

    private function expect_raw($socket, string $code): string
    {
        $response = $this->read_all($socket);
        if (!str_starts_with($response, $code)) {
            throw new \RuntimeException("SMTP error — expected {$code}, got: " . trim($response));
        }
        return $response;
    }
}
