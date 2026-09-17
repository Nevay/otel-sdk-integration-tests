<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file'), Group('logs')]
final class ConfigLogRecordTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Log record content
     * =========================================================================
     *
     * A logger provider configured via the config file must export log
     * records with full content (severity, typed bodies), like the
     * env-configured pipeline.
     */

    public function testLogRecordsExportedWithSeverityAndTypedBodies(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        encoding: json
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('config-test');

                $logger->emit((new LogRecord('warn-message'))
                    ->setSeverityNumber(13)
                    ->setSeverityText('Warning'));
                $logger->emit(new LogRecord(['nested' => ['key' => 'value'], 'count' => 2]));
            },
        );

        self::assertNotEmpty($this->logs);

        $records = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords',
        );

        self::assertSame(13, $records[0][0]['severityNumber']);
        self::assertSame('Warning', $records[0][0]['severityText']);

        /*
         * Map bodies are exported as OTLP kvlist values, including nested
         * maps; int64 values are encoded as strings in JSON.
         */
        self::assertSame(
            [
                'kvlistValue' => [
                    'values' => [
                        [
                            'key' => 'nested',
                            'value' => [
                                'kvlistValue' => [
                                    'values' => [
                                        ['key' => 'key', 'value' => ['stringValue' => 'value']],
                                    ],
                                ],
                            ],
                        ],
                        ['key' => 'count', 'value' => ['intValue' => '2']],
                    ],
                ],
            ],
            $records[0][1]['body'],
        );
    }

    /*
     * =========================================================================
     * Batch LogRecord Processor
     * =========================================================================
     */

    public function testBatchLogRecordProcessorMaxExportBatchSize(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    max_queue_size: 10
                    max_export_batch_size: 2
                    export_timeout: 1000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('config-test');

                for ($i = 1; $i <= 5; ++$i) {
                    $logger->emit(new LogRecord("log-{$i}"));
                }
            },
        );

        self::assertCount(3, $this->logs);

        self::assertCount(
            2,
            $this->logsInExport($this->logs[0]),
        );

        self::assertCount(
            2,
            $this->logsInExport($this->logs[1]),
        );

        self::assertCount(
            1,
            $this->logsInExport($this->logs[2]),
        );
    }

    public function testBatchLogRecordProcessorScheduleDelay(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    schedule_delay: 60000
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('scheduled-log'));
            },
        );

        self::assertNotEmpty($this->logs);

        self::assertSame(
            'scheduled-log',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * LogRecord limits
     * =========================================================================
     */

    public function testLogRecordLimits(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              limits:
                attribute_count_limit: 2
                attribute_value_length_limit: 4

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $record = new LogRecord('limited-log');

                $record->setAttributes([
                    'a1' => '123456789',
                    'a2' => '123456789',
                    'a3' => '123456789',
                ]);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit($record);
            },
        );

        self::assertCount(
            2,
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].attributes[*]',
            ),
        );

        self::assertSame(
            '1234',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].attributes[?(@.key == "a1")].value.stringValue',
            )[0],
        );
    }

    public function testLogRecordAttributeValueDepthLimit(): void {
        /*
         * With a depth limit of one, arrays inside attribute values are
         * replaced by empty arrays; scalar entries of the same array are
         * kept.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              limits:
                attribute_value_depth_limit: 1

              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $record = new LogRecord('depth-limit-log');

                $record->setAttributes([
                    'nested' => ['a' => ['b' => 'c'], 's' => 'keep'],
                ]);

                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit($record);
            },
        );

        $base = '$.resourceLogs[*].scopeLogs[*].logRecords[?(@.body.stringValue == "depth-limit-log")].attributes[?(@.key == "nested")].value.kvlistValue.values';

        self::assertSame(
            ['a', 's'],
            $this->path($this->logs[0], $base . '[*].key'),
        );

        self::assertSame(
            [['key' => 'a', 'value' => ['arrayValue' => []]]],
            $this->path($this->logs[0], $base . '[0]'),
        );

        self::assertSame(
            ['keep'],
            $this->path($this->logs[0], $base . '[1].value.stringValue'),
        );
    }

    #[Group('async')]
    public function testSimpleLogRecordProcessorExportsOnEmit(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - simple:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('simple-processor'));

                /*
                 * Stay alive after emitting: a batch processor would only
                 * export during the shutdown flush, so an early arrival
                 * proves the simple processor exported on emit.
                 */
                \Amp\delay(0.5);
            },
        );

        self::assertCount(1, $this->logs);
        self::assertSame(
            ['simple-processor'],
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );

        /*
         * The export request arrived well before the process exited: it was
         * sent when the record was emitted, not by the shutdown flush.
         */
        self::assertGreaterThan(
            0.25,
            microtime(true) - $this->requestTimes[0],
        );
    }

    #[Group('traces')]
    public function testLogRecordWithEventNameIsBridgedToSpanEvent(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}

            logger_provider:
              processors:
                - event_to_span_event_bridge/development:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('bridge-parent')
                    ->startSpan();

                $scope = $span->activate();

                /*
                 * A log record with an event name, emitted while the span is
                 * active in the current context, is bridged to a span event.
                 */
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->logRecordBuilder()
                    ->setBody('a log with an event name')
                    ->setEventName('bridge.event')
                    ->setAttribute('k', 'v')
                    ->emit();

                $scope->detach();
                $span->end();
            },
        );

        /*
         * The span carries the log record as an event, including its
         * attributes.
         */
        $events = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "bridge-parent")].events[?(@.name == "bridge.event")]',
        );

        self::assertCount(1, $events);
        self::assertSame(
            [['key' => 'k', 'value' => ['stringValue' => 'v']]],
            $events[0]['attributes'],
        );

        /*
         * The bridge is additive: the log record is still exported through
         * the regular log pipeline, carrying its event name.
         */
        $logRecords = $this->logsInExport($this->logs[0]);

        self::assertCount(1, $logRecords);
        self::assertSame('bridge.event', $logRecords[0]['eventName']);
    }

    /*
     * =========================================================================
     * otlp_http exporter options (logs)
     * =========================================================================
     */

    public function testOtlpHttpExporterHeadersAreSentToCollector(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        headers:
                          - name: auth
                            value: config-token
                          - name: x-custom
                            value: v2
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('config-headers'));
            },
        );

        self::assertNotEmpty($this->logs);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['config-token'], $headers['auth']);
        self::assertSame(['v2'], $headers['x-custom']);
    }

    public function testOtlpHttpGzipCompressionIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        compression: gzip
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('config-gzip'));
            },
        );

        self::assertNotEmpty($this->logs);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['gzip'], $headers['content-encoding']);

        self::assertContains(
            'config-gzip',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            ),
        );
    }

    public function testOtlpHttpEncodingJsonIsApplied(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        encoding: json
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('config-json'));
            },
        );

        self::assertNotEmpty($this->logs);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['application/json'], $headers['content-type']);
    }

    public function testOtlpHttpTimeoutDropsExportWhenCollectorIsSlow(): void {
        /*
         * The timed-out attempt is retryable, so without an upper bound the
         * exporter would keep retrying with exponential backoff for tens of
         * seconds. export_timeout bounds the whole export call (the
         * spec-compliant processor-level limit) so the test stays fast.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    export_timeout: 600
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        timeout: 300
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('config-timeout'));
            },
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=' . str_replace(
                '/v1/logs',
                '/v1/slow',
                $this->env['OTEL_EXPORTER_OTLP_LOGS_ENDPOINT'],
            ),
        );

        /*
         * The export was attempted... the slow route delays its response
         * beyond the 300 ms timeout, so the payload is dropped and the
         * process still shuts down cleanly.
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);
        self::assertSame([], $this->logs);
    }

    public function testOtlpHttpMaxRequestSizeBlocksOversizedExports(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        max_request_size: 1
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('config-oversized'));
            },
        );

        self::assertSame([], $this->logs);
        self::assertStringContainsString(
            'maximum request size',
            strtolower($this->lastStderr),
        );
    }

    public function testOtlpHttpMaxResponseSizeRejectsLargeResponses(): void {
        /*
         * Unlike max_request_size, the request is sent; the exporter only
         * rejects the collector's response body once it exceeds the
         * configured limit. (JSON encoding is used so that the empty
         * response body is larger than one byte.)
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_LOGS_ENDPOINT}
                        encoding: json
                        max_response_size: 1
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('config-oversized-response'));
            },
        );

        self::assertStringContainsString(
            'buffer length limit',
            strtolower($this->lastStderr),
        );
    }

    public function testConsoleExporterWritesLogsToStdout(): void {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            logger_provider:
              processors:
                - batch:
                    exporter:
                      console:
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('config-test')
                    ->emit(new LogRecord('console-log'));
            },
        );

        /*
         * The specification leaves the console exporter's output format
         * unspecified ("can vary between implementations"), so we only pin
         * down that the record reaches stdout and does not go to the OTLP
         * HTTP collector.
         */
        self::assertStringContainsString('console-log', $output);
        self::assertSame([], $this->logs);
    }
}
