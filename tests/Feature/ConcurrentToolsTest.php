<?php

use Illuminate\Contracts\Concurrency\Driver;
use Illuminate\Support\Defer\DeferredCallback;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\Event;
use Laravel\Ai\AiManager;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Spatie\Fork\Fork;
use Tests\Fixtures\Tools\ConcurrentEchoTool;
use Tests\Fixtures\Tools\EmptyToolException;
use Tests\Fixtures\Tools\LabeledToolException;
use Tests\Fixtures\Tools\SequentialEchoTool;

use function Illuminate\Support\defer;

beforeEach(function () {
    SequentialEchoTool::reset();
    config(['concurrency.default' => 'sync']);
});

test('drivers agree on results, events, and failures', function (string $mode, string $case) {
    if ($mode === 'fork' && ! class_exists(Fork::class)) {
        $this->markTestSkipped('spatie/fork is not installed.');
    }

    $failing = str_ends_with($case, 'fails');
    $labels = ['a', 'b'];
    $throws = fn (string $label): bool => $label === 'b' && $failing;
    $events = [];
    $traceAtFirstInvoked = null;
    $recordTrace = false;

    Event::listen(function (InvokingTool $event) use (&$events) {
        $events[] = ['invoking', $event->tool->name(), $event->arguments, $event->toolInvocationId];
    });

    Event::listen(function (ToolInvoked $event) use (&$events, &$traceAtFirstInvoked, &$recordTrace) {
        if ($recordTrace) {
            $traceAtFirstInvoked ??= SequentialEchoTool::$trace;
        }

        $events[] = ['invoked', $event->tool->name(), $event->result, $event->toolInvocationId, is_float($event->time)];
    });

    Event::listen(function (ToolFailed $event) use (&$events) {
        $events[] = ['failed', $event->tool->name(), $event->exception::class, $event->exception->getMessage(), $event->exception->getCode(), $event->toolInvocationId, is_float($event->time)];
    });

    $single = array_map(
        fn (string $label): SequentialEchoTool => new SequentialEchoTool($label, $throws($label), $throws($label) ? 7 : 0),
        $labels,
    );

    concurrentRun($single, $labels, $failing);
    concurrentAssertEvents($events, $case);

    $events = [];
    SequentialEchoTool::reset();
    concurrentMode($mode);
    $recordTrace = true;

    $pause = in_array($mode, ['process', 'fork'], true) && $case === 'two' ? 400000 : 0;
    $tools = array_map(
        fn (string $label): ConcurrentEchoTool => concurrentTool($label, $throws($label), $pause),
        $labels,
    );

    $started = hrtime(true);
    concurrentRun($tools, $labels, $failing);
    $elapsed = (hrtime(true) - $started) / 1e6;

    concurrentAssertEvents($events, $case);

    if (in_array($mode, ['process', 'fork'], true)) {
        expect(SequentialEchoTool::$trace)->toBe([]);

        if ($pause > 0) {
            expect($elapsed)->toBeLessThan(800);
        }

        return;
    }

    expect($traceAtFirstInvoked)->toBe(concurrentTraceAtFirstInvoked($mode));
})->with([
    ['sync', 'two'],
    ['sync', 'second-fails'],
    ['process', 'two'],
    ['process', 'second-fails'],
    ['fork', 'two'],
    ['fork', 'second-fails'],
    ['reverse', 'two'],
    ['reverse', 'second-fails'],
]);

test('a normal tool is a barrier between opted-in calls', function (string $mode) {
    concurrentMode($mode);

    $tools = [
        new ConcurrentEchoTool('a'),
        new ConcurrentEchoTool('b'),
        new SequentialEchoTool('c'),
        new ConcurrentEchoTool('d'),
        new ConcurrentEchoTool('e'),
    ];
    $traceAtFirstInvoked = null;

    Event::listen(function (ToolInvoked $event) use (&$traceAtFirstInvoked) {
        $traceAtFirstInvoked ??= SequentialEchoTool::$trace;
    });

    $response = concurrentStep(new ConcurrentOrderAgent($tools), ['a', 'b', 'c', 'd', 'e']);

    expect($response->toolResults->map(fn ($result): string => (string) $result->result)->all())
        ->toBe(['result:a', 'result:b', 'result:c', 'result:d', 'result:e'])
        ->and($traceAtFirstInvoked)->toBe($mode === 'reverse' ? ['b', 'a'] : ['a', 'b'])
        ->and(SequentialEchoTool::$trace)->toBe($mode === 'reverse' ? ['b', 'a', 'c', 'e', 'd'] : ['a', 'b', 'c', 'd', 'e']);
})->with(['sync', 'reverse']);

test('a custom exception keeps its constructor arguments', function (string $mode) {
    if ($mode === 'fork' && ! class_exists(Fork::class)) {
        $this->markTestSkipped('spatie/fork is not installed.');
    }

    concurrentMode($mode);

    $traceAtFirstInvoked = null;

    Event::listen(function (ToolInvoked $event) use (&$traceAtFirstInvoked) {
        $traceAtFirstInvoked ??= SequentialEchoTool::$trace;
    });

    $caught = null;

    try {
        concurrentStep(new ConcurrentOrderAgent([
            new ConcurrentEchoTool('a'),
            new ConcurrentEchoTool('b', labeled: true),
        ]), ['a', 'b']);
    } catch (LabeledToolException $exception) {
        $caught = $exception;
    }

    expect($caught?->label)->toBe('b')
        ->and($caught?->items->all())->toBe(['b'])
        ->and($caught?->getMessage())->toBe('kaboom')
        ->and($caught?->getCode())->toBe(7)
        ->and($traceAtFirstInvoked)->toBe(in_array($mode, ['process', 'fork'], true) ? [] : ['a', 'b']);
})->with(['sync', 'process', 'fork']);

