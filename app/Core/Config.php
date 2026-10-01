<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Immutable application configuration assembled from defaults, .env and real env.
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $projectRoot, ?array $overrides = null): self
    {
        $env = Env::load($projectRoot . DIRECTORY_SEPARATOR . '.env');

        $get = static function (string $key, string $default = '') use ($env): string {
            if (isset($env[$key]) && $env[$key] !== '') {
                return $env[$key];
            }
            $real = getenv($key);
            if ($real !== false && $real !== '') {
                return $real;
            }
            return $default;
        };

        $values = [
            'project_root' => $projectRoot,
            'app_name' => $get('APP_NAME', 'Bidii Benz Rentals'),
            'app_env' => $get('APP_ENV', 'development'),
            'app_url' => rtrim($get('APP_URL', ''), '/'),
            'app_debug' => $get('APP_DEBUG', '1') === '1',
            'db' => [
                'host' => $get('DB_HOST', '127.0.0.1'),
                'port' => (int) $get('DB_PORT', '3306'),
                'name' => $get('DB_NAME', 'bidii_benz'),
                'user' => $get('DB_USER', 'root'),
                'pass' => $get('DB_PASS', ''),
                'charset' => 'utf8mb4',
            ],
            'session' => [
                'idle_timeout' => (int) $get('SESSION_IDLE_TIMEOUT', '1800'),
                'absolute_timeout' => (int) $get('SESSION_ABSOLUTE_TIMEOUT', '28800'),
                'name' => 'bidii_sess',
            ],
            'uploads' => [
                'max_bytes' => (int) $get('UPLOAD_MAX_BYTES', '2097152'),
                'dir' => $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'uploads',
            ],
            'mpesa' => [
                'env' => $get('MPESA_ENV', 'mock'),
                'consumer_key' => $get('MPESA_CONSUMER_KEY', ''),
                'consumer_secret' => $get('MPESA_CONSUMER_SECRET', ''),
                'shortcode' => $get('MPESA_SHORTCODE', ''),
                'passkey' => $get('MPESA_PASSKEY', ''),
                'callback_url' => $get('MPESA_CALLBACK_URL', ''),
                'callback_secret' => $get('MPESA_CALLBACK_SECRET', ''),
            ],
            'audit' => [
                'retention_months' => (int) $get('AUDIT_RETENTION_MONTHS', '12'),
            ],
            'logs_dir' => $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'logs',
        ];

        if (is_array($overrides)) {
            $values = array_replace_recursive($values, $overrides);
        }

        return new self($values);
    }

    /**
     * Dot-notation getter: get('db.host').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->values;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public function isProduction(): bool
    {
        return $this->get('app_env') === 'production';
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
