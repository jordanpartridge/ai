<?php

use Illuminate\Support\Facades\Event;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Tests\Fixtures\Tools\FixedNumberGenerator;
use Tests\Fixtures\Tools\LookupTool;

beforeEach(function (): void {
    config(['concurrency.default' => 'sync']);

    LookupTool::$runs = 0;
});

test('lookups the model asked for come back in that order', function () {
    $agent = recordsAgent([
        new LookupTool('weather', '72 and sunny'),
        new FixedNumberGenerator,
        new LookupTool('inventory', '14 units'),
    ]);

    Ai::fakeAgent($agent::class, [
        toolStep([
            ['call_inventory', 'inventory'],
            ['call_number', 'FixedNumberGenerator'],
            ['call_weather', 'weather'],
        ]),
        'Inventory is 14 units, reference 72019, and it is 72 and sunny.',
    ]);

    $response = $agent->prompt('Check inventory, give me a reference number, and check the weather');

    expect($response->toolResults->pluck('result')->all())->toBe([
        '14 units',
        '72019',
        '72 and sunny',
    ])->and($response->text)->toBe('Inventory is 14 units, reference 72019, and it is 72 and sunny.');
});

test('a failed lookup still lets the others finish, then throws', function () {
    Event::fake();

    $agent = recordsAgent([
        new LookupTool('weather', '72 and sunny'),
        new LookupTool('inventory', error: 'Inventory service timed out.'),
    ]);

    Ai::fakeAgent($agent::class, [
        toolStep([
            ['call_inventory', 'inventory'],
            ['call_weather', 'weather'],
        ]),
    ]);

    expect(fn () => $agent->prompt('Check the weather and the inventory'))
        ->toThrow(RuntimeException::class, 'Inventory service timed out.');

    expect(LookupTool::$runs)->toBe(2);

    Event::assertDispatched(ToolInvoked::class, fn (ToolInvoked $event): bool => $event->result === '72 and sunny');
    Event::assertDispatchedTimes(ToolInvoked::class, 1);
    Event::assertDispatched(ToolFailed::class, fn (ToolFailed $event): bool => $event->exception->getMessage() === 'Inventory service timed out.');
    Event::assertDispatched(AgentFailed::class);
});

test('a validation error comes back as text beside the other lookup', function () {
    $agent = recordsAgent([
        new LookupTool('weather', '72 and sunny'),
        new LookupTool('inventory', invalid: true),
    ]);

    Ai::fakeAgent($agent::class, [
        toolStep([
            ['call_weather', 'weather'],
            ['call_inventory', 'inventory'],
        ]),
        'The inventory lookup needs a city.',
    ]);

    $response = $agent->prompt('Check the weather and the inventory');

    expect($response->toolResults->pluck('result')->all())->toBe([
        '72 and sunny',
        'The city field is required.',
    ])->and($response->toolResults->every(fn ($result): bool => $result->failed === false))->toBeTrue();
});

test('a lookup that needs approval waits while the other one runs', function () {
    $agent = recordsAgent([
        (new LookupTool('wire', 'sent'))->requireApproval('Sends a wire.'),
        new LookupTool('balance', '400'),
    ]);

    Ai::fakeAgent($agent::class, [
        toolStep([
            ['call_wire', 'wire'],
            ['call_balance', 'balance'],
        ]),
    ]);

    $response = $agent->withMessages([])->prompt('Check the balance and send the wire');

    expect($response->hasPendingApprovals())->toBeTrue()
        ->and($response->pendingApprovals->pluck('tool')->all())->toBe(['wire'])
        ->and($response->toolResults->pluck('result')->all())->toBe(['400'])
        ->and(LookupTool::$runs)->toBe(1);
});

test('streaming still returns the lookups in call order', function () {
    $agent = recordsAgent([
        new LookupTool('weather', '72 and sunny'),
        new LookupTool('inventory', '14 units'),
    ]);

    Ai::fakeAgent($agent::class, [
        toolStep([
            ['call_inventory', 'inventory'],
            ['call_weather', 'weather'],
        ]),
        'Both ready.',
    ]);

    $results = null;

    $agent->stream('Check inventory and the weather')->then(function ($response) use (&$results): void {
        $results = $response->toolResults->pluck('result')->all();
    })->each(fn () => null);

    expect($results)->toBe(['14 units', '72 and sunny']);
});

/**
 * @param  array<int, array{0: string, 1: string}>  $calls
 */
function toolStep(array $calls): StepResponse
{
    return new StepResponse(
        '',
        array_map(fn (array $call): ToolCall => new ToolCall($call[0], $call[1], []), $calls),
        FinishReason::ToolCalls,
        new TextUsage,
        new Meta('fake', 'model'),
    );
}

/**
 * @param  array<int, object>  $tools
 */
function recordsAgent(array $tools): Agent
{
    return new class($tools) implements Agent, HasTools
    {
        use Promptable;

        /**
         * @param  array<int, object>  $records
         */
        public function __construct(public array $records) {}

        public function instructions(): string
        {
            return 'Look up the records you are asked for.';
        }

        public function tools(): iterable
        {
            return $this->records;
        }
    };
}
