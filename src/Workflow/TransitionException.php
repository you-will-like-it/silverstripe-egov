<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Workflow;

use RuntimeException;

/**
 * Thrown when a status transition is not allowed or its preconditions are not met.
 * The message is user-facing (Bosnian).
 */
class TransitionException extends RuntimeException
{
}
