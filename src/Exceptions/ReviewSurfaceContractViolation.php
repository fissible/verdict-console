<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use Fissible\VerdictConsole\Reviews\ReviewVerb;
use LogicException;

final class ReviewSurfaceContractViolation extends LogicException
{
    /**
     * @param  list<ReviewVerb>  $expected
     * @param  list<ReviewVerb>  $actual
     */
    public function __construct(public readonly array $expected, public readonly array $actual)
    {
        parent::__construct(sprintf(
            'Review surface verb contract violated: expected [%s], rendered [%s].',
            implode(', ', array_map(static fn (ReviewVerb $verb): string => $verb->value, $expected)),
            implode(', ', array_map(static fn (ReviewVerb $verb): string => $verb->value, $actual)),
        ));
    }
}
