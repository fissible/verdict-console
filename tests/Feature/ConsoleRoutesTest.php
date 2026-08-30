<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Http\VerdictConsoleRoutes;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

const CONSOLE_ROUTE_NAMES = ['verdict-console.approvals.approve', 'verdict-console.approvals.reject', 'verdict-console.approvals.close'];

/**
 * The Laravel first-party shape: routes mount at boot, safe because every endpoint is fail-closed
 * behind the host's Gate, and a host opts OUT — `VerdictConsoleRoutes::ignoreRoutes()` (the
 * Passport / Fortify / Sanctum idiom) or the config switch — rather than in.
 */
afterEach(function (): void {
    // The ignore flag is static, and Pest runs every file in one process.
    VerdictConsoleRoutes::$registersRoutes = true;
});

/**
 * Simulate a boot on which nothing mounted: clear the router, then boot the provider again. The
 * forced registration re-runs boot(), which would also re-register every listener on the live
 * dispatcher — so boot() is handed a throwaway dispatcher and the real one is restored after.
 */
function rebootConsoleRoutes(): void
{
    Route::setRoutes(new RouteCollection);

    $events = app('events');
    app()->instance('events', new Dispatcher(app()));

    try {
        app()->register(VerdictConsoleServiceProvider::class, force: true);
    } finally {
        app()->instance('events', $events);
    }
}

/** @return list<string> every registered route name under the console's namespace, duplicates included */
function consoleRouteNames(): array
{
    return array_values(array_filter(
        array_map(fn ($route): ?string => $route->getName(), Route::getRoutes()->getRoutes()),
        fn (?string $name): bool => $name !== null && str_starts_with($name, 'verdict-console.approvals.'),
    ));
}

it('mounts the three approval routes at boot by default, under the configured prefix and middleware', function (): void {
    expect(config('verdict-console.routes.register'))->toBeTrue()
        ->and(config('verdict-console.routes.prefix'))->toBe('verdict-console')
        ->and(config('verdict-console.routes.middleware'))->toBe(['web'])
        ->and(VerdictConsoleRoutes::$registersRoutes)->toBeTrue();

    foreach (['approve', 'reject', 'close'] as $verb) {
        $route = Route::getRoutes()->getByName('verdict-console.approvals.'.$verb);

        expect($route)->not->toBeNull($verb.' must be mounted at boot.')
            ->and($route->methods())->toBe(['POST'])
            ->and($route->uri())->toBe('verdict-console/approvals/{approval}/'.$verb)
            ->and($route->gatherMiddleware())->toContain('web');
    }
});

it('does not mount when the host ignores the routes', function (): void {
    VerdictConsoleRoutes::ignoreRoutes();

    rebootConsoleRoutes();

    expect(VerdictConsoleRoutes::$registersRoutes)->toBeFalse()
        ->and(consoleRouteNames())->toBe([]);
});

it('does not mount when the config switch is off', function (): void {
    config()->set('verdict-console.routes.register', false);

    rebootConsoleRoutes();

    expect(consoleRouteNames())->toBe([]);
});

it('honours a host prefix and middleware from config at boot', function (): void {
    config()->set('verdict-console.routes.prefix', 'console');
    config()->set('verdict-console.routes.middleware', ['api']);

    rebootConsoleRoutes();

    $close = Route::getRoutes()->getByName('verdict-console.approvals.close');

    expect($close->uri())->toBe('console/approvals/{approval}/close')
        ->and($close->gatherMiddleware())->toContain('api')
        ->and($close->gatherMiddleware())->not->toContain('web');
});

/** The Passport pattern: ignore the default mount, then mount yourself where and how you like. */
it('lets a host that ignored the default mount register its own prefix and middleware', function (): void {
    VerdictConsoleRoutes::ignoreRoutes();
    rebootConsoleRoutes();

    VerdictConsoleRoutes::register(prefix: 'ops/verdict', middleware: ['api', 'auth:sanctum']);

    $reject = Route::getRoutes()->getByName('verdict-console.approvals.reject');

    expect($reject->uri())->toBe('ops/verdict/approvals/{approval}/reject')
        ->and($reject->gatherMiddleware())->toContain('auth:sanctum')
        ->and($reject->gatherMiddleware())->not->toContain('web');
});

/**
 * Mounting is idempotent: a host that calls the helper after the default mount gets the routes it
 * already had. RouteCollection keys entries by method and URI, so a re-registration would replace
 * the objects rather than duplicate them — object identity is the observable.
 */
it('registers the routes once, however many times mounting is requested', function (): void {
    $mountedAtBoot = array_map(fn (string $name): object => Route::getRoutes()->getByName($name), CONSOLE_ROUTE_NAMES);

    VerdictConsoleRoutes::register();
    VerdictConsoleRoutes::register();

    foreach (CONSOLE_ROUTE_NAMES as $i => $name) {
        expect(Route::getRoutes()->getByName($name))->toBe($mountedAtBoot[$i], $name.' must be the route mounted at boot, not a replacement.');
    }

    expect(consoleRouteNames())->toHaveCount(3);
});

/**
 * Under `route:cache` the cached file already holds the routes; registering them again at boot is
 * what every first-party package guards against with `routesAreCached()`.
 */
it('does not mount at boot when the application routes are cached', function (): void {
    // The framework memoizes its answer as the `routes.cached` container instance (testbench
    // evaluates it at boot), and that instance is what the guard reads — so the fixture binds it
    // directly. Pointing APP_ROUTES_CACHE at a file is not portable: Laravel treats only `/` and
    // `\\` prefixes as absolute, so a Windows drive-letter path resolves under basePath() instead.
    $originallyCached = app()->routesAreCached();
    app()->instance('routes.cached', true);

    try {
        expect(app()->routesAreCached())->toBeTrue('Fixture: the framework must believe its routes are cached.');

        rebootConsoleRoutes();

        expect(consoleRouteNames())->toBe([]);
    } finally {
        app()->forgetInstance('routes.cached');
        expect(app()->routesAreCached())->toBe($originallyCached);
    }
});

it('publishes the views under their own tag, into the vendor views directory', function (): void {
    $target = resource_path('views/vendor/verdict-console');

    File::exists($target) && File::deleteDirectory($target);

    $this->artisan('vendor:publish', ['--tag' => 'verdict-console-views', '--force' => true])->assertSuccessful();

    expect(File::exists($target.'/components/approvals.blade.php'))->toBeTrue();

    File::deleteDirectory($target);
});

/** A Blade component and routes need the view and routing packages; the manifest must say so. */
it('declares the view and routing packages the widget needs', function (): void {
    $require = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true)['require'];

    expect($require)->toHaveKeys(['illuminate/view', 'illuminate/routing']);
});
