<?php

namespace Tests\Fixtures;

final class MutableCountry
{

    public static int $instances = 0;

    public int $id;

    public string $name = '';

    public ?Iso $iso = null;

    private string $secret = 'unchanged';

    public function getSecret(): string
    {
        return $this->secret;
    }
}
