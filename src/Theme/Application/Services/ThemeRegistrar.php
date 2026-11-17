<?php

declare(strict_types=1);

namespace Pollora\Theme\Application\Services;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Pollora\BlockPattern\UI\PatternComponent;
use Pollora\Hook\Domain\Contract\Action;
use Pollora\Modules\Domain\Contracts\ModuleDiscoveryOrchestratorInterface;
use Pollora\Modules\Domain\Contracts\ModuleRepositoryInterface;
use Pollora\Modules\Infrastructure\Services\ModuleAssetManager;
use Pollora\Modules\Infrastructure\Services\ModuleComponentManager;
use Pollora\Modules\Infrastructure\Services\ModuleConfigurationLoader;
use Pollora\Modules\Infrastructure\Services\ModuleRouteLoader;
use Pollora\Theme\Domain\Contracts\ThemeModuleInterface;
use Pollora\Theme\Domain\Contracts\ThemeRegistrarInterface;
use Pollora\Theme\Domain\Contracts\ThemeService;
use Pollora\Theme\Domain\Models\ImageSize;
use Pollora\Theme\Domain\Models\Menus;
use Pollora\Theme\Domain\Models\Sidebar;
use Pollora\Theme\Domain\Models\Templates;
use Pollora\Theme\Domain\Models\ThemeInitializer;
use Pollora\Theme\Infrastructure\Models\LaravelThemeModule;
use Pollora\Theme\Infrastructure\Repositories\ThemeRepository;
use Pollora\Theme\Infrastructure\Services\Support;
use Pollora\Theme\Infrastructure\Services\WordPressThemeParser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Simplified theme self-registration service.
 */
class ThemeRegistrar implements ThemeRegistrarInterface
{
    private ?ThemeModuleInterface $activeTheme = null;

    public function __construct(
        protected ContainerInterface $app,
        protected WordPressThemeParser $themeParser
    ) {}

    /**
     * Register a theme as the active theme, behind its parent theme when it has one.
     *
     * WordPress runs the `functions.php` of a child theme and then the one of its parent, and both
     * call this method: the chain is registered the first time and the later calls return the
     * active theme. The parent registers first, so the child's config files, views and providers
     * come after and take precedence.
     */
    public function register(): ThemeModuleInterface
    {
        if ($this->activeTheme instanceof ThemeModuleInterface) {
            return $this->activeTheme;
        }

        $themeName = get_stylesheet();
        $themePath = get_stylesheet_directory();

        $parent = $this->resolveParentTheme($themeName);

        if ($parent !== null) {
            $this->hookParentProviders($this->registerTheme($parent['name'], $parent['path'], false));
        }

        return $this->registerTheme($themeName, $themePath, true);
    }

    /**
     * Register one theme of the chain.
     *
     * As under WordPress, the code of a theme belongs to that theme: the `theme` asset container
     * points at the theme while it registers, so the assets its code declares come from its own
     * build, and it ends up on the active theme. The theme initializer and the patterns, which
     * already walk the parent chain, run for the active theme only.
     */
    private function registerTheme(string $themeName, string $themePath, bool $isActive): ThemeModuleInterface
    {
        // Parse theme headers
        $styleCssPath = rtrim($themePath, '/').'/style.css';
        $themeData = $this->themeParser->parseThemeHeaders($styleCssPath);

        // Create the theme module
        $theme = $this->createThemeModule($themeName, $themePath);
        $theme->registerAutoloading();
        $theme->setHeaders($themeData);
        $theme->setEnabled(true);

        if ($isActive) {
            // Register as the active theme
            $this->activeTheme = $theme;
        }

        // The asset container comes first: the code discovered next declares its assets against it
        $assetsReady = $this->setupThemeAssetContainers($theme);

        // Invalidate repository cache and discover structures
        $this->invalidateRepositoryCache();

        if ($isActive) {
            $this->discoverThemeStructures($theme);
        } else {
            $this->registerWithBootOn($theme, fn () => $this->discoverThemeStructures($theme));
        }

        // Load theme configuration
        $this->loadThemeConfiguration($theme);

        // Setup theme components
        $this->setupThemeComponents($theme, $isActive);

        // Load the includes of the theme
        if ($assetsReady) {
            $this->loadThemeIncludes($theme);
        }

        // Load theme routes (api.php, web.php)
        $this->loadThemeRoutes($theme);

        // Load theme view paths via ThemeManager, which walks the parent chain
        if ($isActive && $this->app->has(ThemeService::class)) {
            $this->app->get(ThemeService::class)->load($themeName);
        }

        // Register and boot the theme
        $theme->register();
        $theme->boot();

        return $theme;
    }

