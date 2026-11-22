<?php

namespace App\Intel;

interface AsnDatabase
{
    public function lookup(string $ip): ?AsnInfo;

    public function available(): bool;
}