test('an exception with no constructor arguments keeps its class', function () {
    concurrentMode('sync');

    $traceAtFirstInvoked = null;

    Event::listen(function (ToolInvoked $event) use (&$traceAtFirstInvoked) {
        $traceAtFirstInvoked ??= SequentialEchoTool::$trace;
    });

    $caught = null;

    try {
        concurrentStep(new ConcurrentOrderAgent([
            new ConcurrentEchoTool('a'),
            new ConcurrentEchoTool('b', bare: true),
        ]), ['a', 'b']);
    } catch (EmptyToolException $exception) {
        $caught = $exception;
    }

    expect($caught?->getMessage())->toBe('kaboom')
        ->and($caught?->getCode())->toBe(7)
        ->and($traceAtFirstInvoked)->toBe(['a', 'b']);
});

function concurrentMode(string $mode): void
{
    if ($mode !== 'reverse') {
        config(['concurrency.default' => $mode]);

        return;
    }

    Concurrency::extend('reverse', fn (): Driver => new class implements Driver
    {
        public function run(Closure|array $tasks, $timeout = null): array
        {
            $results = [];

            foreach (array_reverse($tasks, true) as $key => $task) {
                $results[$key] = $task();
            }

            return $results;
        }

        public function defer(Closure|array $tasks): DeferredCallback
        {
            return defer(fn (): array => $this->run($tasks));
        }
    });

    config(['concurrency.default' => 'reverse']);
}

function concurrentTool(string $label, bool $throws, int $pause): ConcurrentEchoTool
{
    return new ConcurrentEchoTool($label, $throws, $throws ? 7 : 0, $pause);
}

/**
 * @param  list<SequentialEchoTool>  $tools
 * @param  list<string>  $labels
 */
function concurrentRun(array $tools, array $labels, bool $failing): void
{
    $run = fn (): AgentResponse => concurrentStep(new ConcurrentOrderAgent($tools), $labels);

    if ($failing) {
        expect($run)->toThrow(RuntimeException::class, 'kaboom');

        return;
    }

    $response = $run();

    expect($response->toolResults->map(fn ($result): string => (string) $result->result)->all())
        ->toBe(array_map(fn (string $label): string => 'result:'.$label, $labels));
}

/**
 * @param  list<array<int, mixed>>  $events
 */
function concurrentAssertEvents(array $events, string $case): void
{
    expect(concurrentEventShape($events))->toBe(concurrentExpectedShape($case));

    $seen = [];

    foreach ($events as $event) {
        $id = $event[0] === 'failed' ? $event[5] : $event[3];

        expect($id)->toBeString()->not->toBeEmpty();

        if (isset($seen[$event[1]])) {
            expect($id)->toBe($seen[$event[1]]);
        }

        $seen[$event[1]] = $id;
    }
}

/**
 * @return list<string>
 */
function concurrentTraceAtFirstInvoked(string $mode): array
{
    if ($mode === 'reverse') {
        return ['b', 'a'];
    }

    return ['a', 'b'];
}

/**
 * @param  list<array<int, mixed>>  $events
 * @return list<array<int, mixed>>
 */
function concurrentEventShape(array $events): array
{
    return array_map(fn (array $event): array => match ($event[0]) {
        'invoking' => ['invoking', $event[1], $event[2]],
        'invoked' => ['invoked', $event[1], $event[2], $event[4]],
        'failed' => ['failed', $event[1], $event[2], $event[3], $event[4], $event[6]],
    }, $events);
}

/**
 * @return list<array<int, mixed>>
 */
function concurrentExpectedShape(string $case): array
{
    return match ($case) {
        'two' => [
            ['invoking', 'a', []],
            ['invoked', 'a', 'result:a', true],
            ['invoking', 'b', []],
            ['invoked', 'b', 'result:b', true],
        ],
        'second-fails' => [
            ['invoking', 'a', []],
            ['invoked', 'a', 'result:a', true],
            ['invoking', 'b', []],
            ['failed', 'b', RuntimeException::class, 'kaboom', 7, true],
        ],
    };
}

/**
 * @param  list<string>  $labels
 */
function concurrentStep(ConcurrentOrderAgent $agent, array $labels): AgentResponse
{
    app(AiManager::class)->textProvider()->useTextGateway(new ConcurrentStepGateway([
        new StepResponse('', array_map(
            fn (string $label): ToolCall => new ToolCall($label, $label, []),
            $labels,
        ), FinishReason::ToolCalls, new TextUsage, new Meta('fake', 'model')),
        new StepResponse('Done.', [], FinishReason::Stop, new TextUsage, new Meta('fake', 'model')),
    ]));

    return $agent->prompt('Run the tools');
}

class ConcurrentOrderAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  array<int, SequentialEchoTool>  $toolList
     */
    public function __construct(public array $toolList) {}

    public function instructions(): string
    {
        return 'Call the tools.';
    }

    public function tools(): iterable
    {
        return $this->toolList;
    }
}

class ConcurrentStepGateway implements StepTextGateway
{
    /**
     * @param  list<StepResponse>  $steps
     */
    public function __construct(public array $steps) {}

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        return array_shift($this->steps);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        yield from [];

        return null;
    }
}
