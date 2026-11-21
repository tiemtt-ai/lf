<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The board of a Session refused someone who may see the Session: it is not open (not live, or the class is no longer
 * active) or the learner's Enrollment is not active. Still a 403 for the board's JSON endpoints and the private
 * channel; the board pages catch it and say why instead (LF-Core-LiveClass, stale entries).
 */
class LiveClassBoardRefused extends HttpException
{
    /**
     * @param  'closed'|'inactive'  $reason
     */
    public function __construct(public readonly string $reason, public readonly int $cohortId, string $message)
    {
        parent::__construct(403, $message);
    }
}
