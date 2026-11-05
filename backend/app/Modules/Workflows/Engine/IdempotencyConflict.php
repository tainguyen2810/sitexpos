<?php

declare(strict_types=1);

namespace SiteXPost\Modules\Workflows\Engine;

use RuntimeException;

final class IdempotencyConflict extends RuntimeException {}
