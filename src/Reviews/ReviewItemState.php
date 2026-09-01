<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Reviews;

enum ReviewItemState: string
{
    case Pending = 'pending';
    case LapsedUndecided = 'lapsed_undecided';
}
