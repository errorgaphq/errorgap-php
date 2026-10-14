<?php

declare(strict_types=1);

namespace Errorgap\Monolog;

use Errorgap\Errorgap;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Monolog 3 handler: report error-level log records to Errorgap.
 *
 *     $logger->pushHandler(new \Errorgap\Monolog\Handler());
 *
 * A record that carries a Throwable under `context.exception` (how PSR-3
 * loggers and frameworks pass one) reports that exception. Any other record
 * is reported as a LoggedError carrying the message. The channel, level and
 * the rest of the record's context go with it.
 */
final class Handler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Error, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        try {
            $context = $record->context;
            $exception = $context['exception'] ?? null;
            unset($context['exception']);

            $noticeContext = [
                'source' => 'monolog',
                'component' => $record->channel,
                'severity' => strtolower($record->level->getName()),
            ];
            if ($exception instanceof \Throwable) {
                $noticeContext['log_message'] = $record->message;
            } else {
                $exception = new LoggedError($record->message);
            }

            Errorgap::notify($exception, context: $noticeContext, params: $context);
        } catch (\Throwable) {
            // A log handler must never break the code that logged.
        }
    }
}
