<?php

namespace Tests\Fixtures\Tools;

use Illuminate\Support\Collection;
use RuntimeException;

class LabeledToolException extends RuntimeException
{
    /**
     * @param  Collection<int, string>  $items
     */
    public function __construct(
        public string $label,
        public Collection $items,
        string $message = 'kaboom',
        int $code = 7,
    ) {
        parent::__construct($message, $code);
    }
}
