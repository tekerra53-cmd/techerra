<?php
declare(strict_types=1);

require_once __DIR__ . '/chat-config.php';

function techerra_csv_list(string $raw): array
{
    if (trim($raw) === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function techerra_current_host(): string
{
    $host = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
    if ($host === '') {
        return '';
    }
    $parts = explode(':', $host, 2);
    return trim($parts[0]);
}

function techerra_client_ip(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function techerra_admin_network_allowed(): bool
{
    $enforce = defined('TECHERRA_CHAT_ADMIN_ENFORCE_NETWORK_RESTRICTION')
        ? (bool) TECHERRA_CHAT_ADMIN_ENFORCE_NETWORK_RESTRICTION
        : false;
    if (!$enforce) {
        return true;
    }

    $allowedHosts = techerra_csv_list((string) (defined('TECHERRA_CHAT_ADMIN_ALLOWED_HOSTS') ? TECHERRA_CHAT_ADMIN_ALLOWED_HOSTS : ''));
    $allowedIps = techerra_csv_list((string) (defined('TECHERRA_CHAT_ADMIN_ALLOWED_IPS') ? TECHERRA_CHAT_ADMIN_ALLOWED_IPS : ''));

    $host = techerra_current_host();
    $ip = techerra_client_ip();

    if (!empty($allowedHosts)) {
        $hostOk = false;
        foreach ($allowedHosts as $h) {
            if (strtolower($h) === $host) {
                $hostOk = true;
                break;
            }
        }
        if (!$hostOk) {
            return false;
        }
    }

    if (!empty($allowedIps)) {
        $ipOk = false;
        foreach ($allowedIps as $allowedIp) {
            if ($allowedIp === $ip) {
                $ipOk = true;
                break;
            }
        }
        if (!$ipOk) {
            return false;
        }
    }

    return true;
}

function techerra_block_if_not_allowed(): void
{
    if (techerra_admin_network_allowed()) {
        return;
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Access denied.';
    exit;
}

