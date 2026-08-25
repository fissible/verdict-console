<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\VerdictConsole\Approvals\ApprovalVerb;
use LogicException;

/** A rendering test offered controls other than the shared approval-verb contract permits. */
final class ApprovalSurfaceContractViolation extends LogicException
{
    /**
     * @param  list<ApprovalVerb>  $expected
     * @param  list<ApprovalVerb>  $actual
     */
    public function __construct(
        public readonly array $expected,
        public readonly array $actual,
    ) {
        parent::__construct(sprintf(
            'Approval surface verb contract violated: expected [%s], rendered [%s].',
            implode(', ', array_map(static fn (ApprovalVerb $verb): string => $verb->value, $expected)),
            implode(', ', array_map(static fn (ApprovalVerb $verb): string => $verb->value, $actual)),
        ));
    }
}
