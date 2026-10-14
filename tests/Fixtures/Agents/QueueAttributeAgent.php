<?php

namespace Tests\Fixtures\Agents;

use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Connection;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Tries;

#[Tries(3), Backoff([10, 60]), MaxExceptions(2)]
#[Connection('redis'), Queue('ai')]
class QueueAttributeAgent extends AssistantAgent
{
    //
}
