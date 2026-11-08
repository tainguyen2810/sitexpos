<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use RuntimeException;

/**
 * Thrown inside a step when the current queue job has no time left for the
 * next paid call (see JobClock). Not a failure: the engine puts the step
 * back to pending and continues the run in a new job. Deliberately NOT an
 * AiGatewayException, so pipeline code that degrades on gateway errors
 * (e.g. "claim stays unchecked") never swallows it.
 */
final class StepYield extends RuntimeException
{
    public function __construct(string $message = 'Out of time in this queue job; continuing in a new job.')
    {
        parent::__construct($message);
    }
}
