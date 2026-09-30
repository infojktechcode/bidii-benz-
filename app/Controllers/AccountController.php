<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Guard;
use App\Core\Session;
use App\Core\View;
use App\Repositories\UserRepository;
use App\Services\AuthService;
use App\Services\RateLimiter;
use PDO;

/**
 * Signed-in account self-service: view/edit the client profile and change
 * the password. Profile edits are client-only (route guard); the password
 * change is open to every authenticated role and requires the current
 * password, CSRF and a session-scoped throttle.
 *
 * Every outcome is Post/Redirect/Get; nothing here echoes submitted input
 * without View::e, and no password value ever reaches the audit trail.
 */
final class AccountController
{
    private UserRepository $users;
    private AuthService $auth;

    public function __construct()
    {
        $config = Config::load(dirname(__DIR__, 2));
        $pdo = Database::connect($config);
        $this->users = new UserRepository($pdo);
        $this->auth = new AuthService($this->users);
    }

    public function show(array $params = []): void
    {
        $userId = Guard::userId();
        $user = $userId !== null ? $this->users->findById($userId) : null;
        if ($user === null) {
            // The account vanished between revalidation and here: fail closed.
            Session::destroy();
            redirect('/login');
        }

        View::render('account/show', [
            'title' => 'My account — Bidii Benz Rentals',
            'user' => $user,
            'profile' => Guard::hasRole(Guard::ROLE_CLIENT)
                ? $this->users->findProfileByUserId((int) $user['id'])
                : null,
            'profileErrors' => Session::pull('profile_errors', []),
            'profileOld' => Session::pull('profile_old', []),
            'passwordErrors' => Session::pull('password_errors', []),
        ]);
    }

    public function updateProfile(array $params = []): void
    {
        if (!Csrf::validate('account_profile', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }

        $userId = (int) Guard::userId();
        $fullName = trim((string) ($_POST['full_name'] ?? ''));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $city = trim((string) ($_POST['city'] ?? ''));

        $result = $this->auth->updateProfile($userId, $fullName, $phone, $city === '' ? null : $city);
        if (!$result['ok']) {
            Session::set('profile_errors', $result['errors']);
            Session::set('profile_old', [
                'full_name' => $fullName,
                'phone' => $phone,
                'city' => $city,
            ]);
            redirect('/account');
        }

        // Keep the header's display name in step with the stored one.
        Session::set('user_name', $fullName);
        Audit::asCurrentActor('account.profile_updated', 'clients', null, 'fields=full_name,phone,city');
        Session::flash('success', 'Your profile has been updated.');
        redirect('/account');
    }

    public function changePassword(array $params = []): void
    {
        if (!Csrf::validate('account_password', $_POST['_csrf'] ?? null)) {
            $this->forbidCsrf();
        }

        // Count every attempt (wrong-current failures included) before any
        // verification work: a blocked request verifies nothing and writes
        // no password-related row - it exits at the 429 below.
        $now = time();
        $times = array_values(array_filter(
            (array) Session::get('pw_change_times', []),
            static fn (mixed $t): bool => is_int($t)
        ));
        if (RateLimiter::overLimit($times, RateLimiter::MAX_PASSWORD_CHANGES_PER_WINDOW, $now)) {
            http_response_code(429);
            View::render('errors/429', ['reason' => 'password'], 429);
            exit;
        }
        $times[] = $now;
        Session::set('pw_change_times', $times);

        $userId = (int) Guard::userId();
        $result = $this->auth->changePassword(
            $userId,
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['confirm_password'] ?? '')
        );

        if (!$result['ok']) {
            Audit::asCurrentActor(
                'account.password_change_failed',
                'users',
                $userId,
                'fields=' . implode(',', array_keys($result['errors']))
            );
            Session::set('password_errors', $result['errors']);
            redirect('/account');
        }

        // New session id + fresh CSRF token after the credential changes.
        Session::regenerate();
        Csrf::rotate();
        Audit::asCurrentActor('account.password_changed', 'users', $userId, 'password changed');
        Session::flash('success', 'Your password has been changed. Use it next time you sign in.');
        redirect('/account');
    }

    private function forbidCsrf(): never
    {
        http_response_code(403);
        View::render('errors/403', [], 403);
        exit;
    }
}
