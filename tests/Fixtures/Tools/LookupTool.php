<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Attributes\Concurrent;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Throwable;

#[Concurrent]
class LookupTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public static int $runs = 0;

    /** @var list<string> */
    public static array $trace = [];

    public static ?Throwable $thrown = null;

    public function __construct(
        public string $record,
        public string $answer = '',
        public bool $invalid = false,
        public ?string $error = null,
        public int $code = 0,
        public ?string $gate = null,
    ) {}

    public function description(): string
    {
        return 'Looks up '.$this->record.'.';
    }

    public function name(): string
    {
        return $this->record;
    }

    public function handle(Request $request): string
    {
        static::$runs++;
        static::$trace[] = $this->record;

        if ($this->gate !== null) {
            return $this->waitForOverlap($this->gate);
        }

        if ($this->invalid) {
            $request->validate(['city' => 'required']);
        }

        if ($this->error !== null) {
            throw static::$thrown = new RuntimeException($this->error, $this->code);
        }

        return $this->answer;
    }

    /**
     * Both calls return overlapped only when they are inside this method together.
     */
    protected function waitForOverlap(string $path): string
    {
        file_put_contents($path, "1\n", FILE_APPEND | LOCK_EX);

        $deadline = microtime(true) + 3;

        do {
            clearstatcache(true, $path);

            if (count(file($path, FILE_IGNORE_NEW_LINES) ?: []) >= 2) {
                return 'overlapped';
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        return 'alone';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return false;
    }
}
