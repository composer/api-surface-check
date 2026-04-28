<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

class SimpleClass
{
    public const VERSION = '1.0';

    public function publicMethod(int $count = 5): string
    {
        return (string) $count;
    }

    protected function protectedMethod(): void
    {
    }

    private function privateMethod(): void
    {
    }
}
