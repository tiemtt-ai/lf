<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the behavioural tests of resources/js/ai-authoring.js (tests/js) under
 * Node. They cover what AiAuthoringScriptProtocolTest, which only reads the
 * source, cannot: what the script still holds after access is lost, which
 * request id a retry carries, what a late answer may do.
 */
class AiAuthoringScriptBehaviorTest extends TestCase
{
    public function test_the_script_behaves_as_the_protocol_requires(): void
    {
        $version = new Process(['node', '--version']);
        try {
            $version->run();
        } catch (\Throwable) {
            $this->markTestSkipped('Node is not available.');
        }
        if (! $version->isSuccessful() || (int) ltrim(trim($version->getOutput()), 'v') < 18) {
            $this->markTestSkipped('Node 18 or newer is needed for node --test.');
        }

        $process = new Process(['node', '--test', 'tests/js'], dirname(__DIR__, 2), null, null, 120);
        $process->run();

        $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
    }
}
