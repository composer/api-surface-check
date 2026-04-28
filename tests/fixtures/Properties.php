<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

class WithProperties
{
    public string $publicTyped;

    public string $publicWithDefault = 'default';

    public readonly int $readonlyValue;

    public static array $staticArr = [];

    public string|int $unionTyped = 0;

    protected ?float $protectedNullable = null;

    private bool $privateProp = false;

    /**
     * @internal
     */
    public string $internalProp = '';
}
