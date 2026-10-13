<?php

namespace Tests\Fixtures\Tools;

use RuntimeException;

class EmptyToolException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('kaboom', 7);
    }
}
