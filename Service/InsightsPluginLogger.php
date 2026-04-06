<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInsightsBundle\Service;

use Psr\Log\LoggerInterface;

/**
 * Forwards PSR-3 calls to the inner logger only when {@see InsightsPluginLoggingFeatureReader} allows it.
 *
 * Method signatures intentionally match {@see LoggerInterface} as loaded with psr/log 1.x (untyped $message),
 * so this class works in Mautic installs that resolve an older psr/log than 3.x.
 */
final class InsightsPluginLogger implements LoggerInterface
{
    public function __construct(
        private LoggerInterface $inner,
        private InsightsPluginLoggingFeatureReader $loggingReader,
    ) {
    }

    public function emergency($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->emergency($message, $context);
        }
    }

    public function alert($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->alert($message, $context);
        }
    }

    public function critical($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->critical($message, $context);
        }
    }

    public function error($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->error($message, $context);
        }
    }

    public function warning($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->warning($message, $context);
        }
    }

    public function notice($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->notice($message, $context);
        }
    }

    public function info($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->info($message, $context);
        }
    }

    public function debug($message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->debug($message, $context);
        }
    }

    public function log($level, $message, array $context = []): void
    {
        if ($this->loggingReader->isPluginLoggingEnabled()) {
            $this->inner->log($level, $message, $context);
        }
    }
}
