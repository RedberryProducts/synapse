<?php

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Redberry\Synapse\Chat\ConversationWriter;
use Redberry\Synapse\Chat\MessageHistory;
use Redberry\Synapse\Models\SynapseConversation;

function historyResponse(bool $completed): AgentResponse
{
    $call = new ToolCall('call', 'Search', ['query' => 'hoodie'], resultId: 'result', thoughtSignature: 'provider-signature');
    $step = new Step('Searching.', [$call], $completed ? [new ToolResult('call', 'Search', ['query' => 'hoodie'], 'Found.', resultId: 'result')] : [], FinishReason::ToolCalls, new TextUsage, new Meta('gemini', 'test'), '', [['type' => 'private-provider-block']]);
    $response = new AgentResponse('invocation', 'Searching.', new TextUsage, new Meta('gemini', 'test'));
    $response->withSteps(collect([$step]));

    return $response;
}

it('preserves provider tool-call fields when rebuilding generic history', function () {
    $conversation = SynapseConversation::create(['agent_class' => 'A', 'title' => 'History']);
    $message = app(ConversationWriter::class)->storeAssistantMessage($conversation, historyResponse(true), 10);
    $history = app(MessageHistory::class)->for($conversation->id);
    expect($message->fresh()->steps[0]['replay_blocks'])->toBe([])
        ->and($history[0]->toolCalls->sole()->thoughtSignature)->toBe('provider-signature')
        ->and($history[0]->toolCalls->sole()->resultId)->toBe('result')
        ->and($history[1]->toolResults->sole()->resultId)->toBe('result')
        ->and($history[0]->replayBlocks)->toBe([]);
});

it('retains raw provider blocks only until a paused turn receives a new prompt', function () {
    $conversation = SynapseConversation::create(['agent_class' => 'A', 'title' => 'History']);
    $writer = app(ConversationWriter::class);
    $message = $writer->storeAssistantMessage($conversation, historyResponse(false), 10, status: 'paused');
    expect($message->fresh()->steps[0]['replay_blocks'])->not->toBeEmpty();
    $history = app(MessageHistory::class)->for($conversation->id);
    expect($history)->toHaveCount(1)
        ->and($history[0]->content)->toBe('Searching.')
        ->and($history[0]->toolCalls)->toBeEmpty()
        ->and($history[0]->replayBlocks)->toBe([]);
    $writer->storeUserMessage($conversation, 'New request');
    expect($message->fresh()->steps[0]['replay_blocks'])->toBe([]);
});

it('pairs interrupted calls with failed placeholders while excluding error cards', function () {
    $conversation = SynapseConversation::create(['agent_class' => 'A', 'title' => 'History']);
    $writer = app(ConversationWriter::class);
    $writer->storeAssistantMessage($conversation, historyResponse(false), 10, [['id' => 'notice', 'type' => 'notice', 'message' => 'Retrying']], 'failed');
    $writer->storeError($conversation, new RuntimeException('Private diagnostic'));
    $history = app(MessageHistory::class)->for($conversation->id);
    expect($history)->toHaveCount(2)
        ->and($history[1]->toolResults->sole()->failed)->toBeTrue()
        ->and($history[1]->toolResults->sole()->result)->toContain('may or may not have run')
        ->and($history[1]->toolResults->sole()->resultId)->toBe('result')
        ->and(json_encode($history))->not->toContain('Private diagnostic')->not->toContain('Retrying');
});
