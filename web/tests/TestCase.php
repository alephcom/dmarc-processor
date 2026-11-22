<?php

namespace Tests;

use App\Dns\ArrayResolver;
use App\Dns\Resolver;
use App\Intel\AsnDatabase;
use App\Intel\MmdbAsnDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Tests never touch real DNS or a downloaded IP database; individual tests override these.
        $this->app->instance(Resolver::class, new ArrayResolver);
        $this->app->instance(AsnDatabase::class, new MmdbAsnDatabase('/nonexistent'));
    }
}
