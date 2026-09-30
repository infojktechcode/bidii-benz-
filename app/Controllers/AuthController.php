<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Guard;
use App\Core\Input;
use App\Core\Log;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RateLimiter;
use PDO;

/**
 * Authentication endpoints. Every POST is CSRF-validated;
 * every response follows Post/Redirect/Get.
 */
final class AuthController
{
    private AuthService $auth;
    private PDO $pdo;

    public function __construct()
    {
        $config = Config::load(dirname(__DIR__, 2));
        $this->pdo = Database::connect($config);
        $this->auth = new AuthService(new UserRepository($this->pdo));
    }

    // --- login ---------------------------------------------------------------

    public function showLogin(array $params = []): void
    {
        if (Guard::check()) {
            redirect('/');
        }
        View::render('auth/login', [
            'title' => 'Sign in — Bidii Benz Rentals',
            'error' => Session::flash('error'),
            'old_identifier' => (string) Session::pull('old_identifier', ''),
        ]);
    }

    public function login(array $params = []): void
    {
        if (!Csrf::validate('login', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }
        if (Guard::check()) {
            redirect('/');
        }

        $input = Input::fromRequest();
        $identifier = $input->string('identifier', 190);
        $password = (string) ($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            Session::flash('error', 'Enter your email/phone and password.');
            Session::set('old_identifier', $identifier);
            redirect('/login');
        }

        $ip = $this->clientIp();
        $result = $this->auth->login($identifier, $password, $ip);

        if (!$result['ok']) {
            $this->audit(null, 'auth.login_failed', $ip, 'identifier=' . $identifier);
            Session::flash('error', $result['error']);
            Session::set('old_identifier', $identifier);
            redirect('/login');
        }

        $user = $result['user'];
        $this->establishSession($user);
        $this->audit((int) $user['id'], 'auth.login', $ip, 'role=' . $user['role']);

        $intended = Session::pull('intended', '/');
        // Must be a single-slash application path; "//host" would be an
        // open redirect once the base path is empty (production).
        if (!is_string($intended) || $intended === ''
            || $intended[0] !== '/' || str_starts_with($intended, '//')) {
            $intended = '/';
        }
        redirect($intended);
    }

    // --- register ------------------------------------------------------------

    public function showRegister(array $params = []): void
    {
        if (Guard::check()) {
            redirect('/');
        }
        View::render('auth/register', [
            'title' => 'Create account — Bidii Benz Rentals',
            'errors' => Session::pull('reg_errors', []),
            'old' => Session::pull('reg_old', []),
            'success' => Session::flash('success'),
        ]);
    }

    public function register(array $params = []): void
    {
        if (!Csrf::validate('register', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }
        if (Guard::check()) {
            redirect('/');
        }

        // Count every attempt (invalid payloads included) after CSRF and
        // before any validation or database work — a blocked request writes
        // no user row and verifies nothing.
        $now = time();
        $times = array_values(array_filter(
            (array) Session::get('register_times', []),
            static fn (mixed $t): bool => is_int($t)
        ));
        if (RateLimiter::overLimit($times, RateLimiter::MAX_REGISTRATIONS_PER_WINDOW, $now)) {
            http_response_code(429);
            View::render('errors/429', ['reason' => 'registration'], 429);
            exit;
        }
        $times[] = $now;
        Session::set('register_times', $times);

        $input = Input::fromRequest();
        $fullName = $input->string('full_name', 120);
        $email = strtolower($input->string('email', 190));
        $phone = $input->string('phone', 20);
        $idNumber = preg_replace('/\D/', '', $input->string('id_number', 20)) ?? '';
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $errors = AuthService::validateRegistration(
            $fullName,
            $email,
            $phone,
            $idNumber,
            $password,
            $confirm
        );

        if ($errors !== []) {
            Session::set('reg_errors', $errors);
            Session::set('reg_old', [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'id_number' => $idNumber,
            ]);
            redirect('/register');
        }

        $result = $this->auth->register([
            'email' => $email,
            'phone' => $phone,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'full_name' => $fullName,
            'id_number' => $idNumber,
            'city' => $input->string('city', 80) ?: null,
        ]);

        if (!$result['ok']) {
            Session::set('reg_errors', $result['errors']);
            Session::set('reg_old', [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
                'id_number' => $idNumber,
            ]);
            redirect('/register');
        }

        $this->audit($result['user_id'], 'auth.register', $this->clientIp(), 'role=client');
        Session::flash('success', 'Your account has been created. Please sign in.');
        redirect('/login');
    }

    // --- logout ---------------------------------------------------------------

    public function logout(array $params = []): void
    {
        if (!Csrf::validate('logout', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }

        $userId = Guard::userId();
        if ($userId !== null) {
            $this->audit($userId, 'auth.logout', $this->clientIp(), null);
        }

        Session::destroy();
        // Fresh session so the flash message survives the redirect.
        // destroy() deleted the client cookie; regenerate() issues a NEW id
        // (emitting a replacement Set-Cookie) or the flash would be lost.
        Session::start(Config::load(dirname(__DIR__, 2)));
        Session::regenerate();
        Csrf::rotate();
        Session::flash('success', 'You have been signed out.');
        redirect('/');
    }

    // --- privacy notice --------------------------------------------------------

    public function privacy(array $params = []): void
    {
        View::render('legal/privacy', [
            'title' => 'Privacy Notice — Bidii Benz Rentals',
        ]);
    }

    // --- helpers ----------------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     */
    private function establishSession(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        Session::set('role', (string) $user['role']);
        Session::set('user_email', (string) $user['email']);
        Session::set('user_name', $this->displayName($user));
        Csrf::rotate();
    }

    /** @param array<string, mixed> $user */
    private function displayName(array $user): string
    {
        $stmt = $this->pdo->prepare('SELECT full_name FROM clients WHERE user_id = ?');
        $stmt->execute([(int) $user['id']]);
        $name = $stmt->fetchColumn();
        return is_string($name) && $name !== '' ? $name : (string) $user['email'];
    }

    private function clientIp(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return is_string($ip) ? substr($ip, 0, 45) : '0.0.0.0';
    }

    private function forbidCsrf(): never
    {
        http_response_code(403);
        View::render('errors/403', [], 403);
        exit;
    }

    private function audit(?int $userId, string $action, string $ip, ?string $detail): void
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO audit_logs (user_id, role, action, ip_address, detail)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId,
                $userId !== null ? (string) (Session::get('role') ?? '') : null,
                $action,
                $ip,
                $detail,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit write failed: ' . $e->getMessage());
        }
    }
}
