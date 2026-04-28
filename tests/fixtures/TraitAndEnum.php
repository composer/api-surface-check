<?php

namespace Composer\ApiSurfaceCheck\Tests\Fixtures;

trait Stringable_
{
    public function asString(): string
    {
        return '';
    }
}

enum Status: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return $this->value;
    }
}
