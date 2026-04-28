<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

/**
 * @internal
 */
class WhollyInternal
{
    public function whatever(): void
    {
    }
}

class PartiallyInternal
{
    public function publicApi(): void
    {
    }

    /**
     * @internal
     */
    public function internalMethod(): void
    {
    }

    /**
     * @internal
     */
    public const INTERNAL_CONST = 'x';
}
