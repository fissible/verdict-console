<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Doctor;

/**
 * How badly a finding breaks the console.
 *
 * Deliberately only two levels. A preflight whose output needs triage is one nobody reads.
 */
enum Severity: string
{
    /** This agent or capability cannot be driven by the console at all. */
    case Error = 'error';

    /**
     * Works, but is not doing what its registration implies — the shape of a mistake rather than a
     * broken run.
     */
    case Warning = 'warning';
}
