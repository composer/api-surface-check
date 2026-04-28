<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

class Holder
{
    public function makeAnonymous(): object
    {
        return new class {
            public function visible(): void
            {
            }
        };
    }
}
