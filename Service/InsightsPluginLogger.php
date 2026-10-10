<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Psr\Log\LoggerInterface;
use Stringable;

/**
 * Forwards PSR-3 calls to the inner logger only when {@see InsightsPluginLoggingFeatureReader} allows it.
 */
final class InsightsPluginLogger implements LoggerInterface
{
    public function __construct(
        private LoggerInterface $inner,
        private InsightsPluginLoggingFeatureReader $loggingReader,
    ) {
    }

    public function emergency(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->emergency($message, $context);
        }
    }

    public function alert(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->alert($message, $context);
        }
    }

    public function critical(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->critical($message, $context);
        }
    }

    public function error(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->error($message, $context);
        }
    }

    public function warning(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->warning($message, $context);
        }
    }

    public function notice(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->notice($message, $context);
        }
    }

    public function info(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->info($message, $context);
        }
    }

    public function debug(string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->debug($message, $context);
        }
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->log($level, $message, $context);
        }
    }
}
