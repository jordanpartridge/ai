<?php

namespace Laravel\Ai\Attributes;

use Attribute;

/**
 * Run beside other marked calls until a normal call. The handler runs in the concurrency driver. Failures travel as class, message, and code.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Concurrent
{
    //
}
