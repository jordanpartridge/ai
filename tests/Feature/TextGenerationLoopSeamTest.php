<?php

use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Gateway\TextGenerationLoop;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Agents\NamedToolAgent;
use Tests\Fixtures\Tools\FixedNumberGenerator;

test('a bound loop replaces how a step runs its tools', function () {
    BoundLoop::$used = false;

    app()->bind(TextGenerationLoop::class, fn ($app, array $parameters) => new class($parameters['gateway']) extends TextGenerationLoop
    {
        protected function invokeResolvedTools(array $resolved, bool $isFinalStep, array $tools, ?RunContext $context): array
        {
            BoundLoop::$used = true;

            return parent::invokeResolvedTools($resolved, $isFinalStep, $tools, $context);
        }
    });

    NamedToolAgent::fake([
        new ToolCall('call_1', 'FixedNumberGenerator', []),
        'Done.',
    ]);

    $response = (new NamedToolAgent)
        ->withTools([new FixedNumberGenerator])
        ->prompt('Generate a number');

    expect(BoundLoop::$used)->toBeTrue()
        ->and($response->toolResults->pluck('result')->all())->toBe(['72019']);
});

class BoundLoop
{
    public static bool $used = false;
}
