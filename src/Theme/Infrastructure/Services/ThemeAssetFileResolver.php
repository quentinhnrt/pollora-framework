<?php

declare(strict_types=1);

namespace Pollora\Theme\Infrastructure\Services;

use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Asset\Infrastructure\Services\AssetFile;
use Pollora\Theme\Domain\Contracts\ThemeAssetUrlResolverInterface;
use Pollora\Theme\Domain\Contracts\ThemeService;
use Pollora\Theme\Domain\Models\ThemeMetadata;

/**
 * Answers the built URL of a theme file through the `theme` asset container.
 *
 * Behind a parent theme, a file the active theme does not build is looked for in the `theme.<name>`
 * container of each parent, so the views of a parent keep their images and fonts under a child.
 */
class ThemeAssetFileResolver implements ThemeAssetUrlResolverInterface
{
    public function __construct(
        private readonly ?ThemeService $themes = null,
        private readonly ?AssetManager $assets = null,
    ) {}

    public function assetUrl(string $file): string
    {
        $parents = $this->parentContainers();

        if ($parents !== [] && ! $this->activeThemeBuilds($file)) {
            foreach ($parents as $container) {
                if ($this->containerBuilds($container, $file)) {
                    return (string) (new AssetFile($file))->from($container);
                }
            }
        }

        return (string) (new AssetFile($file))->from('theme');
    }

    /**
     * The asset containers of the parent themes, the nearest first.
     *
     * @return list<string>
     */
    private function parentContainers(): array
    {
        if (! $this->themes instanceof ThemeService || ! $this->assets instanceof AssetManager) {
            return [];
        }

        return array_values(array_map(
            static fn (ThemeMetadata $theme): string => 'theme.'.$theme->getName(),
            $this->themes->getParentThemes()
        ));
    }

    /**
     * Whether the active theme builds the file, or may build it: with no manifest to read, as
     * under the dev server, the `theme` container answers.
     */
    private function activeThemeBuilds(string $file): bool
    {
        $manifest = $this->manifestOf('theme');

        return $manifest === null || $this->lists($manifest, $file);
    }

    /**
     * Whether the container lists the file in its manifest.
     */
    private function containerBuilds(string $container, string $file): bool
    {
        $manifest = $this->manifestOf($container);

        return $manifest !== null && $this->lists($manifest, $file);
    }

    /**
     * @param  array{entries: array<string, mixed>, basePath: string}  $manifest
     */
    private function lists(array $manifest, string $file): bool
    {
        return array_key_exists($manifest['basePath'].$file, $manifest['entries']);
    }

    /**
     * The entries of the manifest of an asset container, with the base path its keys start with.
     *
     * @return array{entries: array<string, mixed>, basePath: string}|null Null when the container or its manifest is missing
     */
    private function manifestOf(string $container): ?array
    {
        $assetContainer = $this->assets?->getContainer($container);

        if (! $assetContainer instanceof AssetContainer) {
            return null;
        }

        $path = public_path($assetContainer->getBuildDirectory().'/'.$assetContainer->getManifestPath());

        if (! is_file($path)) {
            return null;
        }

        $entries = json_decode((string) file_get_contents($path), true);

        return is_array($entries) ? ['entries' => $entries, 'basePath' => $assetContainer->getBasePath()] : null;
    }
}
