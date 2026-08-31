<?php

namespace App\Services;

use App\Exceptions\DocumentCommandFailure;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class DocumentProcessRunner
{
    public function run(array $command, int $timeoutSeconds): string
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new RuntimeException('provider_timeout');
        }

        if (! $process->isSuccessful()) {
            throw new DocumentCommandFailure('provider_command_failed: '.mb_substr(trim($process->getErrorOutput()), 0, 500),
                $process->getExitCode(), $process->hasBeenSignaled() ? $process->getTermSignal() : null);
        }

        return $process->getOutput();
    }
}
