<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use RuntimeException;

/**
 * There is no approver to name on the receipt.
 *
 * Thrown rather than falling back to `'unknown'`, `'system'`, or an empty string. Verdict records
 * this value on the receipt and in the evidence trail, so a placeholder would produce an audit
 * entry asserting that *somebody* approved a consequential action while naming nobody — worse than
 * a failed approval, because it looks complete.
 */
final class ApproverNotIdentified extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'No authenticated approver is available to record against this decision. An approval must '
            .'name who made it; bind a custom ApproverAuthority if this application identifies '
            .'approvers by something other than the authenticated user.',
        );
    }
}
