<?php

declare(strict_types=1);

use Fissible\VerdictConsole\VerdictConsoleServiceProvider;

it('boots the service provider', function (): void {
    expect(app()->getLoadedProviders())
        ->toHaveKey(VerdictConsoleServiceProvider::class);
});

it('merges the package configuration with its decided defaults', function (): void {
    // Guards the design's decided defaults (§7, §13): polling transport, inspect-only config.
    expect(config('verdict-console.transport'))->toBe('polling')
        ->and(config('verdict-console.config_surface.writable'))->toBeFalse()
        ->and(config('verdict-console.approvals.gate'))->toBe('approve-verdict-action');
});
