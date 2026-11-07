<?php

declare(strict_types=1);

namespace Kestello\Framework\Redis;

/** Connection failure, protocol error, or an error reply from the server. @api */
final class RedisException extends \RuntimeException {}
