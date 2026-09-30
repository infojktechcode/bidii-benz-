<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Log;
use App\Repositories\UserRepository;
use PDOException;

/**
 * Registration and login. Session writes happen in the controller —
 * this service returns results and never echoes or redirects.
 */
final class AuthService
{
    /**
     * Dummy hash for timing equalisation when the account does not exist,
     * so attackers cannot distinguish "unknown user" from "wrong password".
     */
    private const DUMMY_HASH = '$2y$10$WaepyoGjMmApK2ESehaWF.dmzeF0jxuOiwuPeWACQIa6wxjQc8RaK';

    public function __construct(private UserRepository $users)
    {
    }

    /**
     * Pure validation — no database access.
     *
     * @return array<string, string> validation errors (empty = valid)
     */
    public static function validateRegistration(
        string $fullName,
        string $email,
        string $phone,
        string $idNumber,
        string $password,
        string $confirm
    ): array {
        $errors = [];

        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Enter your full name (3–120 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (!preg_match('/^(?:0|\+254)(7|1)\d{8}$/', $phone)) {
            $errors['phone'] = 'Enter a valid Kenyan mobile number (07XXXXXXXX or +2547XXXXXXXX).';
        }
        if (!preg_match('/^\d{7,9}$/', $idNumber)) {
            $errors['id_number'] = 'Enter a valid national ID number (7–9 digits).';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($password) > 72) {
            // bcrypt silently truncates at 72 bytes — reject rather than confuse.
            $errors['password'] = 'Password must be 72 characters or fewer.';
        }
        if ($password !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }

        return $errors;
    }

    /**
     * @param array{email:string, phone:string, password_hash:string,
     *              full_name:string, id_number:string, city?:string} $data
     * @return array{ok:bool, user_id?:int, errors?:array<string,string>}
     */
    public function register(array $data): array
    {
        if ($this->users->findByEmail($data['email']) !== null) {
            return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
        }
        if ($this->users->findByPhone($data['phone']) !== null) {
            return ['ok' => false, 'errors' => ['phone' => 'An account with this phone number already exists.']];
        }
        if ($this->users->idNumberExists($data['id_number'])) {
            return ['ok' => false, 'errors' => ['id_number' => 'An account with this ID number already exists.']];
        }

        try {
            $userId = $this->users->createClient($data);
        } catch (PDOException $e) {
            // Race: another request inserted the same unique key between our
            // check and the insert. Map the DB error back to a field message.
            $msg = $e->getMessage();
            if (str_contains($msg, 'uq_users_email')) {
                return ['ok' => false, 'errors' => ['email' => 'An account with this email already exists.']];
            }
            if (str_contains($msg, 'uq_users_phone')) {
                return ['ok' => false, 'errors' => ['phone' => 'An account with this phone number already exists.']];
            }
            if (str_contains($msg, 'uq_clients_id_number')) {
                return ['ok' => false, 'errors' => ['id_number' => 'An account with this ID number already exists.']];
            }
            Log::error('Registration failed: ' . $e->getMessage());
            return ['ok' => false, 'errors' => ['email' => 'Registration failed. Please try again.']];
        }

        return ['ok' => true, 'user_id' => $userId];
    }

    /**
     * @return array{ok:bool, user?:array<string,mixed>, error?:string, blocked_for?:int}
     */
    public function login(string $identifier, string $password, string $ip): array
    {
        $identifier = trim($identifier);
        $now = time();
        $since = $now - RateLimiter::WINDOW_SECONDS;

        $idFails = $this->users->recentFailures($identifier, $since);
        $ipFails = $this->users->recentFailuresByIp($ip, $since);

        if (RateLimiter::isBlocked(count($idFails), count($ipFails))) {
            $retry = RateLimiter::retryAfterSeconds($idFails, RateLimiter::MAX_PER_IDENTIFIER, $now);
            if ($retry === 0) {
                $retry = RateLimiter::retryAfterSeconds($ipFails, RateLimiter::MAX_PER_IP, $now);
            }
            return [
                'ok' => false,
                'error' => 'Too many failed sign-in attempts. Please try again later.',
                'blocked_for' => $retry,
            ];
        }

        $user = $this->users->findByIdentifier($identifier);

        // Always run a verify to keep timing consistent for unknown accounts.
        $valid = password_verify($password, $user['password_hash'] ?? self::DUMMY_HASH);

        if ($user === null || !$valid) {
            $this->users->recordAttempt($identifier, $ip, false);
            Log::warning('Failed login', ['identifier' => $identifier, 'ip' => $ip]);
            return ['ok' => false, 'error' => 'Invalid email/phone or password.'];
        }

        if ($user['status'] !== 'active') {
            $this->users->recordAttempt($identifier, $ip, false);
            return ['ok' => false, 'error' => 'This account is suspended. Contact the office.'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->rehash((int) $user['id'], $password);
        }

        $this->users->recordAttempt($identifier, $ip, true);
        $this->users->clearFailures($identifier);
        $this->users->touchLastLogin((int) $user['id']);
        $user['last_login_at'] = date('Y-m-d H:i:s');

        return ['ok' => true, 'user' => $user];
    }

    /**
     * Profile self-service validation: the same rules registration applies,
     * minus the identifiers that cannot change here (email, ID number).
     *
     * @return array<string, string>
     */
    public static function validateProfile(string $fullName, string $phone, string $city): array
    {
        $errors = [];
        if (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120) {
            $errors['full_name'] = 'Enter your full name (3-120 characters).';
        }
        if (!preg_match('/^(?:0|\+254)(7|1)\d{8}$/', $phone)) {
            $errors['phone'] = 'Enter a valid Kenyan mobile number (07XXXXXXXX or +2547XXXXXXXX).';
        }
        if (mb_strlen($city) > 80) {
            $errors['city'] = 'City must be 80 characters or fewer.';
        }
        return $errors;
    }

    /**
     * Client profile self-service: validate, enforce phone uniqueness, then
     * persist phone (users) + name/city (clients) in one transaction.
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function updateProfile(int $userId, string $fullName, string $phone, ?string $city): array
    {
        $errors = self::validateProfile($fullName, $phone, $city ?? '');
        if ($errors === []) {
            $other = $this->users->findByPhone($phone);
            if ($other !== null && (int) $other['id'] !== $userId) {
                $errors['phone'] = 'That phone number is already in use.';
            }
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $this->users->updateProfile($userId, $fullName, $phone, $city);
        return ['ok' => true, 'errors' => []];
    }

    /**
     * Change the signed-in user's password with current-password re-entry.
     * Values never leave this method: callers receive field-level messages
     * only, and nothing is logged or audited from here.
     *
     * @return array{ok: bool, errors: array<string, string>}
     */
    public function changePassword(int $userId, string $current, string $new, string $confirm): array
    {
        $errors = [];
        if ($current === '') {
            $errors['current_password'] = 'Enter your current password.';
        }
        if (strlen($new) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif (strlen($new) > 72) {
            // bcrypt silently truncates at 72 bytes - reject rather than confuse.
            $errors['password'] = 'Password must be 72 characters or fewer.';
        }
        if ($new !== $confirm) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
        if ($new !== '' && !isset($errors['password']) && hash_equals($current, $new)) {
            $errors['password'] = 'Choose a password different from your current one.';
        }
        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        $user = $this->users->findById($userId);
        if ($user === null) {
            return ['ok' => false, 'errors' => ['current_password' => 'Account not found.']];
        }
        if (!password_verify($current, (string) $user['password_hash'])) {
            return ['ok' => false, 'errors' => ['current_password' => 'Current password is incorrect.']];
        }

        $this->users->updatePasswordHash($userId, password_hash($new, PASSWORD_DEFAULT));
        return ['ok' => true, 'errors' => []];
    }

    private function rehash(int $userId, string $password): void
    {
        try {
            $this->users->updatePasswordHash($userId, password_hash($password, PASSWORD_DEFAULT));
        } catch (\Throwable $e) {
            // Rehash failure must never block a successful login.
            Log::warning('Password rehash skipped: ' . $e->getMessage());
        }
    }
}
