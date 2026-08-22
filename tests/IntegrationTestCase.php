<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Tests;

use Fissible\Verdict\VerdictServiceProvider;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Illuminate\Foundation\Application;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * The middle tier: Verdict and Laravel AI are booted, but nothing drives an agent.
 *
 * {@see TestCase} boots only this package, deliberately, so the unit suite stays independent of
 * Verdict's boot. {@see EndToEndTestCase} adds a faked provider transport and drives a whole run.
 * This sits between them, for code that inspects the real Verdict container — the doctor's checks
 * are only meaningful against real capabilities and real bindings.
 */
abstract class IntegrationTestCase extends Orchestra
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            VerdictServiceProvider::class,
            VerdictConsoleServiceProvider::class,
        ];
    }
}
