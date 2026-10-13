<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use RuntimeException;

class SequentialEchoTool implements Tool
{
    /** @var list<string> */
    public static array $trace = [];

    public function __construct(
        public string $label,
        public bool $throws = false,
        public int $code = 0,
        public int $pause = 0,
        public bool $labeled = false,
        public bool $bare = false,
    ) {}

    public static function reset(): void
    {
        static::$trace = [];
    }

    public function name(): string
    {
        return $this->label;
    }

    public function description(): string
    {
        return $this->label;
    }

    public function handle(Request $request): string
    {
        static::$trace[] = $this->label;

        if ($this->pause > 0) {
            usleep($this->pause);
        }

        if ($this->labeled) {
            throw new LabeledToolException($this->label, new Collection([$this->label]));
        }

        if ($this->bare) {
            throw new EmptyToolException;
        }

        if ($this->throws) {
            throw new RuntimeException('kaboom', $this->code);
        }

        return 'result:'.$this->label;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
