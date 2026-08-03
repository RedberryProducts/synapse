<?php

namespace Redberry\Synapse;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\HtmlString;
use Redberry\Synapse\Chat\StreamEmitter;

class Synapse
{
    /**
     * The Synapse version.
     */
    public const VERSION = '0.1.0';

    /**
     * The callback that authorizes access to the dashboard in non-local environments.
     *
     * @var (Closure(Request): bool)|null
     */
    public static ?Closure $authUsing = null;

    /**
     * The directory holding the compiled dashboard assets.
     */
    protected static function distPath(string $file): string
    {
        return __DIR__.'/../dist/'.$file;
    }

    /**
     * Register the callback used to authorize dashboard access.
     *
     * @param  Closure(Request): bool  $callback
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
     * Whether this deployment can stream a response as it is produced.
     *
     * A property of the runtime, not of any agent: PHP can only push bytes to a
     * client as they are written when it is actually serving HTTP. Under the CLI
     * SAPI — a test harness, or Octane, which runs its workers on CLI — the
     * whole response is assembled first and arrives in one piece.
     *
     * The dashboard says so rather than letting a developer watch a blank thread
     * and conclude it has hung. Delegated to the emitter so the answer shown to
     * the user and the behaviour on the wire come from one rule.
     */
    public static function streams(): bool
    {
        return StreamEmitter::flushesUnder(PHP_SAPI);
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
            'streaming' => static::streams(),
        ];
    }

    /**
     * Inline the compiled application JavaScript.
     *
     * Assets are read straight from the package's dist/ directory rather than
     * published into the host application, so they can never go stale after a
     * `composer update` (the same approach Horizon and Telescope use).
     */
    public static function js(): HtmlString
    {
        $js = @file_get_contents(static::distPath('app.js'));

        if ($js === false) {
            return new HtmlString('<!-- Synapse assets are not built. Run `npm run build`. -->');
        }

        return new HtmlString('<script type="module">'.$js.'</script>');
    }

    /**
     * Inline the compiled dashboard stylesheet.
     */
    public static function css(): HtmlString
    {
        $css = @file_get_contents(static::distPath('app.css'));

        if ($css === false) {
            return new HtmlString('');
        }

        return new HtmlString('<style>'.$css.'</style>');
    }
}
