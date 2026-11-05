<?php

namespace App\Exceptions;

use RuntimeException;

/** Diagnostic reason stays in logs; only PUBLIC_MESSAGE is sent to staff. */
class OccurrenceConflict extends RuntimeException
{
    public const PUBLIC_MESSAGE = 'This record conflicts with existing crossing evidence. Your correction was not saved. Reload Review and ask an admin to check the event history.';
}
