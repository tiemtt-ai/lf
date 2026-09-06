<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Laravel namespaces Storage::fake() only for its own --parallel
        // runner. Independent PHPUnit processes otherwise clean the same fake
        // disk and can remove another suite's source files mid-test.
        ParallelTesting::resolveTokenUsing(static fn (): string => (string) getmypid());
    }
}
