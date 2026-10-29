<?php

namespace Redberry\Synapse\Chat;

use Laravel\Ai\Responses\Data\FinishReason;
use Redberry\Synapse\Models\SynapseMessage;

/** Display-only projection; SDK replay shapes stay private. */
class StepPresenter
{
    /**
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    public function forMessage(SynapseMessage $message, array $tools): array
    {
        $details = [];
        foreach ($message->steps ?? [] as $index => $step) {
            if (! is_array($step)) {
                continue;
            }
            $observation = $message->step_observations[$index] ?? [];
            $observation = is_array($observation) ? $observation : [];
            $completion = $observation['completion'] ?? 'unverified';
            $completion = in_array($completion, ['completed', 'response', 'incomplete'], true) ? $completion : 'unverified';
            $reason = $observation['finish_reason'] ?? $step['finish_reason'] ?? null;
            $meta = is_array($step['meta'] ?? null) ? $step['meta'] : [];
            $usage = $completion !== 'incomplete' && is_array($step['usage'] ?? null) ? $step['usage'] : [];
            $counts = [];
            foreach (['input_tokens', 'output_tokens', 'cache_read_input_tokens', 'cache_write_input_tokens', 'reasoning_tokens'] as $key) {
                $counts[$key] = is_int($usage[$key] ?? null) && $usage[$key] >= 0 ? $usage[$key] : null;
            }
            $details[] = [
                'number' => $index + 1,
                'completion' => $completion,
                'provider' => $this->text($observation['provider'] ?? $meta['provider'] ?? null),
                'model' => $this->text($observation['model'] ?? $meta['model'] ?? null),
                'text' => $this->text($step['text'] ?? null),
                'reasoning' => $this->text($completion === 'incomplete' ? ($observation['reasoning'] ?? null) : ($step['reasoning'] ?? null)),
                'finish_reason' => is_string($reason) ? FinishReason::tryFrom($reason)?->value : null,
                'model_duration_ms' => $completion === 'completed' ? $this->duration($observation['model_duration_ms'] ?? null) : null,
                'usage' => $counts,
                'tools' => $this->tools($step, $observation, $tools),
            ];
        }

        return $details;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $observation
     * @param  list<array<string, mixed>>  $tools
     * @return list<array<string, mixed>>
     */
    protected function tools(array $step, array $observation, array $tools): array
    {
        $ids = is_array($observation['tool_ids'] ?? null) ? $observation['tool_ids'] : [];
        $linked = [];
        foreach ($ids as $id) {
            foreach ($tools as $tool) {
                if ($tool['id'] === $id) {
                    $linked[$id] = $tool;
                }
            }
        }
        $result = [];
        foreach ($this->arrays($step['tool_calls'] ?? null) as $call) {
            $matches = array_filter($linked, fn (array $tool): bool => $tool['type'] === 'tool' && $tool['tool_call_id'] === ($call['id'] ?? null));
            if (count($matches) === 1) {
                $tool = reset($matches);
                $result[] = $this->observedTool($tool);
                unset($linked[$tool['id']]);

                continue;
            }
            $results = array_values(array_filter($this->arrays($step['tool_results'] ?? null), fn (array $item): bool => isset($call['id']) && ($item['id'] ?? null) === $call['id']));
            $output = count($results) === 1 ? $results[0] : null;
            $status = $output === null ? 'unavailable' : (! empty($output['denied']) ? 'denied' : (! empty($output['failed']) ? 'error' : 'success'));
            $result[] = [
                'observation_id' => null, 'call_id' => $this->text($call['id'] ?? null),
                'type' => 'tool', 'name' => $this->text($call['name'] ?? null),
                'arguments' => $call['arguments'] ?? null, 'result' => $output['result'] ?? null,
                'status' => $status, 'duration_ms' => null,
                'error' => in_array($status, ['denied', 'error']) ? $this->text($output['result'] ?? null) : null,
            ];
        }
        foreach ($this->arrays($step['provider_tool_calls'] ?? null) as $call) {
            if (count(array_filter($linked, fn (array $tool): bool => $tool['type'] === 'provider_tool' && $tool['tool_call_id'] === ($call['id'] ?? null))) > 0) {
                continue;
            }
            $result[] = [
                'observation_id' => null, 'call_id' => $this->text($call['id'] ?? null),
                'type' => 'provider_tool', 'name' => $this->text($call['type'] ?? null),
                'arguments' => null, 'result' => null, 'status' => 'unavailable', 'duration_ms' => null, 'error' => null,
            ];
        }
        foreach ($linked as $tool) {
            $result[] = $this->observedTool($tool);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $tool
     * @return array<string, mixed>
     */
    protected function observedTool(array $tool): array
    {
        return [
            'observation_id' => $tool['id'], 'call_id' => $tool['tool_call_id'],
            'type' => $tool['type'], 'name' => $tool['name'],
            'arguments' => $tool['arguments'], 'result' => $tool['result'],
            'status' => $tool['status'], 'duration_ms' => $tool['duration_ms'], 'error' => $tool['error'],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected function arrays(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    protected function text(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    protected function duration(mixed $value): int|float|null
    {
        return (is_int($value) || is_float($value)) && is_finite($value) && $value >= 0 ? $value : null;
    }
}
