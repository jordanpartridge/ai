<?php

namespace Laravel\Ai\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Responses\AgentResponse;
use ReflectionClass;

class InvokeAgent implements ShouldQueue
{
    use Concerns\InvokesQueuedResponseCallbacks;
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public ?int $tries = null;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array|int|null $backoff = null;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public ?int $maxExceptions = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agent $agent,
        public Decisions|string $prompt = '',
        public array $attachments = [],
        public Lab|array|string|null $provider = null,
        public ?string $model = null)
    {
        $this->tries = $this->agentAttribute(Tries::class);
        $this->backoff = $this->agentAttribute(Backoff::class);
        $this->maxExceptions = $this->agentAttribute(MaxExceptions::class);
        $this->connection = $this->agentAttribute(Connection::class);
        $this->queue = $this->agentAttribute(Queue::class);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->withCallbacks(fn (): AgentResponse => $this->agent->prompt(
            $this->prompt, $this->attachments, $this->provider, $this->model
        ));
    }

    /**
     * Get the display name for the queued job.
     */
    public function displayName(): string
    {
        return $this->agent::class;
    }

    /**
     * Get the value of the given queue attribute declared on the agent.
     */
    protected function agentAttribute(string $attribute): mixed
    {
        $attributes = (new ReflectionClass($this->agent))->getAttributes($attribute);

        return $attributes === [] ? null : array_values(get_object_vars($attributes[0]->newInstance()))[0];
    }
}
