<?php

namespace Laravel\Ai\Attributes;

use Attribute;

/**
 * Run beside other marked calls until a normal call.
 *
 * The process driver serializes the tool and its result, and stops a call after 60 seconds. Events are recorded in call order after the calls finish.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Concurrent
{
    //
}
