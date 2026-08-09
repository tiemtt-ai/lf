<?php

namespace Tests\Feature;

use Tests\TestCase;

class SchemaDriftCommandTest extends TestCase
{
    public function test_docs_only_contract_passes(): void
    {
        $this->artisan('schema:drift --docs-only')->assertSuccessful();
    }

    public function test_invalid_mode_combination_fails(): void
    {
        $this->artisan('schema:drift --docs-only --fresh')->assertFailed();
    }

    public function test_fresh_mode_rejects_sqlite(): void
    {
        // This guard keys off config('database.default'), not phpunit.xml's
        // <env> block: a real DB_CONNECTION in the process environment (as
        // set by the schema-drift CI job, which needs mysql for the other
        // commands sharing that step) wins over phpunit.xml's unforced
        // <env>, so the test must pin its own default here rather than
        // assume sqlite ambiently.
        $default = config('database.default');
        config(['database.default' => 'sqlite']);

        try {
            $this->artisan('schema:drift --fresh')
                ->expectsOutputToContain('SQLite is intentionally rejected')
                ->assertFailed();
        } finally {
            config(['database.default' => $default]);
        }
    }

    public function test_json_error_output_does_not_expose_credentials(): void
    {
        config(['database.connections.unsafe' => [
            'driver' => 'sqlite', 'database' => ':memory:',
            'username' => 'secret-user', 'password' => 'secret-password',
        ]]);
        $this->artisan('schema:drift --connection=unsafe --format=json')
            ->doesntExpectOutputToContain('secret-user')
            ->doesntExpectOutputToContain('secret-password')
            ->assertFailed();
    }

    public function test_production_requires_explicit_read_only_opt_in(): void
    {
        $environment = app()->environment();
        app()->detectEnvironment(fn () => 'production');
        try {
            $this->artisan('schema:drift --connection=mysql')
                ->expectsOutputToContain('requires --allow-production-read')
                ->assertFailed();
        } finally {
            app()->detectEnvironment(fn () => $environment);
        }
    }
}
