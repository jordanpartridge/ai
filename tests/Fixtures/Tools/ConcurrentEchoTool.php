<?php

namespace Tests\Fixtures\Tools;

use Laravel\Ai\Attributes\Concurrent;

#[Concurrent]
class ConcurrentEchoTool extends SequentialEchoTool {}
