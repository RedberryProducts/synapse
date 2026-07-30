<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\AgentSlug;
use Redberry\Synapse\Models\SynapseConversation;
use Redberry\Synapse\Models\SynapseMessage;
use Redberry\Synapse\Models\SynapseToolInvocation;
use Redberry\Synapse\Repositories\ConversationQuery;
use Redberry\Synapse\Repositories\ConversationRepository;

class ConversationsController
{
    /**
     * The History list: filtered, sorted, paginated.
     */
    public function index(Request $request, ConversationQuery $conversations): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string'],
            'agents' => ['nullable', 'array'],
            'agents.*' => ['string'],
            'status' => ['nullable', 'in:success,error'],
            'tools' => ['nullable', 'array'],
            'tools.*' => ['string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'sort' => ['nullable', 'in:newest,oldest'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $paginator = $conversations->paginate($filters);

        return response()->json([
            'data' => array_map(
                fn (SynapseConversation $conversation): array => $conversations->summary($conversation),
                $paginator->items(),
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            // Shipped with the list so the page needs one request, not three.
            'filters' => $conversations->filterOptions(),
        ]);
    }

    /**
     * Rename a conversation.
     *
     * Titles are never model-generated — not on creation and not here. The SDK
     * has a `generate_title` behaviour; spending a provider call on cosmetics is
     * not something a debugging tool should do behind your back.
     */
    public function update(Request $request, string $id, ConversationQuery $conversations): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $conversation = SynapseConversation::query()->find($id);

        abort_if($conversation === null, 404, 'Conversation not found.');

        $conversation->forceFill(['title' => $validated['title']])->save();

        return response()->json($conversations->summary($conversation));
    }

    /**
     * Replay one conversation: every turn, in order, with its token counts.
     */
    public function show(string $id, AgentDiscovery $discovery): JsonResponse
    {
        $conversation = SynapseConversation::query()->find($id);

        abort_if($conversation === null, 404, 'Conversation not found.');

        // uuid7 keys are k-sortable, so id order is chronological.
        $messages = $conversation->messages()->orderBy('id')->get();

        return response()->json([
            'id' => $conversation->id,
            'agent_class' => $conversation->agent_class,
            'agent_slug' => $slug = AgentSlug::make($conversation->agent_class),
            // The thread replays from rows, so it does not need the agent to
            // exist — but the playground needs to know, to explain the disabled
            // composer rather than pretending the conversation is gone.
            'agent_available' => $discovery->find($slug) !== null,
            'title' => $conversation->title,
            'created_at' => $conversation->created_at?->toIso8601String(),
            'totals' => [
                'prompt_tokens' => (int) $messages->sum('prompt_tokens'),
                'completion_tokens' => (int) $messages->sum('completion_tokens'),
                'total_tokens' => (int) ($messages->sum('prompt_tokens') + $messages->sum('completion_tokens')),
            ],
            'tool_invocations' => $this->toolInvocations($conversation),
            'messages' => $messages->map(fn (SynapseMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'attachments' => $this->attachments($message),
                'usage' => $message->usage ?: null,
                'duration_ms' => $message->duration_ms,
                'meta' => $message->meta ?: null,
                'metadata' => $message->metadata ?: null,
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * A message's attachments, as things the browser can fetch.
     *
     * `path` and `disk` stay on the server: the UI needs to show and download a
     * file, not to know where it lives on the filesystem.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function attachments(SynapseMessage $message): array
    {
        $base = rtrim((string) config('synapse.ui.path', 'synapse'), '/');

        return collect($message->attachments)
            ->values()
            ->map(fn (mixed $attachment, int $index): ?array => is_array($attachment) ? [
                'type' => $attachment['type'] ?? 'stored-document',
                'name' => $attachment['name'] ?? 'attachment',
                'url' => url("{$base}/api/attachments/{$message->id}/{$index}"),
            ] : null)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Every tool call made in this conversation, oldest first.
     *
     * Ordered by `started_at` so the UI can interleave the cards with messages
     * in the order things actually happened. A row with no `finished_at` was
     * still running when the run ended — that is shown, not hidden.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toolInvocations(SynapseConversation $conversation): array
    {
        return $conversation->toolInvocations()
            ->orderBy('started_at')
            ->orderBy('id')
            ->get()
            ->map(fn (SynapseToolInvocation $invocation): array => [
                'id' => $invocation->id,
                'message_id' => $invocation->message_id,
                'tool_call_id' => $invocation->tool_invocation_id,
                'type' => $invocation->type,
                'name' => $invocation->name,
                'arguments' => $invocation->arguments,
                'result' => $invocation->result,
                'status' => $invocation->status,
                // The provider's own word for it, kept unnormalized.
                'provider_status' => $invocation->provider_status,
                'error' => $invocation->error,
                'duration_ms' => $invocation->duration_ms,
                'started_at' => $invocation->started_at?->toIso8601String(),
                'finished_at' => $invocation->finished_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Delete a conversation and everything hanging off it.
     */
    public function destroy(string $id, ConversationRepository $repository): Response
    {
        $repository->deleteConversations([$id]);

        return response()->noContent();
    }
}
