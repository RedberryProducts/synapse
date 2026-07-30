<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Http\Client\RequestException;
use Throwable;
use WeakMap;

/**
 * Digs the provider's own explanation out of a failed HTTP call.
 *
 * `RequestException::getMessage()` is only ever "HTTP request returned status
 * code 400" — the part that actually says *why* lives in the response body, and
 * the SDK rethrows the exception untouched. For a debugging tool, showing the
 * status without the reason is barely better than showing nothing: the whole
 * question is what the provider objected to.
 */
class ProviderErrorDetail
{
    /**
     * Response bodies are small, but a provider echoing back a large request
     * shouldn't be able to bloat every stored error row.
     */
    protected const int MAX_BODY = 8000;

    /**
     * Bodies already read, keyed by the throwable they came from.
     *
     * `Response::body()` is `(string) $response->getBody()`, and a failure from
     * a *streaming* request carries a non-seekable stream — so the first read
     * drains it and every read after that returns an empty string. One turn asks
     * twice (the stored error row, then the SSE part), which is exactly how the
     * browser ended up with a blank provider response while the database had the
     * real one.
     *
     * A WeakMap rather than a plain array: entries disappear with the throwable,
     * so nothing is held past the request that produced it.
     *
     * @var WeakMap<Throwable, array{status: int, body: string}>
     */
    protected static ?WeakMap $cache = null;

    /**
     * The status and body of the failed request, walking the exception chain.
     *
     * The SDK converts some failures into its own exception types (rate limits,
     * insufficient credits) with the original attached as `previous`, so the
     * chain is followed rather than just the outermost throwable.
     *
     * @return array{status: int, body: string}|null
     */
    public static function for(?Throwable $e): ?array
    {
        if ($e === null) {
            return null;
        }

        static::$cache ??= new WeakMap;

        if (isset(static::$cache[$e])) {
            return static::$cache[$e];
        }

        $found = static::read($e);

        if ($found !== null) {
            static::$cache[$e] = $found;
        }

        return $found;
    }

    /**
     * @return array{status: int, body: string}|null
     */
    protected static function read(Throwable $e): ?array
    {
        $current = $e;

        while ($current !== null) {
            if ($current instanceof RequestException && $current->response !== null) {
                return [
                    'status' => $current->response->status(),
                    'body' => mb_substr($current->response->body(), 0, self::MAX_BODY),
                ];
            }

            $current = $current->getPrevious();
        }

        return null;
    }
}
