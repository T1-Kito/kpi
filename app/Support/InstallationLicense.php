<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

final class InstallationLicense
{
    public static function machineCode(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') throw new RuntimeException('Bản cài này cần máy chủ Windows.');
        $process = new Process(['reg.exe', 'query', 'HKLM\SOFTWARE\Microsoft\Cryptography', '/v', 'MachineGuid', '/reg:64']);
        $process->setTimeout(3)->mustRun();
        if (!preg_match('/MachineGuid\s+REG_SZ\s+([^\r\n]+)/i', $process->getOutput(), $match)) throw new RuntimeException('Không lấy được mã máy chủ.');
        return strtoupper(hash('sha256', 'VK-CRM|'.strtoupper(trim($match[1]))));
    }

    public static function validate(string $json, string $publicKey, string $machine): array
    {
        if (strlen($json) > 32768) throw new RuntimeException('File bản quyền quá lớn.');
        $envelope = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        $payload = base64_decode($envelope['payload'] ?? '', true);
        $signature = base64_decode($envelope['signature'] ?? '', true);
        if (!$payload || !$signature || openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException('Chữ ký bản quyền không hợp lệ.');
        $data = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        if (($data['product'] ?? '') !== 'VK-CRM' || ($data['version'] ?? 0) !== 1 || !is_string($data['company'] ?? null) || trim($data['company']) === '') throw new RuntimeException('File bản quyền không hợp lệ.');
        if (!hash_equals($machine, $data['machine'] ?? '')) throw new RuntimeException('Bản quyền dành cho máy chủ khác.');
        if (!empty($data['expires']) && new \DateTimeImmutable($data['expires']) <= new \DateTimeImmutable('now')) throw new RuntimeException('Bản quyền đã hết hạn.');
        return $data;
    }
}
