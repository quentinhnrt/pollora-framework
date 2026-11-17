<?php

declare(strict_types=1);

use Brain\Monkey\Functions;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Mockery\MockInterface;
use Pollora\BlockPattern\UI\PatternComponent;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Modules\Domain\Contracts\ModuleDiscoveryOrchestratorInterface;
use Pollora\Modules\Infrastructure\Services\ModuleAssetManager;
use Pollora\Modules\Infrastructure\Services\ModuleComponentManager;
use Pollora\Modules\Infrastructure\Services\ModuleConfigurationLoader;
use Pollora\Modules\Infrastructure\Services\ModuleDiscoveryOrchestrator;
use Pollora\Modules\Infrastructure\Services\ModuleRouteLoader;
use Pollora\Theme\Application\Services\ThemeRegistrar;
use Pollora\Theme\Domain\Contracts\ThemeService;
use Pollora\Theme\Domain\Models\ImageSize;
use Pollora\Theme\Domain\Models\Menus;
use Pollora\Theme\Domain\Models\Sidebar;
use Pollora\Theme\Domain\Models\Templates;
use Pollora\Theme\Domain\Models\ThemeInitializer;
use Pollora\Theme\Infrastructure\Services\Support;
use Pollora\Theme\Infrastructure\Services\WordPressThemeParser;
use Psr\Log\LoggerInterface;

/**
 * Creates the directories of a parent and a child theme.
 *
 * @param  array<int, string>  $parentSubDirs
 * @param  array<int, string>  $childSubDirs
 * @return array{0: string, 1: string} The parent directory, then the child directory
 */
function parentChainDirs(array $parentSubDirs = [], array $childSubDirs = []): array
{
    $root = sys_get_temp_dir().'/pollora-parent-chain-'.bin2hex(random_bytes(6));
    mkdir($root);
    mkdir($root.'/parent');
    mkdir($root.'/child');

    foreach ($parentSubDirs as $sub) {
        mkdir($root.'/parent/'.$sub);
    }

    foreach ($childSubDirs as $sub) {
        mkdir($root.'/child/'.$sub);
    }

    return [$root.'/parent', $root.'/child'];
}

