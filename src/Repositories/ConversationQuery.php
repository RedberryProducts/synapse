<?php

namespace Redberry\Synapse\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\AgentSlug;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseToolInvocation;

/**
 * Builds the History list: filters, sort, pagination, and the aggregates each
 * row shows.
 */
class ConversationQuery
{
    public const int PER_PAGE = 25;

    public function __construct(protected AgentDiscovery $discovery) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, SynapseConversation>
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $query = SynapseConversation::query()
            ->withCount('toolInvocations')
            // Aliases must not shadow a column on synapse_conversations; these
            // live on synapse_messages, so they are safe here.
            ->withSum('messages as prompt_tokens', 'prompt_tokens')
            ->withSum('messages as completion_tokens', 'completion_tokens')
            // One query instead of N+1 for the status column.
            ->withExists(['messages as has_error' => fn (Builder $q) => $q->where('role', 'error')]);

        $this->applyFilters($query, $filters);

        $direction = ($filters['sort'] ?? 'newest') === 'oldest' ? 'asc' : 'desc';

        return $query
            ->orderBy('updated_at', $direction)
            ->orderBy('id', $direction)
            ->paginate(
                perPage: (int) ($filters['per_page'] ?? self::PER_PAGE),
                page: (int) ($filters['page'] ?? 1),
            );
    }

    /**
     * The values the filter menus offer.
     *
     * Agents come from discovery — that is the set you can meaningfully filter
     * by. Tool names come from the invocation rows instead, because the useful
     * question is "which conversations used this tool", including tools that
     * have since been deleted from the codebase.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return [
            'agents' => array_map(
                fn ($agent): array => ['slug' => $agent->slug, 'name' => $agent->name],
                $this->discovery->all(),
            ),
            'tools' => SynapseToolInvocation::query()
                ->distinct()
                ->orderBy('name')
                ->pluck('name')
                ->all(),
        ];
    }

    /**
     * @param  Builder<SynapseConversation>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        if (filled($filters['search'] ?? null)) {
            $search = '%'.$filters['search'].'%';

            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', $search)
                ->orWhereHas('messages', fn (Builder $m) => $m->where('content', 'like', $search))
            );
        }

        if (filled($filters['agents'] ?? null)) {
            $query->whereIn('agent_class', $this->classesFor((array) $filters['agents']));
        }

        if (($filters['status'] ?? null) === 'error') {
            $query->whereHas('messages', fn (Builder $q) => $q->where('role', 'error'));
        }

        if (($filters['status'] ?? null) === 'success') {
            $query->whereDoesntHave('messages', fn (Builder $q) => $q->where('role', 'error'));
        }

        if (filled($filters['tools'] ?? null)) {
            $query->whereHas(
                'toolInvocations',
                fn (Builder $q) => $q->whereIn('name', (array) $filters['tools']),
            );
        }

        if (filled($filters['from'] ?? null)) {
            $query->whereDate('updated_at', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->whereDate('updated_at', '<=', $filters['to']);
        }
    }

    /**
     * Resolve agent slugs to class names through discovery.
     *
     * Never by transforming a slug back into a class name — the rule Epic 1
     * set, so a crafted query parameter cannot name an arbitrary class. A slug
     * that matches nothing contributes nothing, which filters to an empty list
     * rather than erroring.
     *
     * @param  array<int, mixed>  $slugs
     * @return array<int, string>
     */
    protected function classesFor(array $slugs): array
    {
        $classes = [];

        foreach ($slugs as $slug) {
            if (! is_string($slug)) {
                continue;
            }

            $agent = $this->discovery->find($slug);

            if ($agent !== null) {
                $classes[] = $agent->class;
            }
        }

        // An unmatched filter must exclude everything, not silently match all.
        return $classes === [] ? [''] : $classes;
    }

    /**
     * The list payload for one conversation.
     *
     * @return array<string, mixed>
     */
    public function summary(SynapseConversation $conversation): array
    {
        $slug = AgentSlug::make($conversation->agent_class);

        $prompt = (int) ($conversation->getAttribute('prompt_tokens') ?? 0);
        $completion = (int) ($conversation->getAttribute('completion_tokens') ?? 0);

        return [
            'id' => $conversation->id,
            'agent_class' => $conversation->agent_class,
            'agent_slug' => $slug,
            'agent_name' => class_basename($conversation->agent_class),
            // False once the class is gone. The conversation is still Synapse's
            // own record and stays readable; it just cannot be continued.
            'agent_available' => $this->discovery->find($slug) !== null,
            'title' => $conversation->title,
            // Message-level only. A recovered tool failure is still a success:
            // agents routinely fail a tool and answer correctly anyway, and
            // colouring those red would teach you to ignore the column.
            'status' => $conversation->getAttribute('has_error') ? 'error' : 'success',
            'tool_calls' => (int) ($conversation->getAttribute('tool_invocations_count') ?? 0),
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'updated_at' => $conversation->updated_at?->toIso8601String(),
        ];
    }
}
