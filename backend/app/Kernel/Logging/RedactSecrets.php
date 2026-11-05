<?php

declare(strict_types=1);

namespace SiteXPost\Kernel\Logging;

use Illuminate\Log\Logger;
use Monolog\Logger as MonologLogger;
use Monolog\LogRecord;

/**
 * Logging tap that redacts secret-looking keys from log context.
 */
final class RedactSecrets
{
    private const PATTERN = '/(password|secret|token|authorization|signature|api[_-]?key|private[_-]?key|_key$|cookie|recovery)/i';

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof MonologLogger) {
            $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
                context: self::redact($record->context),
                extra: self::redact($record->extra),
            ));
        }
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
