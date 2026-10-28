<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Responses\Data\UrlCitation;
use Redberry\Synapse\Models\SynapseMessage;

class CitationSources
{
    /**
     * @param  iterable<mixed>  ...$groups
     * @return list<array{url: string, title: string|null}>
     */
    public static function merge(iterable ...$groups): array
    {
        $sources = [];
        foreach ($groups as $group) {
            foreach ($group as $citation) {
                $source = self::source($citation);
                if ($source === null) {
                    continue;
                }
                $url = $source['url'];
                if (! isset($sources[$url])) {
                    $sources[$url] = $source;
                } elseif ($sources[$url]['title'] === null) {
                    $sources[$url]['title'] = $source['title'];
                }
            }
        }

        return array_values($sources);
    }

    /** @return array{url: string, title: string|null}|null */
    public static function source(mixed $citation): ?array
    {
        $data = $citation instanceof UrlCitation ? $citation->toArray() : $citation;
        if (! is_array($data) || ! is_string($url = $data['url'] ?? null)) {
            return null;
        }
        $parts = parse_url($url);
        if (! preg_match('~\Ahttps?://~i', $url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || $parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        $title = is_string($data['title'] ?? null) ? trim($data['title']) : '';

        return ['url' => $url, 'title' => $title === '' ? null : $title];
    }

    /** @return list<array{url: string, title: string|null}> */
    public static function forMessage(SynapseMessage $message): array
    {
        $citations = $message->meta['citations'] ?? [];
        $sources = self::merge(is_array($citations) ? $citations : []);
        foreach ($message->steps ?? [] as $step) {
            $citations = is_array($step) ? ($step['meta']['citations'] ?? []) : [];
            $sources = self::merge($sources, is_array($citations) ? $citations : []);
        }

        return $sources;
    }
}
