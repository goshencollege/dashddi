<?php

namespace App\Service;

use App\Message\SyslogMessage;

class SyslogForwarderService
{
    private mixed $socket = null;
    private ?string $connectedHost = null;
    private ?int $connectedPort = null;

    public function send(string $protocol, string $host, int $port, SyslogMessage $msg): void
    {
        $formatted = $this->format($msg);

        if ($protocol === 'tcp') {
            $this->sendTcp($host, $port, $formatted);
        } else {
            $this->sendUdp($host, $port, $formatted);
        }
    }

    private function format(SyslogMessage $msg): string
    {
        $ts       = $msg->occurredAt->format(\DateTimeInterface::RFC3339_EXTENDED);
        $hostname = parse_url($_ENV['DEFAULT_URI'] ?? '', PHP_URL_HOST) ?: gethostname() ?: 'dashddi';
        $header   = "<134>1 {$ts} {$hostname} dashddi - - -";

        $kv = 'action=' . $this->quoteKv($msg->action);
        if ($msg->entityType !== null) {
            $kv .= ' entity_type=' . $this->quoteKv($msg->entityType);
        }
        if ($msg->entityId !== null) {
            $kv .= ' entity_id=' . $msg->entityId;
        }
        $kv .= ' entity_label=' . $this->quoteKv($this->trunc($msg->entityLabel, 150));
        if ($msg->userIdentifier !== null) {
            $kv .= ' user=' . $this->quoteKv($msg->userIdentifier);
        }
        if ($msg->ipAddress !== null) {
            $kv .= ' ip=' . $this->quoteKv($msg->ipAddress);
        }

        if (!empty($msg->changedFields)) {
            foreach ($msg->changedFields as $field => [$old, $new]) {
                $oldStr = $this->trunc((string) ($old ?? 'null'), 150);
                $newStr = $this->trunc((string) ($new ?? 'null'), 150);
                $safeField = preg_replace('/[^\w]/', '_', $field);
                $kv .= ' ' . $safeField . '=' . $this->quoteKv($oldStr) . '->' . $this->quoteKv($newStr);
            }
        }

        return $header . ' ' . $kv;
    }

    private function quoteKv(string $value): string
    {
        // Strip control characters (including newlines) to prevent log injection
        $value = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        if ($value === '' || preg_match('/[\s=>"<]/', $value)) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }
        return $value;
    }

    private function trunc(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) . '…' : $value;
    }

    private function sendUdp(string $host, int $port, string $msg): void
    {
        // Clamp to 1024 bytes for UDP compatibility with older syslog servers
        if (strlen($msg) > 1024) {
            $msg = substr($msg, 0, 1023) . '…';
        }
        $fp = @fsockopen('udp://' . $host, $port, $errno, $errstr, 1.0);
        if ($fp === false) {
            return;
        }
        @fwrite($fp, $msg);
        fclose($fp);
    }

    private function sendTcp(string $host, int $port, string $msg): void
    {
        // Reconnect if endpoint changed
        if ($this->socket !== null
            && ($this->connectedHost !== $host || $this->connectedPort !== $port)
        ) {
            @fclose($this->socket);
            $this->socket        = null;
            $this->connectedHost = null;
            $this->connectedPort = null;
        }

        if ($this->socket === null) {
            $fp = @fsockopen('tcp://' . $host, $port, $errno, $errstr, 3.0);
            if ($fp === false) {
                return;
            }
            $this->socket        = $fp;
            $this->connectedHost = $host;
            $this->connectedPort = $port;
        }

        $result = @fwrite($this->socket, $msg . "\n");
        if ($result === false) {
            @fclose($this->socket);
            $this->socket        = null;
            $this->connectedHost = null;
            $this->connectedPort = null;
        }
    }

    public function __destruct()
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
        }
    }
}
