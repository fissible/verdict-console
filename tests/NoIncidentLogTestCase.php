<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Tests;

use Illuminate\Foundation\Application;

/** Boots the package with its optional default incident logger disabled. */
abstract class NoIncidentLogTestCase extends TestCase
{
    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('verdict-console.ingestion_incidents.log', false);
    }
}
