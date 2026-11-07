<?php

declare(strict_types=1);

namespace Kestello\Framework\Redis;

/** @internal socket closed or failed mid-exchange */
final class RedisConnectionLost extends \RuntimeException {}
