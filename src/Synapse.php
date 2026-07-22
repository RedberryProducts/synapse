<?php

namespace Redberry\Synapse;

use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;

class Synapse
{
    /**
     * The Synapse version.
     */
    public const VERSION = '0.1.0';

    /**
     * The callback that authorizes access to the dashboard in non-local environments.
     *
     * @var (Closure(\Illuminate\Http\Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * Cached Vite manifest.
     *
     * @var array<string, array<string, mixed>>|null
     */
    protected static ?array $manifest = null;

    /**
     * Register the callback used to authorize dashboard access.
     *
     * @param  Closure(\Illuminate\Http\Request): bool  $callback
     */
    public static function auth(Closure $callback): void
    {
        static::$authUsing = $callback;
    }

    /**
     * Determine whether the given request may access the dashboard.
     */
    public static function check($request): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return (static::$authUsing ?: fn () => false)($request);
    }

    /**
     * The variables exposed to the front-end via window.Synapse.
     *
     * @return array<string, mixed>
     */
    public static function scriptVariables(): array
    {
        return [
            'path' => config('synapse.ui.path', 'synapse'),
            'version' => static::VERSION,
        ];
    }

    /**
     * The <script> tag(s) for the compiled application.
     */
    public static function js(): HtmlString
    {
        $entry = static::manifest()['resources/js/app.tsx'] ?? null;

        if ($entry === null) {
            return new HtmlString('<!-- Synapse assets not built. Run `npm run build`. -->');
        }

        return new HtmlString(sprintf(
            '<script type="module" src="%s"></script>',
            static::asset($entry['file'])
        ));
    }

    /**
     * The <link> tag(s) for the compiled stylesheet(s).
     */
    public static function css(): HtmlString
    {
        $entry = static::manifest()['resources/js/app.tsx'] ?? null;

        $tags = collect($entry['css'] ?? [])
            ->map(fn (string $file): string => sprintf('<link rel="stylesheet" href="%s">', static::asset($file)))
            ->implode(PHP_EOL);

        return new HtmlString($tags);
    }

    /**
     * Resolve the public URL for a published asset.
     */
    protected static function asset(string $file): string
    {
        return asset('vendor/synapse/'.ltrim($file, '/'));
    }

    /**
     * Load (and cache) the published Vite manifest.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function manifest(): array
    {
        if (static::$manifest !== null) {
            return static::$manifest;
        }

        $path = public_path('vendor/synapse/.vite/manifest.json');

        if (! File::exists($path)) {
            return static::$manifest = [];
        }

        return static::$manifest = json_decode(File::get($path), true) ?: [];
    }
}
