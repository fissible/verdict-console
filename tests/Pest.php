<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Tests\EndToEndTestCase;
use Fissible\VerdictConsole\Tests\TestCase;

uses(TestCase::class)->in('Feature');

// The end-to-end suite boots Verdict and Laravel AI as well as this package. It is deliberately a
// separate directory rather than a heavier default: the Feature suite must stay independent of
// Verdict's boot, and this one cannot be.
uses(EndToEndTestCase::class)->in('EndToEnd');