    /**
     * The parent of the active theme, from the `Template` header WordPress resolves.
     *
     * @return array{name: string, path: string}|null Null when the theme has no parent
     */
    private function resolveParentTheme(string $themeName): ?array
    {
        $template = get_template();

        if ($template === '' || $template === $themeName) {
            return null;
        }

        return ['name' => $template, 'path' => get_template_directory()];
    }

    /**
     * The providers a theme lists in its config/providers.php.
     *
     * @return array<int, string>|null Null when the theme has no providers file
     */
    private function readProviders(string $themePath): ?array
    {
        $file = rtrim($themePath, '/').'/config/providers.php';

        if (! is_file($file)) {
            return null;
        }

        try {
            $providers = require $file;
        } catch (\Throwable $throwable) {
            $this->logError('Failed to read the theme providers: '.$throwable->getMessage());

            return null;
        }

        return is_array($providers) ? array_values($providers) : [];
    }

    /**
     * Register the providers a parent lists, when the theme initializer registers the ones of the active theme.
     *
     * The initializer reads `theme.providers`, which holds the list of the last theme that loaded
     * its config: the child's. The parent's list is registered here, on the same hook and before
     * it, with the `theme` asset container on the parent so its providers declare assets from the
     * parent's build; the container goes back to the active theme afterwards.
     */
    private function hookParentProviders(ThemeModuleInterface $parent): void
    {
        $providers = $this->readProviders($parent->getPath());

        if ($providers === null || $providers === [] || ! $this->app->has(Action::class)) {
            return;
        }

        $this->app->get(Action::class)->add(
            'after_setup_theme',
            fn () => $this->registerParentProviders($parent, $providers),
            1
        );
    }

    /**
     * @param  array<int, string>  $providers
     */
    private function registerParentProviders(ThemeModuleInterface $parent, array $providers): void
    {
        /** @var Application $app */
        $app = $this->app->has('app') ? $this->app->get('app') : $this->app;

        $this->pointThemeContainerAt($parent);

        try {
            foreach ($providers as $provider) {
                try {
                    $this->pointThemeContainerAtWhileBooting($app->register($provider), $parent);
                } catch (\Throwable $throwable) {
                    $this->logError(sprintf('Failed to register the provider %s of the theme %s: %s', $provider, $parent->getName(), $throwable->getMessage()));
                }
            }
        } finally {
            if ($this->activeTheme instanceof ThemeModuleInterface) {
                $this->pointThemeContainerAt($this->activeTheme);
            }
        }
    }

    /**
     * Run a registration that may register providers, and keep each of them booting with `theme` on the given parent.
     *
     * The discovery of a theme registers the service providers it finds in `app/`.
     */
    private function registerWithBootOn(ThemeModuleInterface $parent, \Closure $registration): void
    {
        /** @var Application $app */
        $app = $this->app->has('app') ? $this->app->get('app') : $this->app;

        if (! method_exists($app, 'getLoadedProviders') || ! method_exists($app, 'getProvider')) {
            $registration();

            return;
        }

        $before = array_keys($app->getLoadedProviders());

        $registration();

        foreach (array_diff(array_keys($app->getLoadedProviders()), $before) as $class) {
            $this->pointThemeContainerAtWhileBooting($app->getProvider($class), $parent);
        }
    }

