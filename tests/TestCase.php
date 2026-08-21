<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Tests;

use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Only the console's own provider boots here. Verdict's provider is not loaded in the package
     * suite: the console depends on Verdict as a library, but its unit tests must not require
     * Verdict's full boot (capability discovery, provenance guards, migrations) to be green.
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            VerdictConsoleServiceProvider::class,
        ];
    }
}
