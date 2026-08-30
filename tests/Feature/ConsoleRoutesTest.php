<?php

declare(strict_types=1);

use Fissible\VerdictConsole\Http\VerdictConsoleRoutes;
use Fissible\VerdictConsole\VerdictConsoleServiceProvider;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;

const CONSOLE_ROUTE_NAMES = ['verdict-console.approvals.approve', 'verdict-console.approvals.reject', 'verdict-console.approvals.close'];

/**
 * Mounting the console's routes is the host's decision. Nothing registers them behind the host's
 * back: not the provider by default, and not a command without asking.
 */
it('registers no routes by default', function (): void {
    foreach (CONSOLE_ROUTE_NAMES as $name) {
        expect(Route::has($name))->toBeFalse($name.' must not exist until the host mounts the console routes.');
    }

    expect(config('verdict-console.routes.register'))->toBeFalse()
        ->and(config('verdict-console.routes.prefix'))->toBe('verdict-console')
        ->and(config('verdict-console.routes.middleware'))->toBe(['web']);
});

it('mounts the three approval routes under the configured prefix and middleware when asked explicitly', function (): void {
    VerdictConsoleRoutes::register();

    foreach (CONSOLE_ROUTE_NAMES as $name) {
        expect(Route::has($name))->toBeTrue($name.' must be mounted.');
    }

    foreach (['approve', 'reject', 'close'] as $verb) {
        $route = Route::getRoutes()->getByName('verdict-console.approvals.'.$verb);

        expect($route->methods())->toBe(['POST'])
            ->and($route->uri())->toBe('verdict-console/approvals/{approval}/'.$verb)
            ->and($route->gatherMiddleware())->toContain('web');
    }
});

it('honours a host prefix and middleware, from arguments or from config', function (): void {
    VerdictConsoleRoutes::register(prefix: 'ops/verdict', middleware: ['api', 'auth:sanctum']);

    $reject = Route::getRoutes()->getByName('verdict-console.approvals.reject');

    expect($reject->uri())->toBe('ops/verdict/approvals/{approval}/reject')
        ->and($reject->gatherMiddleware())->toContain('auth:sanctum')
        ->and($reject->gatherMiddleware())->not->toContain('web');
});

/**
 * The config switch is the same mount, taken at boot: a host that sets it gets the routes without
 * writing a line in its routes file. It is off in the shipped config so an install can never
 * expose an action endpoint the host did not choose.
 */
it('mounts the routes at boot only when the config switch is on', function (): void {
    config()->set('verdict-console.routes.register', true);
    config()->set('verdict-console.routes.prefix', 'console');
    config()->set('verdict-console.routes.middleware', ['api']);

    app()->register(VerdictConsoleServiceProvider::class, force: true);

    $close = Route::getRoutes()->getByName('verdict-console.approvals.close');

    expect(Route::has('verdict-console.approvals.close'))->toBeTrue()
        ->and($close->uri())->toBe('console/approvals/{approval}/close')
        ->and($close->gatherMiddleware())->toContain('api')
        ->and($close->gatherMiddleware())->not->toContain('web');
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
