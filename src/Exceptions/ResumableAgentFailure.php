<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Exceptions;

use RuntimeException;

/**
 * The category the ingestion path catches.
 *
 * A pause that cannot be rebuilt is not an error to propagate — the run is already paused and
 * waiting, so failing the listener would hide it rather than prevent it. The disposition bridge
 * catches this base type, records the row as unresumable, and files an incident. Catching the
 * category rather than each case is why the base exists.
 */
abstract class ResumableAgentFailure extends RuntimeException {}
