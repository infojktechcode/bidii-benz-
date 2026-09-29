<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Guard;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class GuardTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testUnauthenticatedWhenNoSessionUser(): void
    {
        self::assertNull(Guard::userId());
        self::assertNull(Guard::role());
        self::assertFalse(Guard::check());
        self::assertFalse(Guard::atLeast(Guard::ROLE_CLIENT));
    }

    public function testSpoofedRoleInSessionIsRejected(): void
    {
        Session::set('role', 'superadmin');
        self::assertNull(Guard::role(), 'unknown roles must never be trusted');
        self::assertFalse(Guard::check());
    }

    public function testStaffRoleChecks(): void
    {
        Session::set('user_id', 5);
        Session::set('role', Guard::ROLE_STAFF);
        self::assertTrue(Guard::check());
        self::assertTrue(Guard::hasRole(Guard::ROLE_STAFF));
        self::assertFalse(Guard::hasRole(Guard::ROLE_OWNER));
        self::assertTrue(Guard::atLeast(Guard::ROLE_STAFF));
        self::assertFalse(Guard::atLeast(Guard::ROLE_OWNER));
    }

    public function testOwnerOutranksStaff(): void
    {
        Session::set('user_id', 1);
        Session::set('role', Guard::ROLE_OWNER);
        self::assertTrue(Guard::atLeast(Guard::ROLE_STAFF), 'owner must pass staff checks');
        self::assertTrue(Guard::atLeast(Guard::ROLE_OWNER));
        self::assertFalse(Guard::hasRole(Guard::ROLE_STAFF), 'hasRole is exact, not hierarchical');
    }

    public function testClientCannotPassStaffChecks(): void
    {
        Session::set('user_id', 9);
        Session::set('role', Guard::ROLE_CLIENT);
        self::assertTrue(Guard::atLeast(Guard::ROLE_CLIENT));
        self::assertFalse(Guard::atLeast(Guard::ROLE_STAFF));
        self::assertFalse(Guard::hasRole(Guard::ROLE_STAFF, Guard::ROLE_OWNER));
    }

    public function testUserIdMustBeInteger(): void
    {
        Session::set('user_id', '7');
        self::assertNull(Guard::userId(), 'non-integer user_id is treated as unauthenticated');
    }
}
