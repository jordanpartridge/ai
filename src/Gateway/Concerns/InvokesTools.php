<?php

namespace Laravel\Ai\Gateway\Concerns;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Gateway\ParentInvocation;
use Laravel\Ai\Gateway\RunContext;
use Laravel\Ai\Providers\Tools\ToolSearch;
use Laravel\Ai\Tools\Request;
use Laravel\Ai\Tools\ToolNameResolver;
use ReflectionClass;
use RuntimeException;
use Throwable;

trait InvokesTools
{
    use MeasuresDuration;

    /**
     * Execute the given tool with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function executeTool(Tool $tool, array $arguments, ?string $toolCallId = null, ?RunContext $context = null): string
    {
        $toolInvocationId = (string) Str::uuid7();

        // Any agent prompted while this tool runs, however it was reached, is a child of this tool call...
        return ParentInvocation::within($context?->invocationId, $toolInvocationId, function () use ($tool, $arguments, $toolCallId, $toolInvocationId, $context): string {
            $context?->invokingTool($tool, $arguments, $toolInvocationId);

            $startedAt = hrtime(true);

            // Only the handler itself may fail the tool call, so a listener that throws is never reported as a tool failure...
            try {
                $result = self::handleTool($tool, $arguments, $toolCallId, $toolInvocationId);
            } catch (Throwable $exception) {
                $context?->toolFailed($tool, $arguments, $exception, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

                throw $exception;
            }

            $context?->toolInvoked($tool, $arguments, $result, $toolInvocationId, $this->elapsedMilliseconds($startedAt));

            return (string) $result;
        });
    }

    /**
     * Find a tool by its name from the given tools array.
     */
    protected function findTool(string $name, array $tools): ?Tool
    {
        foreach ($tools as $tool) {
            if ($tool instanceof ToolSearch) {
                if ($nested = $this->findTool($name, $tool->tools)) {
                    return $nested;
                }

                continue;
            }

            if ($tool instanceof Tool && ToolNameResolver::resolve($tool) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Run the handler. Validation errors are text for the model, not a failed call.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected static function handleTool(Tool $tool, array $arguments, ?string $toolCallId, string $toolInvocationId): mixed
    {
        try {
            return $tool->handle(new Request($arguments, $toolCallId, $toolInvocationId));
        } catch (ValidationException $exception) {
            // Validation failures are returned to the model so it can correct the arguments and retry...
            return implode(' ', $exception->validator->errors()->all()) ?: $exception->getMessage();
        }
    }

    /**
     * @return array{exception: class-string<Throwable>, message: string, code: int, parameters: array<string, mixed>}
     */
    protected static function toolFailure(Throwable $exception): array
    {
        return [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'code' => (int) $exception->getCode(),
            'parameters' => self::exceptionParameters($exception),
        ];
    }

    /**
     * Rebuild a failure that crossed a process boundary.
     *
     * @param  array{exception: class-string<Throwable>, message: string, code: int, parameters: array<string, mixed>}  $failure
     */
    protected static function exceptionFromFailure(array $failure): Throwable
    {
        if ($failure['parameters'] !== []) {
            return new ($failure['exception'])(...$failure['parameters']);
        }

        $constructor = (new ReflectionClass($failure['exception']))->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $failure['exception']) {
            return new ($failure['exception'])($failure['message'], $failure['code']);
        }

        return new RuntimeException($failure['message'], $failure['code']);
    }

    /**
     * Constructor arguments stored on the exception, the same way the process driver reads them.
     *
     * @return array<string, mixed>
     */
    protected static function exceptionParameters(Throwable $exception): array
    {
        $reflection = new ReflectionClass($exception);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getDeclaringClass()->getName() !== $reflection->getName()) {
            return [];
        }

        $parameters = [];

        foreach ($constructor->getParameters() as $parameter) {
            $property = $reflection->hasProperty($parameter->name) ? $reflection->getProperty($parameter->name) : null;

            if ($property?->isPublic()) {
                $value = $property->getValue($exception);
            } elseif ($parameter->name === 'message') {
                $value = $exception->getMessage();
            } elseif ($parameter->name === 'code') {
                $value = (int) $exception->getCode();
            } elseif ($parameter->isDefaultValueAvailable()) {
                continue;
            } else {
                return [];
            }

            if (! is_null($value) && ! is_scalar($value)) {
                return [];
            }

            $parameters[$parameter->name] = $value;
        }

        return $parameters;
    }
}