    /**
     * Keep the `theme` asset container on the parent while its provider boots.
     *
     * WordPress loads the theme before the Laravel application has booted, so a provider
     * registered here boots later, when `theme` is the active theme's again: it would declare
     * the parent's assets against the child's build.
     */
    private function pointThemeContainerAtWhileBooting(mixed $provider, ThemeModuleInterface $parent): void
    {
        if (! $provider instanceof ServiceProvider) {
            return;
        }

        $provider->booting(fn () => $this->pointThemeContainerAt($parent));
        $provider->booted(function (): void {
            if ($this->activeTheme instanceof ThemeModuleInterface) {
                $this->pointThemeContainerAt($this->activeTheme);
            }
        });
    }

    /**
     * Make the `theme` asset container the one of the given theme.
     */
    private function pointThemeContainerAt(ThemeModuleInterface $theme): void
    {
        if (! $this->app->has(ModuleAssetManager::class)) {
            return;
        }

        try {
            $this->app->get(ModuleAssetManager::class)->setupModuleAssetContainer($theme->getLowerName(), $theme->getPath(), 'theme');
        } catch (\Throwable $throwable) {
            $this->logError('Failed to point the theme asset container at '.$theme->getName().': '.$throwable->getMessage());
        }
    }

    /**
     * Create theme module instance.
     */
    protected function createThemeModule(string $themeName, string $themePath): LaravelThemeModule
    {
        /** @var Application $app */
        $app = $this->app->has('app') ? $this->app->get('app') : $this->app;

        return new LaravelThemeModule($themeName, $themePath, $app);
    }

    /**
     * Invalidate the theme repository cache.
     */
    protected function invalidateRepositoryCache(): void
    {
        if (! $this->app->has(ModuleRepositoryInterface::class)) {
            return;
        }

        try {
            $repository = $this->app->get(ModuleRepositoryInterface::class);

            if ($repository instanceof ThemeRepository) {
                $repository->resetCache();
            }
        } catch (\Exception $exception) {
            $this->logError('Failed to invalidate theme repository cache: '.$exception->getMessage());
        }
    }

    /**
     * Perform on-demand discovery for theme structures.
     */
    protected function discoverThemeStructures(ThemeModuleInterface $theme): void
    {
        if (! $this->app->has(ModuleDiscoveryOrchestratorInterface::class)) {
            return;
        }

        try {
            $discoveryService = $this->app->get(ModuleDiscoveryOrchestratorInterface::class);

            // Get the app directory path for the theme
            $appPath = $this->getThemeAppPath($theme);
            if ($appPath && is_dir($appPath)) {
                $discoveryService->discover($appPath);
            }
        } catch (\Exception $exception) {
            $this->logError(sprintf('Theme discovery error for %s: ', $theme->getName()).$exception->getMessage());
        }
    }

    /**
     * Get the app directory path for a theme
     */
    private function getThemeAppPath(ThemeModuleInterface $theme): ?string
    {
        $basePath = $theme->getPath();

        // Check for app/ directory first (preferred)
        $appPath = $basePath.'/app';
        if (is_dir($appPath)) {
            return $appPath;
        }

        // Fallback to src/ directory
        $srcPath = $basePath.'/src';
        if (is_dir($srcPath)) {
            return $srcPath;
        }

        return null;
    }

    /**
     * Process a discovered structure for the theme.
     */
    protected function processDiscoveredStructure($structure, string $scoutType, ThemeModuleInterface $theme): void
    {
        if (! $this->isDebugMode()) {
            return;
        }

        $structureInfo = is_object($structure) && method_exists($structure, 'getFqn')
            ? $structure->getFqn()
            : 'unknown';

        $this->logError(sprintf('Discovered %s in theme %s: %s', $scoutType, $theme->getName(), $structureInfo));
    }

    /**
     * Get the currently registered active theme.
     */
    public function getActiveTheme(): ?ThemeModuleInterface
    {
        return $this->activeTheme;
    }

    /**
     * Check if a theme is registered as active.
     */
    public function isThemeActive(string $themeName): bool
    {
        return $this->activeTheme?->getLowerName() === strtolower($themeName);
    }

    /**
     * Reset the active theme registration.
     */
    public function resetActiveTheme(): void
    {
        $this->activeTheme = null;
        $this->invalidateRepositoryCache();
    }

    /**
     * Check if debug mode is enabled.
     */
    protected function isDebugMode(): bool
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Log error message.
     */
    protected function logError(string $message): void
    {
        try {
            $this->app->get(LoggerInterface::class)->error($message);
        } catch (\Throwable) {
        }
    }

