<?php

namespace Redberry\Synapse\Chat;

use Closure;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class RecordingScope
{
    protected ?Agent $target = null;

    protected ?SynapseRecorder $recorder = null;

    protected ?StreamEmitter $emitter = null;

    protected ?AgentPrompt $prompt = null;

    public function during(Agent $target, SynapseRecorder $recorder, StreamEmitter $emitter, Closure $run): AgentResponse
    {
        $previous = [$this->target, $this->recorder, $this->emitter, $this->prompt];
        [$this->target, $this->recorder, $this->emitter, $this->prompt] = [$target, $recorder, $emitter, null];

        try {
            return $run();
        } finally {
            [$this->target, $this->recorder, $this->emitter, $this->prompt] = $previous;
        }
    }

    public function handle(PromptingAgent|StartingStep|StepCompleted|InvokingTool|ToolInvoked|ToolFailed|AgentFailed|AgentFailedOver $event): void
    {
        if ($this->recorder === null || $this->emitter === null) {
            return;
        }

        if ($event instanceof PromptingAgent) {
            if ($event->prompt->agent !== $this->target || $event->prompt->parentInvocationId !== null) {
                return;
            }

            $this->prompt = $event->prompt;
            $this->recorder->begin($event->invocationId, $event->prompt->provider()->name(), $event->prompt->model);
            $this->emitter->provider($event->prompt->provider()->name());

            return;
        }

        if ($event->invocationId !== $this->recorder->invocationId) {
            return;
        }

        match (true) {
            $event instanceof StartingStep => $this->recorder->startingStep($event),
            $event instanceof StepCompleted => $this->recorder->stepCompleted($event),
            $event instanceof InvokingTool => $this->recorder->toolInvoking($event),
            $event instanceof ToolInvoked => $this->recorder->toolInvoked($event),
            $event instanceof ToolFailed => $this->recorder->toolFailed($event),
            $event instanceof AgentFailed => $this->recorder->failedAttempt($event),
            $event instanceof AgentFailedOver => $this->failover($event),
        };
    }

    protected function failover(AgentFailedOver $event): void
    {
        // Retryable attempts skip AgentFailed. Capture their completed results
        // before the next PromptingAgent replaces this attempt's context.
        $response = $this->prompt?->runContext()?->recordedResponse();
        if ($response !== null) {
            $this->recorder?->capture($response);
        }
        $message = sprintf('Failed over from %s (%s): %s', $event->provider->name(), $event->model, $event->exception->getMessage());
        $data = ['provider' => $event->provider->name(), 'model' => $event->model];
        $this->recorder?->notice($message, $data);
        $this->emitter?->notice($message, $data);
    }
}
