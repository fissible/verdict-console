<?php

declare(strict_types=1);

namespace Fissible\VerdictConsole\Http;

use Fissible\VerdictConsole\Http\Controllers\ApprovalActionController;
use Fissible\VerdictConsole\Http\Controllers\ChatController;
use Illuminate\Support\Facades\Route;

/**
 * Route helper for the Blade approval inbox's single-row action forms.
 *
 * Routes mount at boot by default because every endpoint is fail-closed behind the host's Gate, following the
 * Horizon/Telescope convention. A host opts out with ignoreRoutes() or verdict-console.routes.register, then may
 * call register() with its own prefix and middleware. Any future install or setup command must ask whether to mount.
 */
final class VerdictConsoleRoutes
{
    public static bool $registersRoutes = true;

    public static function ignoreRoutes(): void
    {
        self::$registersRoutes = false;
    }

    /**
     * Register the console's action endpoints using host-supplied or configured mount settings.
     *
     * @param  list<string>|null  $middleware
     */
    public static function register(?string $prefix = null, ?array $middleware = null): void
    {
        if (Route::has('verdict-console.approvals.approve')
            || Route::has('verdict-console.approvals.reject')
            || Route::has('verdict-console.approvals.close')) {
            return;
        }

        Route::middleware($middleware ?? config('verdict-console.routes.middleware'))
            ->prefix($prefix ?? config('verdict-console.routes.prefix'))
            ->group(function (): void {
                Route::name('verdict-console.approvals.approve')
                    ->post('approvals/{approval}/approve', [ApprovalActionController::class, 'approve']);
                Route::name('verdict-console.approvals.reject')
                    ->post('approvals/{approval}/reject', [ApprovalActionController::class, 'reject']);
                Route::name('verdict-console.approvals.close')
                    ->post('approvals/{approval}/close', [ApprovalActionController::class, 'close']);
                Route::name('verdict-console.chat.send')
                    ->post('chat', [ChatController::class, 'send']);
            });
    }
}
