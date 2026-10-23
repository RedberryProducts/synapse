<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Redberry\Synapse\Chat\ConversationPresenter;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Redberry\Synapse\Discovery\AgentSlug;
use Redberry\Synapse\Models\SynapseConversation;
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
    public function show(string $id, AgentDiscovery $discovery, ConversationPresenter $presenter): JsonResponse
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
                'input_tokens' => (int) $messages->sum('input_tokens'),
                'output_tokens' => (int) $messages->sum('output_tokens'),
                'total_tokens' => (int) ($messages->sum('input_tokens') + $messages->sum('output_tokens')),
            ],
            'tool_invocations' => $conversation->toolInvocations()->orderBy('id')->get()->map($presenter->tool(...))->all(),
            'messages' => $messages->map($presenter->message(...))->all(),
        ]);
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
