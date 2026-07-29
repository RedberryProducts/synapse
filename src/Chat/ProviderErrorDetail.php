<?php

namespace Redberry\Synapse\Chat;

use Illuminate\Http\Client\RequestException;
use Throwable;

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
        while ($e !== null) {
            if ($e instanceof RequestException && $e->response !== null) {
                return [
                    'status' => $e->response->status(),
                    'body' => mb_substr($e->response->body(), 0, self::MAX_BODY),
                ];
            }

            $e = $e->getPrevious();
        }

        return null;
    }
}
