<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file'), Group('logs')]
final class ConfigLoggerConfiguratorTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Logger configurator
     * =========================================================================
     */

    public function testLoggerConfiguratorCanDisableDefaultLoggersAndEnableMatchingLogger(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: enabled.logger
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('disabled.logger')
                    ->emit(new LogRecord('disabled'));

                Globals::loggerProvider()
                    ->getLogger('enabled.logger')
                    ->emit(new LogRecord('enabled'));
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorSupportsWildcardMatching(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: application.*
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('application.http')
                    ->emit(new LogRecord('enabled'));

                Globals::loggerProvider()
                    ->getLogger('library.http')
                    ->emit(new LogRecord('disabled'));
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorCanDisableMatchingLoggerWithDefaultEnabled(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: true
                loggers:
                  - name: noisy.logger
                    config:
                      enabled: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('normal.logger')
                    ->emit(new LogRecord('normal'));

                Globals::loggerProvider()
                    ->getLogger('noisy.logger')
                    ->emit(new LogRecord('noisy'));
            },
        );

        self::assertSame(
            ['normal'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorMinimumSeverity(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: true
                loggers:
                  - name: application.logger
                    config:
                      minimum_severity: warn

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('application.logger');

                $logger->emit(
                    (new LogRecord('debug'))
                        ->setSeverityNumber(5),
                );

                $logger->emit(
                    (new LogRecord('info'))
                        ->setSeverityNumber(9),
                );

                $logger->emit(
                    (new LogRecord('warning'))
                        ->setSeverityNumber(13),
                );

                $logger->emit(
                    (new LogRecord('error'))
                        ->setSeverityNumber(17),
                );
            },
        );

        self::assertSame(
            ['warning', 'error'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorMinimumSeverityFiltersBelowThreshold(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: severity-test.logger
                    config:
                      minimum_severity: warn

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('severity-test.logger');

                $logger->emit(
                    (new LogRecord('severity.info'))
                        ->setSeverityNumber(9)
                        ->setSeverityText('INFO'),
                );

                $logger->emit(
                    (new LogRecord('severity.warn'))
                        ->setSeverityNumber(13)
                        ->setSeverityText('WARN'),
                );

                $logger->emit(
                    (new LogRecord('severity.error'))
                        ->setSeverityNumber(17)
                        ->setSeverityText('ERROR'),
                );

                // Severity 0 means unspecified and bypasses minimum severity filtering.
                $logger->emit(
                    new LogRecord('severity.unspecified'),
                );
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertNotContains('severity.info', $bodies);
        self::assertContains('severity.warn', $bodies);
        self::assertContains('severity.error', $bodies);
        self::assertContains('severity.unspecified', $bodies);
    }

    public function testLoggerConfiguratorTraceBasedFalseDoesNotFilterUnsampledTraces(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: trace-disabled.logger
                    config:
                      trace_based: false

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('trace-disabled.logger');

                $span = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('a', 32),
                        str_repeat('b', 16),
                    ),
                );

                $context = $span->storeInContext(
                    \OpenTelemetry\Context\Context::getCurrent(),
                );

                $logger->emit(
                    (new LogRecord('trace-disabled.unsampled'))
                        ->setContext($context),
                );

                $logger->emit(
                    new LogRecord('trace-disabled.no-context'),
                );
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertContains('trace-disabled.unsampled', $bodies);
        self::assertContains('trace-disabled.no-context', $bodies);
    }

    public function testLoggerConfiguratorQuestionMarkWildcard(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: app.?
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $loggerProvider = Globals::loggerProvider();

                $loggerProvider
                    ->getLogger('app.a')
                    ->emit(new LogRecord('enabled'));

                $loggerProvider
                    ->getLogger('app.ab')
                    ->emit(new LogRecord('disabled'));
            },
        );

        self::assertSame(
            ['enabled'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorMatchingIsCaseSensitive(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  enabled: false
                loggers:
                  - name: app.logger
                    config:
                      enabled: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $loggerProvider = Globals::loggerProvider();

                $loggerProvider
                    ->getLogger('app.logger')
                    ->emit(new LogRecord('lowercase'));

                $loggerProvider
                    ->getLogger('APP.LOGGER')
                    ->emit(new LogRecord('uppercase'));
            },
        );

        self::assertSame(
            ['lowercase'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testLoggerConfiguratorTraceBasedDropsLogsFromUnsampledTraces(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: trace-based.logger
                    config:
                      trace_based: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('trace-based.logger');

                $unsampledSpan = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('a', 32),
                        str_repeat('b', 16),
                    ),
                );

                $scope = $unsampledSpan->activate();

                $logger->emit(new LogRecord('unsampled'));

                $scope->detach();

                $sampledSpan = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('c', 32),
                        str_repeat('d', 16),
                        TraceFlags::SAMPLED,
                    ),
                );

                $scope = $sampledSpan->activate();

                $logger->emit(new LogRecord('sampled'));

                $scope->detach();

                // No trace context.
                $logger->emit(new LogRecord('no-context'));
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertSame(
            ['sampled', 'no-context'],
            $bodies,
        );

        self::assertNotContains('unsampled', $bodies);
    }

    public function testLoggerConfiguratorTraceBasedFiltersUnsampledTraceRecords(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                loggers:
                  - name: trace-based.logger
                    config:
                      trace_based: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('trace-based.logger');

                $unsampledContext = SpanContext::create(
                    str_repeat('a', 32),
                    str_repeat('b', 16),
                    \OpenTelemetry\API\Trace\TraceFlags::DEFAULT,
                );

                $sampledContext = SpanContext::create(
                    str_repeat('c', 32),
                    str_repeat('d', 16),
                    \OpenTelemetry\API\Trace\TraceFlags::SAMPLED,
                );

                $logger->emit(
                    (new LogRecord('unsampled'))
                        ->setContext(Span::wrap($unsampledContext)->storeInContext(Context::getCurrent())),
                );

                $logger->emit(
                    (new LogRecord('sampled'))
                        ->setContext(Span::wrap($sampledContext)->storeInContext(Context::getCurrent())),
                );

                $logger->emit(
                    new LogRecord('no-context'),
                );
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertContains('sampled', $bodies);
        self::assertContains('no-context', $bodies);
        self::assertNotContains('unsampled', $bodies);
    }

    public function testLoggerConfiguratorDefaultConfigAppliesMinimumSeverityAndTraceBased(): void
    {
        /*
         * The default_config sub-options apply to every logger that does not
         * override them: here, records below ERROR are dropped and records
         * from unsampled traces are dropped as well.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              logger_configurator/development:
                default_config:
                  minimum_severity: error
                  trace_based: true

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()
                    ->getLogger('default-config.logger');

                $sampledSpan = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('c', 32),
                        str_repeat('d', 16),
                        TraceFlags::SAMPLED,
                    ),
                );

                $scope = $sampledSpan->activate();

                /* Below the minimum severity -> dropped. */
                $logger->emit(
                    (new LogRecord('info-in-sampled-span'))
                        ->setSeverityNumber(9),
                );

                /* Above the minimum severity, sampled trace -> kept. */
                $logger->emit(
                    (new LogRecord('error-in-sampled-span'))
                        ->setSeverityNumber(17),
                );

                $scope->detach();

                $unsampledSpan = \OpenTelemetry\API\Trace\Span::wrap(
                    SpanContext::create(
                        str_repeat('a', 32),
                        str_repeat('b', 16),
                    ),
                );

                $scope = $unsampledSpan->activate();

                /* From an unsampled trace -> dropped. */
                $logger->emit(
                    (new LogRecord('error-in-unsampled-span'))
                        ->setSeverityNumber(17),
                );

                $scope->detach();

                /* No trace context at all -> kept. */
                $logger->emit(
                    (new LogRecord('error-no-context'))
                        ->setSeverityNumber(17),
                );
            },
        );

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
        );

        self::assertSame(
            ['error-in-sampled-span', 'error-no-context'],
            $bodies,
        );
    }
}
