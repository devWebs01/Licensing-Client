<?php

namespace DevWebs01\LicensingClient\Services;

final class FingerprintCollector
{
    private ?string $cachedFingerprint = null;

    public function collect(): string
    {
        if ($this->cachedFingerprint !== null) {
            return $this->cachedFingerprint;
        }

        $components = [
            'hostname' => php_uname('n'),
            'os' => php_uname('s').php_uname('r'),
            'app_path' => $this->getAppPath(),
            'database' => $this->getDatabaseName(),
            'php_version' => PHP_VERSION,
            'app_key_hash' => $this->getAppKeyHash(),
        ];

        $raw = implode('|', array_map(
            fn (string $key, string $value): string => "{$key}:{$value}",
            array_keys($components),
            $components
        ));

        $this->cachedFingerprint = hash('sha256', $raw);

        return $this->cachedFingerprint;
    }

    public function fingerprint(): string
    {
        return $this->collect();
    }

    /**
     * @return array<string, string>
     */
    public function collectData(): array
    {
        return [
            'hostname' => php_uname('n'),
            'os' => php_uname('s'),
            'kernel' => php_uname('r'),
            'app_path' => $this->getAppPath(),
            'database' => $this->getDatabaseName(),
            'php_version' => PHP_VERSION,
            'app_key_hash' => $this->getAppKeyHash(),
        ];
    }

    private function getAppPath(): string
    {
        $path = defined('base_path') ? base_path() : ($_SERVER['DOCUMENT_ROOT'] ?? __DIR__);

        return realpath($path) ?: $path;
    }

    private function getDatabaseName(): string
    {
        try {
            $connection = config('database.default', 'mysql');

            return (string) config("database.connections.{$connection}.database", 'unknown');
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function getAppKeyHash(): string
    {
        try {
            $key = function_exists('config') ? config('app.key') : null;

            return $key ? hash('sha256', $key) : 'unknown';
        } catch (\Throwable) {
            return 'unknown';
        }
    }
}
