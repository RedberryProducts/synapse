<?php

namespace Redberry\Synapse\Http\Controllers;

use Illuminate\Http\Request;
use Redberry\Synapse\Chat\AgentInvoker;
use Redberry\Synapse\Chat\StreamEmitter;
use Redberry\Synapse\Discovery\AgentDiscovery;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController
{
    /**
     * Send a message to an agent and stream the answer back.
     *
     * The response body is written by `AgentInvoker`, which never lets an
     * exception escape: by the time the closure runs the headers are already on
     * the wire, so a failure has to arrive as an error *part*, not a status code.
     */
    public function send(Request $request, string $agent, AgentDiscovery $discovery, AgentInvoker $invoker): StreamedResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
            'conversation_id' => ['nullable', 'string'],
        ]);

        $discovered = $discovery->find($agent);

        abort_if($discovered === null, 404, 'Agent not found.');

        return response()->stream(
            function () use ($discovered, $validated, $invoker): void {
                // Finish the turn even if the browser goes away.
                //
                // PHP aborts a script on the first write to a dead connection,
                // and the dashboard cancels its own request routinely — closing
                // the tab, "New conversation", navigating to another agent. Left
                // alone, that would kill the run mid-stream: no assistant row,
                // no error row, and any tool invocation stuck `pending` forever,
                // leaving a question in the thread with no explanation of what
                // happened to it. The provider call is already in flight and
                // already paid for, so seeing it through and recording the
                // result is strictly better than discarding it.
                ignore_user_abort(true);

                $invoker->handle(
                    $discovered,
                    $validated['message'],
                    $validated['conversation_id'] ?? null,
                    new StreamEmitter,
                );
            },
            headers: [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache, no-transform',
                'Connection' => 'keep-alive',
                'x-vercel-ai-ui-message-stream' => 'v1',
                // Stop nginx from holding the whole stream until it closes.
                'X-Accel-Buffering' => 'no',
            ],
        );
    }
}
