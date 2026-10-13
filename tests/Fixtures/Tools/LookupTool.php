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

#[Concurrent]
class LookupTool implements Approvable, Tool
{
    use InteractsWithApprovals;

    public static int $runs = 0;

    public function __construct(
        public string $record,
        public string $answer = '',
        public bool $invalid = false,
        public ?string $error = null,
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

        if ($this->invalid) {
            $request->validate(['city' => 'required']);
        }

        if ($this->error !== null) {
            throw new RuntimeException($this->error);
        }

        return $this->answer;
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
