<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Approvals;

/** The only resume-failure phases the current Laravel AI boundary can observe honestly. */
enum ResumeFailurePhase: string
{
    case DefinitelyPreExecution = 'definitely_pre_execution';
    case Indeterminate = 'indeterminate';
}
