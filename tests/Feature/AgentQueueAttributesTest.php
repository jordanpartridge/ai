<?php

use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Jobs\InvokeAgent;
use Tests\Fixtures\Agents\QueueAttributeAgent;

test('queued agents respect queue attributes', function (): void {
    Queue::fake();

    QueueAttributeAgent::make()->queue('Summarize the inbox');
    QueueAttributeAgent::make()->queue('Summarize the inbox')->onConnection('sqs')->onQueue('high');

    $jobs = Queue::pushed(InvokeAgent::class);

    expect($jobs[0])->tries->toBe(3)->backoff->toBe([10, 60])->maxExceptions->toBe(2)
        ->connection->toBe('redis')->queue->toBe('ai');
    expect($jobs[1])->connection->toBe('sqs')->queue->toBe('high');
})->skip(! class_exists(Tries::class), 'Queue attributes require Laravel 13.');
