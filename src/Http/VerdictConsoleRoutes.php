<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Http;

use Fissible\VerdictConsole\Http\Controllers\ApprovalActionController;
use Illuminate\Support\Facades\Route;

/**
 * Opt-in route helper for the Blade approval inbox's single-row action forms.
 *
 * Mounting is opt-in and the host's decision: this package registers no routes unless the host calls this helper
 * or sets the verdict-console.routes.register configuration. Any future install or setup command must ask the user
 * before registering these routes.
 */
final readonly class VerdictConsoleRoutes
{
    /**
     * Register the console's action endpoints using host-supplied or configured mount settings.
     *
     * @param  list<string>|null  $middleware
     */
    public static function register(?string $prefix = null, ?array $middleware = null): void
    {
        Route::middleware($middleware ?? config('verdict-console.routes.middleware'))
            ->prefix($prefix ?? config('verdict-console.routes.prefix'))
            ->group(function (): void {
                Route::name('verdict-console.approvals.approve')
                    ->post('approvals/{approval}/approve', [ApprovalActionController::class, 'approve']);
                Route::name('verdict-console.approvals.reject')
                    ->post('approvals/{approval}/reject', [ApprovalActionController::class, 'reject']);
                Route::name('verdict-console.approvals.close')
                    ->post('approvals/{approval}/close', [ApprovalActionController::class, 'close']);
            });
    }
}
