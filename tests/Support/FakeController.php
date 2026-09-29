<?php

declare(strict_types=1);

namespace Tests\Support;

final class FakeController
{
    public static ?string $last = null;

    public function run(array $params = []): void
    {
        self::$last = 'ran';
    }
}
