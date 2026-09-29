<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Allow-list input reading, sanitisation and validation.
 * Only keys explicitly requested are ever read.
 */
final class Input
{
    /** @var array<string, mixed> */
    private array $data;

    /** @param array<string, mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function fromRequest(): self
    {
        return new self(array_merge($_GET, $_POST));
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function raw(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key, int $maxLen = 255): string
    {
        $value = $this->data[$key] ?? '';
        if (is_array($value)) {
            return '';
        }
        $value = trim((string) $value);
        if (strlen($value) > $maxLen) {
            $value = substr($value, 0, $maxLen);
        }
        return $value;
    }

    public function int(string $key): ?int
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }
        if (!preg_match('/^-?\d+$/', (string) $value)) {
            return null;
        }
        return (int) $value;
    }

    public function email(string $key): string
    {
        return filter_var($this->string($key, 320), FILTER_VALIDATE_EMAIL) ?: '';
    }

    public function date(string $key): ?string
    {
        $value = $this->string($key, 10);
        if ($value === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            return null;
        }
        return $value;
    }

    /**
     * Kenyan mobile number: 07XXXXXXXX / 01XXXXXXXX / +2547XXXXXXXX / +2541XXXXXXXX.
     */
    public function phone(string $key): string
    {
        $value = preg_replace('/[\s\-\.]/', '', $this->string($key, 20)) ?? '';
        if (preg_match('/^(?:0|\+254)(7|1)\d{8}$/', $value)) {
            return $value;
        }
        return '';
    }

    public function slug(string $key, int $maxLen = 64): string
    {
        $value = strtolower($this->string($key, $maxLen));
        $value = preg_replace('/[^a-z0-9\-_]/', '-', $value) ?? '';
        return trim($value, '-');
    }

    /**
     * Validate multiple fields at once.
     *
     * Rules per field: required|optional plus type: string|int|email|date|phone|id
     * Example: ['email' => 'required|email', 'age' => 'optional|int']
     *
     * @param array<string, string> $rules
     * @return array<string, string> field => error message (empty = all valid)
     */
    public function validate(array $rules): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $required = in_array('required', $ruleList, true);
            $type = 'string';
            foreach (['string', 'int', 'email', 'date', 'phone', 'id', 'slug'] as $t) {
                if (in_array($t, $ruleList, true)) {
                    $type = $t;
                }
            }

            $exists = $this->has($field) && $this->string($field) !== '';
            if (!$exists) {
                if ($required) {
                    $errors[$field] = ucfirst($field) . ' is required.';
                }
                continue;
            }

            switch ($type) {
                case 'email':
                    if ($this->email($field) === '') {
                        $errors[$field] = ucfirst($field) . ' must be a valid email address.';
                    }
                    break;
                case 'date':
                    if ($this->date($field) === null) {
                        $errors[$field] = ucfirst($field) . ' must be a valid date (YYYY-MM-DD).';
                    }
                    break;
                case 'phone':
                    if ($this->phone($field) === '') {
                        $errors[$field] = ucfirst($field) . ' must be a valid Kenyan mobile number.';
                    }
                    break;
                case 'int':
                    if ($this->int($field) === null) {
                        $errors[$field] = ucfirst($field) . ' must be a whole number.';
                    }
                    break;
                case 'id':
                    if (($this->int($field) ?? 0) < 1) {
                        $errors[$field] = ucfirst($field) . ' is invalid.';
                    }
                    break;
                case 'string':
                    if (is_array($this->raw($field))) {
                        $errors[$field] = ucfirst($field) . ' is invalid.';
                    }
                    break;
            }
        }
        return $errors;
    }
}
