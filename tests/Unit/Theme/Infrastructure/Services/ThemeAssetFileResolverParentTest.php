<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Pollora\Asset\Application\Services\AssetManager;
use Pollora\Asset\Infrastructure\Repositories\AssetContainer;
use Pollora\Asset\Infrastructure\Services\AssetFile;
use Pollora\Theme\Domain\Contracts\ThemeService;
use Pollora\Theme\Domain\Models\ThemeMetadata;
use Pollora\Theme\Infrastructure\Services\ThemeAssetFileResolver;

/**
 * A public directory holding the build manifests of the given themes, keyed by theme name.
 *
 * @param  array<string, array<int, string>|null>  $builds  Files each theme's manifest lists; null leaves the manifest out
 */
function parentAssetPublicDir(array $builds): string
{
    $public = sys_get_temp_dir().'/pollora-parent-assets-'.bin2hex(random_bytes(6));
    mkdir($public);
    mkdir($public.'/build');
    mkdir($public.'/build/theme');

    foreach ($builds as $theme => $files) {
        mkdir($public.'/build/theme/'.$theme);

        if ($files === null) {
            continue;
        }

        $manifest = [];

        foreach ($files as $file) {
            $manifest['resources/assets/'.$file] = ['file' => 'assets/'.basename($file)];
        }

        file_put_contents($public.'/build/theme/'.$theme.'/manifest.json', json_encode($manifest));
    }

    return $public;
}

/**
 * Points `public_path()` at the directory, through a real application the tests restore afterwards.
 */
function parentAssetUsePublicDir(string $public): void
{
    $app = new Application(sys_get_temp_dir());
    $app->usePublicPath($public);
    Container::setInstance($app);
}

function parentAssetCleanup(string $public): void
{
    foreach (glob($public.'/build/theme/*/manifest.json') ?: [] as $manifest) {
        @unlink($manifest);
    }

    foreach (glob($public.'/build/theme/*') ?: [] as $dir) {
        @rmdir($dir);
    }

    @rmdir($public.'/build/theme');
    @rmdir($public.'/build');
    @rmdir($public);
}

/**
 * A resolver whose active theme `child` has the given parents, with the asset containers of all of them.
 *
 * @param  array<int, string>  $parents  The names of the parent themes
 */
function parentAssetResolver(array $parents = ['parent']): ThemeAssetFileResolver
{
    $themes = Mockery::mock(ThemeService::class);
    $themes->shouldReceive('getParentThemes')->andReturn(array_map(
        fn (string $name): ThemeMetadata => new ThemeMetadata($name, '/themes'),
        $parents
    ));

    $containers = ['theme' => new AssetContainer('theme', ['build_directory' => 'build/theme/child', 'base_path' => 'resources/assets/'])];

    foreach ($parents as $name) {
        $containers['theme.'.$name] = new AssetContainer('theme.'.$name, ['build_directory' => 'build/theme/'.$name, 'base_path' => 'resources/assets/']);
    }

    $assets = Mockery::mock(AssetManager::class);
    $assets->shouldReceive('getContainer')->andReturnUsing(fn (string $name): ?AssetContainer => $containers[$name] ?? null);

    return new ThemeAssetFileResolver($themes, $assets);
}

describe('ThemeAssetFileResolver behind a parent theme', function (): void {
    beforeEach(function (): void {
        $this->previousContainer = Container::getInstance();
        $this->public = null;
        $this->asked = [];
        AssetFile::resolveUsing(function (string $file, string $container): string {
            $this->asked[] = [$file, $container];

            return 'https://example.test/'.$container.'/'.$file;
        });
    });

    afterEach(function (): void {
        AssetFile::resolveUsing(null);
        Container::setInstance($this->previousContainer);

        if ($this->public !== null) {
            parentAssetCleanup($this->public);
        }
    });

    it('asks the theme container alone when the theme has no parent', function (): void {
        $themes = Mockery::mock(ThemeService::class);
        $themes->shouldReceive('getParentThemes')->andReturn([]);
        $assets = Mockery::mock(AssetManager::class);
        $assets->shouldNotReceive('getContainer');

        $url = (new ThemeAssetFileResolver($themes, $assets))->assetUrl('img/a.svg');

        expect($url)->toBe('https://example.test/theme/img/a.svg')
            ->and($this->asked)->toBe([['img/a.svg', 'theme']]);
    });

    it('resolves a file the active theme builds without asking its parent', function (): void {
        $this->public = parentAssetPublicDir(['child' => ['img/a.svg'], 'parent' => ['img/a.svg']]);
        parentAssetUsePublicDir($this->public);

        $url = parentAssetResolver()->assetUrl('img/a.svg');

        expect($url)->toBe('https://example.test/theme/img/a.svg')
            ->and($this->asked)->toBe([['img/a.svg', 'theme']]);
    });

    it('falls back to the parent container for a file only the parent builds', function (): void {
        $this->public = parentAssetPublicDir(['child' => ['css/child.css'], 'parent' => ['img/a.svg']]);
        parentAssetUsePublicDir($this->public);

        $url = parentAssetResolver()->assetUrl('img/a.svg');

        expect($url)->toBe('https://example.test/theme.parent/img/a.svg')
            ->and($this->asked)->toBe([['img/a.svg', 'theme.parent']]);
    });

    it('asks the theme container for a file no build lists, which logs the miss once', function (): void {
        $this->public = parentAssetPublicDir(['child' => ['css/child.css'], 'parent' => ['css/parent.css']]);
        parentAssetUsePublicDir($this->public);

        $url = parentAssetResolver()->assetUrl('img/missing.svg');

        expect($url)->toBe('https://example.test/theme/img/missing.svg')
            ->and($this->asked)->toBe([['img/missing.svg', 'theme']]);
    });

    it('lets the resolver answer for a container with no manifest, as under the dev server', function (): void {
        $this->public = parentAssetPublicDir(['child' => null, 'parent' => ['img/a.svg']]);
        parentAssetUsePublicDir($this->public);

        $url = parentAssetResolver()->assetUrl('img/a.svg');

        expect($url)->toBe('https://example.test/theme/img/a.svg')
            ->and($this->asked)->toBe([['img/a.svg', 'theme']]);
    });

    it('skips a parent whose container is not registered', function (): void {
        $this->public = parentAssetPublicDir(['child' => ['css/child.css']]);
        parentAssetUsePublicDir($this->public);

        $themes = Mockery::mock(ThemeService::class);
        $themes->shouldReceive('getParentThemes')->andReturn([new ThemeMetadata('ghost', '/themes')]);
        $assets = Mockery::mock(AssetManager::class);
        $assets->shouldReceive('getContainer')->with('theme')->andReturn(new AssetContainer('theme', ['build_directory' => 'build/theme/child', 'base_path' => 'resources/assets/']));
        $assets->shouldReceive('getContainer')->with('theme.ghost')->andReturnNull();

        $url = (new ThemeAssetFileResolver($themes, $assets))->assetUrl('img/a.svg');

        expect($url)->toBe('https://example.test/theme/img/a.svg')
            ->and($this->asked)->toBe([['img/a.svg', 'theme']]);
    });
});
