<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

class ParentClass
{
    public const SHARED = 1;

    public function inherited(): void
    {
    }
}

class ChildClass extends ParentClass
{
    public function inherited(): void
    {
    }

    public function childOnly(): int
    {
        return 1;
    }
}
