<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

interface Contract
{
    public function fulfill(string $what): bool;

    public const STATUS_OK = 'ok';
}

class Implementer implements Contract
{
    public function fulfill(string $what): bool
    {
        return true;
    }

    public function classOnly(): void
    {
    }
}