function parentChainCleanup(string $parentDir, string $childDir): void
{
    foreach ([$parentDir, $childDir] as $dir) {
        foreach (glob($dir.'/config/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($dir.'/*') ?: [] as $entry) {
            @rmdir($entry);
        }

        @rmdir($dir);
    }

    @rmdir(dirname($parentDir));
}

/**
 * A registrar for the child theme, whose parent is `parent`.
 */
function parentChainRegistrar(Container $container, string $parentDir, string $childDir): ThemeRegistrar
{
    Functions\when('get_stylesheet')->justReturn('child');
    Functions\when('get_stylesheet_directory')->justReturn($childDir);
    Functions\when('get_template')->justReturn('parent');
    Functions\when('get_template_directory')->justReturn($parentDir);

    $parser = Mockery::mock(WordPressThemeParser::class);
    $parser->shouldReceive('parseThemeHeaders')->andReturn(['Theme Name' => 'Demo']);

    return new ThemeRegistrar($container, $parser);
}

describe('ThemeRegistrar parent theme chain', function (): void {
    beforeEach(function (): void {
        [$this->parentDir, $this->childDir] = parentChainDirs(['app'], ['app']);
        $this->container = new Container;
    });

    afterEach(function (): void {
        parentChainCleanup($this->parentDir, $this->childDir);
    });

    it('loads the configuration of the parent before the one of the child', function (): void {
        $loader = Mockery::mock(ModuleConfigurationLoader::class);
        $loader->shouldReceive('loadModuleConfiguration')->once()->with($this->parentDir, 'theme')->ordered();
        $loader->shouldReceive('loadModuleConfiguration')->once()->with($this->childDir, 'theme')->ordered();
        $this->container->instance(ModuleConfigurationLoader::class, $loader);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('runs the discovery on the app directory of the parent and on the one of the child', function (): void {
        $discovery = Mockery::mock(ModuleDiscoveryOrchestrator::class);
        $discovery->shouldReceive('discover')->once()->with($this->parentDir.'/app')->ordered();
        $discovery->shouldReceive('discover')->once()->with($this->childDir.'/app')->ordered();
        $this->container->instance(ModuleDiscoveryOrchestratorInterface::class, $discovery);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('loads the includes and the routes of the parent', function (): void {
        $assets = Mockery::mock(ModuleAssetManager::class)->shouldIgnoreMissing();
        $assets->shouldReceive('loadModuleIncludes')->once()->with($this->parentDir)->ordered();
        $assets->shouldReceive('loadModuleIncludes')->once()->with($this->childDir)->ordered();
        $this->container->instance(ModuleAssetManager::class, $assets);

        $routes = Mockery::mock(ModuleRouteLoader::class);
        $routes->shouldReceive('loadModuleRoutes')->twice();
        $this->container->instance(ModuleRouteLoader::class, $routes);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('returns the child as the active theme and keeps the parent apart', function (): void {
        $registrar = parentChainRegistrar($this->container, $this->parentDir, $this->childDir);

        $theme = $registrar->register();

        expect($theme->getName())->toBe('child');
        expect($registrar->getActiveTheme())->toBe($theme);
        expect($registrar->isThemeActive('child'))->toBeTrue();
        expect($registrar->isThemeActive('parent'))->toBeFalse();
    });

    it('points the `theme` container at each theme while it registers, the parent first, and leaves it on the child', function (): void {
        $assets = Mockery::mock(ModuleAssetManager::class)->shouldIgnoreMissing();
        $assets->shouldReceive('setupModuleAssets')->once()->with('parent', $this->parentDir, 'theme')->ordered();
        $assets->shouldReceive('setupModuleAssets')->once()->with('child', $this->childDir, 'theme')->ordered();
        $assets->shouldReceive('setupThemeNamedContainer')->once()->with('parent', $this->parentDir);
        $assets->shouldReceive('setupThemeNamedContainer')->once()->with('child', $this->childDir);
        $this->container->instance(ModuleAssetManager::class, $assets);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('points the `theme` container at a theme before the discovery of its code, which declares its assets', function (): void {
        $assets = Mockery::mock(ModuleAssetManager::class)->shouldIgnoreMissing();
        $assets->shouldReceive('setupModuleAssets')->once()->with('parent', $this->parentDir, 'theme')->ordered('chain');
        $discovery = Mockery::mock(ModuleDiscoveryOrchestrator::class);
        $discovery->shouldReceive('discover')->once()->with($this->parentDir.'/app')->ordered('chain');
        $assets->shouldReceive('setupModuleAssets')->once()->with('child', $this->childDir, 'theme')->ordered('chain');
        $discovery->shouldReceive('discover')->once()->with($this->childDir.'/app')->ordered('chain');
        $this->container->instance(ModuleAssetManager::class, $assets);
        $this->container->instance(ModuleDiscoveryOrchestratorInterface::class, $discovery);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('initializes the theme and the patterns for the child only', function (): void {
        $manager = Mockery::mock(ModuleComponentManager::class);
        $manager->shouldReceive('registerModuleComponents')->once()->with('theme.parent', [
            Menus::class,
            Support::class,
            Sidebar::class,
            Templates::class,
            ImageSize::class,
        ])->ordered();
        $manager->shouldReceive('initializeModuleComponents')->once()->with('theme.parent')->ordered();
        $manager->shouldReceive('registerModuleComponents')->once()->with('theme.child', [
            ThemeInitializer::class,
            PatternComponent::class,
            Menus::class,
            Support::class,
            Sidebar::class,
            Templates::class,
            ImageSize::class,
        ])->ordered();
        $manager->shouldReceive('initializeModuleComponents')->once()->with('theme.child')->ordered();
        $this->container->instance(ModuleComponentManager::class, $manager);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('lets the theme service load the child, whose chain holds the parent', function (): void {
        $service = Mockery::mock(ThemeService::class);
        $service->shouldReceive('load')->once()->with('child');
        $this->container->instance(ThemeService::class, $service);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('registers the chain once when the parent functions.php registers the theme again', function (): void {
        $loader = Mockery::mock(ModuleConfigurationLoader::class);
        $loader->shouldReceive('loadModuleConfiguration')->once()->with($this->parentDir, 'theme');
        $loader->shouldReceive('loadModuleConfiguration')->once()->with($this->childDir, 'theme');
        $this->container->instance(ModuleConfigurationLoader::class, $loader);

        $registrar = parentChainRegistrar($this->container, $this->parentDir, $this->childDir);

        $first = $registrar->register();
        $second = $registrar->register();

        expect($second)->toBe($first);
    });

    it('registers the chain again after the active theme was reset', function (): void {
        $loader = Mockery::mock(ModuleConfigurationLoader::class);
        $loader->shouldReceive('loadModuleConfiguration')->twice()->with($this->parentDir, 'theme');
        $loader->shouldReceive('loadModuleConfiguration')->twice()->with($this->childDir, 'theme');
        $this->container->instance(ModuleConfigurationLoader::class, $loader);

        $registrar = parentChainRegistrar($this->container, $this->parentDir, $this->childDir);

        $registrar->register();
        $registrar->resetActiveTheme();
        $registrar->register();
    });

    /**
     * Registers the chain with a parent that lists providers, and returns what the registrar hooked on after_setup_theme.
     *
     * @return array{0: Closure|null, 1: MockInterface, 2: MockInterface} The hooked callback, the application and the asset manager
     */
    function parentChainWithProviders(object $test, string $providers = "['ParentProvider']"): array
    {
        mkdir($test->parentDir.'/config');
        file_put_contents($test->parentDir.'/config/providers.php', "<?php\nreturn ".$providers.";\n");
        mkdir($test->childDir.'/config');
        file_put_contents($test->childDir.'/config/providers.php', "<?php\nreturn ['ChildProvider'];\n");

        $app = Mockery::mock(Application::class)->makePartial();
        $app->instance('config', new Repository);

        $test->container->instance('app', $app);

        $assets = Mockery::mock(ModuleAssetManager::class)->shouldIgnoreMissing();
        $test->container->instance(ModuleAssetManager::class, $assets);

        $captured = null;
        $action = Mockery::mock(Action::class);
        $action->shouldReceive('add')->with('after_setup_theme', Mockery::type(Closure::class), 1)
            ->andReturnUsing(function (string $hook, Closure $callback) use (&$captured, $action) {
                $captured = $callback;

                return $action;
            });
        $test->container->instance(Action::class, $action);

        parentChainRegistrar($test->container, $test->parentDir, $test->childDir)->register();

        return [$captured, $app, $assets];
    }

    it('registers the providers of the parent on after_setup_theme, while `theme` points at the parent, then gives it back to the child', function (): void {
        [$callback, $app, $assets] = parentChainWithProviders($this);

        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('parent', $this->parentDir, 'theme')->ordered('flow');
        $app->shouldReceive('register')->once()->with('ParentProvider')->ordered('flow');
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('child', $this->childDir, 'theme')->ordered('flow');

        expect($callback)->toBeInstanceOf(Closure::class);

        $callback();
    });

    it('keeps the `theme` container on the parent while its provider boots, which happens after the application booted', function (): void {
        [$callback, $app, $assets] = parentChainWithProviders($this);
        $provider = new class($app) extends ServiceProvider {};

        $app->shouldReceive('register')->once()->with('ParentProvider')->andReturn($provider);
        // Registering the provider, then its boot, each with `theme` on the parent and then back on the child
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('parent', $this->parentDir, 'theme')->ordered('boot');
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('child', $this->childDir, 'theme')->ordered('boot');
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('parent', $this->parentDir, 'theme')->ordered('boot');
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('child', $this->childDir, 'theme')->ordered('boot');

        $callback();
        $provider->callBootingCallbacks();
        $provider->callBootedCallbacks();
    });

    it('keeps the `theme` container on the parent while the providers its discovery registers boot', function (): void {
        $app = Mockery::mock(Application::class)->makePartial();
        $app->instance('config', new Repository);

        $this->container->instance('app', $app);
        $provider = new class($app) extends ServiceProvider {};
        $app->shouldReceive('getLoadedProviders')->andReturn(['Existing' => true], ['Existing' => true, 'Discovered' => true]);
        $app->shouldReceive('getProvider')->with('Discovered')->andReturn($provider);

        $assets = Mockery::mock(ModuleAssetManager::class)->shouldIgnoreMissing();
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('parent', $this->parentDir, 'theme')->ordered('boot');
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('child', $this->childDir, 'theme')->ordered('boot');
        $this->container->instance(ModuleAssetManager::class, $assets);

        $discovery = Mockery::mock(ModuleDiscoveryOrchestrator::class);
        $discovery->shouldReceive('discover')->with($this->parentDir.'/app')->once();
        $discovery->shouldReceive('discover')->with($this->childDir.'/app')->once();
        $this->container->instance(ModuleDiscoveryOrchestratorInterface::class, $discovery);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
        $provider->callBootingCallbacks();
        $provider->callBootedCallbacks();
    });

    it('leaves the providers of the child to the theme initializer', function (): void {
        [$callback, $app] = parentChainWithProviders($this);

        $app->shouldReceive('register')->with('ParentProvider')->once();
        $app->shouldReceive('register')->with('ChildProvider')->never();

        $callback();
    });

    it('hooks nothing when the parent lists no provider', function (): void {
        $this->container->instance('config', new Repository);
        mkdir($this->parentDir.'/config');
        file_put_contents($this->parentDir.'/config/providers.php', "<?php\nreturn [];\n");
        $action = Mockery::mock(Action::class);
        $action->shouldReceive('add')->never();
        $this->container->instance(Action::class, $action);

        parentChainRegistrar($this->container, $this->parentDir, $this->childDir)->register();
    });

    it('logs a provider that fails to register and still gives the container back to the child', function (): void {
        [$callback, $app, $assets] = parentChainWithProviders($this);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once()->with('Failed to register the provider ParentProvider of the theme parent: boom');
        $this->container->instance(LoggerInterface::class, $logger);

        $app->shouldReceive('register')->once()->with('ParentProvider')->andThrow(new RuntimeException('boom'));
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('child', $this->childDir, 'theme');
        $assets->shouldReceive('setupModuleAssetContainer')->once()->with('parent', $this->parentDir, 'theme');

        $callback();
    });
});

describe('ThemeRegistrar without a parent theme', function (): void {
    it('registers the theme alone when the template is the stylesheet', function (): void {
        $dir = sys_get_temp_dir().'/pollora-no-parent-'.bin2hex(random_bytes(6));
        mkdir($dir);

        Functions\when('get_stylesheet')->justReturn('solo');
        Functions\when('get_stylesheet_directory')->justReturn($dir);
        Functions\when('get_template')->justReturn('solo');
        Functions\when('get_template_directory')->justReturn($dir);

        $parser = Mockery::mock(WordPressThemeParser::class);
        $parser->shouldReceive('parseThemeHeaders')->andReturn(['Theme Name' => 'Solo']);

        $container = new Container;
        $loader = Mockery::mock(ModuleConfigurationLoader::class);
        $loader->shouldReceive('loadModuleConfiguration')->once()->with($dir, 'theme');
        $container->instance(ModuleConfigurationLoader::class, $loader);

        $assets = Mockery::mock(ModuleAssetManager::class)->shouldIgnoreMissing();
        $assets->shouldReceive('setupThemeNamedContainer')->once()->with('solo', $dir);
        $container->instance(ModuleAssetManager::class, $assets);

        try {
            $theme = (new ThemeRegistrar($container, $parser))->register();
        } finally {
            @rmdir($dir);
        }

        expect($theme->getName())->toBe('solo');
    });
});
