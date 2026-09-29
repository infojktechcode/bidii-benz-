<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Guard;
use App\Core\Session;
use App\Core\View;
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
        View::setBasePath('');
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

    // --- intended path (post-login redirect) --------------------------------

    public function testIntendedPathStripsBasePath(): void
    {
        View::setBasePath('/bidii-benz/public');
        self::assertSame('/admin/dashboard', Guard::intendedPath('/bidii-benz/public/admin/dashboard'));
        self::assertSame('/', Guard::intendedPath('/bidii-benz/public'));
        self::assertSame('/login', Guard::intendedPath('/bidii-benz/public/login?next=1#top'));
    }

    public function testIntendedPathDoesNotStripPartialBaseMatch(): void
    {
        View::setBasePath('/bidii-benz/public');
        // A sibling path sharing the base prefix must not be mangled.
        self::assertSame('/bidii-benz/publicity', Guard::intendedPath('/bidii-benz/publicity'));
    }

    public function testIntendedPathWithoutBasePath(): void
    {
        View::setBasePath('');
        self::assertSame('/admin', Guard::intendedPath('/admin'));
        self::assertSame('/', Guard::intendedPath('/'));
    }

    public function testIntendedPathRejectsProtocolRelativeRedirect(): void
    {
        View::setBasePath('');
        // parse_url treats "//host" as a host, not a path -> falls back to '/'.
        self::assertSame('/', Guard::intendedPath('//evil.com'));
        self::assertSame('/', Guard::intendedPath('///evil.com'));
        // A non-path value never becomes a redirect target.
        self::assertNull(Guard::intendedPath('\\evil.com'));
    }

    public function testIntendedPathHandlesMalformedInput(): void
    {
        View::setBasePath('/bidii-benz/public');
        self::assertSame('/', Guard::intendedPath(''));
        // A value that is not an absolute path is never remembered.
        self::assertNull(Guard::intendedPath('not a url at all'));
    }
}
