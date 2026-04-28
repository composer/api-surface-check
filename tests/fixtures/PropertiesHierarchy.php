<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

class PropParent
{
    public string $shared = 'parent';
}

class PropChild extends PropParent
{
    public string $shared = 'child';

    public int $childOnly = 0;
}