    /**
     * Load theme-specific configuration.
     */
    protected function loadThemeConfiguration(ThemeModuleInterface $theme): void
    {
        if (! $this->app->has(ModuleConfigurationLoader::class)) {
            return;
        }

        try {
            /** @var ModuleConfigurationLoader $configLoader */
            $configLoader = $this->app->get(ModuleConfigurationLoader::class);

            $configLoader->loadModuleConfiguration(
                $theme->getPath(),
                'theme'
            );
        } catch (\Exception $exception) {
            $this->logError('Failed to load theme configuration: '.$exception->getMessage());
        }
    }

    /**
     * Setup theme-specific components.
     *
     * The theme initializer and the patterns run for the active theme only: the initializer loads
     * the theme chain and registers the providers, and the patterns already walk the parents.
     */
    protected function setupThemeComponents(ThemeModuleInterface $theme, bool $isActive = true): void
    {
        if (! $this->app->has(ModuleComponentManager::class)) {
            return;
        }

        try {
            /** @var ModuleComponentManager $componentManager */
            $componentManager = $this->app->get(ModuleComponentManager::class);

            // Define theme-specific components
            $themeComponents = [
                Menus::class,
                Support::class,
                Sidebar::class,
                Templates::class,
                ImageSize::class,
            ];

            if ($isActive) {
                array_unshift($themeComponents, ThemeInitializer::class, PatternComponent::class);
            }

            $moduleId = 'theme.'.$theme->getLowerName();

            $componentManager->registerModuleComponents($moduleId, $themeComponents);
            $componentManager->initializeModuleComponents($moduleId);
        } catch (\Exception $exception) {
            $this->logError('Failed to setup theme components: '.$exception->getMessage());
        }
    }

    /**
     * Load theme routes (api.php, web.php).
     */
    protected function loadThemeRoutes(ThemeModuleInterface $theme): void
    {
        if (! $this->app->has(ModuleRouteLoader::class)) {
            return;
        }

        try {
            /** @var ModuleRouteLoader $routeLoader */
            $routeLoader = $this->app->get(ModuleRouteLoader::class);
            $routeLoader->loadModuleRoutes($theme);
        } catch (\Exception $exception) {
            $this->logError('Failed to load theme routes: '.$exception->getMessage());
        }
    }

    /**
     * Setup the asset containers and the view paths of a theme.
     *
     * `theme` is the container of the theme being registered, so the assets its code declares come
     * from its own build; the active theme registers last and keeps it. Every theme also gets
     * `theme.<name>`, to name a build from another theme's code. A parent registers before its
     * child, and each view path goes in front of the others: the child's views win.
     *
     * @return bool Whether the containers are set up, and the includes can load
     */
    protected function setupThemeAssetContainers(ThemeModuleInterface $theme): bool
    {
        if (! $this->app->has(ModuleAssetManager::class)) {
            return false;
        }

        try {
            /** @var ModuleAssetManager $assetManager */
            $assetManager = $this->app->get(ModuleAssetManager::class);

            // Setup asset management ('theme' is always the container name)
            $assetManager->setupModuleAssets(
                $theme->getLowerName(),
                $theme->getPath(),
                'theme'
            );

            $assetManager->setupThemeNamedContainer($theme->getLowerName(), $theme->getPath());
        } catch (\Exception $exception) {
            $this->logError('Failed to setup theme assets: '.$exception->getMessage());

            return false;
        }

        return true;
    }

    /**
     * Load the includes of a theme and register its Blade directives.
     */
    protected function loadThemeIncludes(ThemeModuleInterface $theme): void
    {
        try {
            /** @var ModuleAssetManager $assetManager */
            $assetManager = $this->app->get(ModuleAssetManager::class);

            // Load theme includes
            $assetManager->loadModuleIncludes($theme->getPath());

            // Register Blade directives
            $assetManager->registerModuleBladeDirectives($theme->getPath());
        } catch (\Exception $exception) {
            $this->logError('Failed to setup theme assets: '.$exception->getMessage());
        }
    }
}
