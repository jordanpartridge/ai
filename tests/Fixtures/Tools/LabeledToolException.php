<?php

namespace Tests\Fixtures\Tools;

use RuntimeException;

class LabeledToolException extends RuntimeException
{
    public function __construct(public string $label, string $message = 'kaboom', int $code = 7)
    {
        parent::__construct($message, $code);
    }
}
