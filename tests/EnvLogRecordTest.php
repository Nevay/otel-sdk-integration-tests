<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('env'), Group('logs')]
final class EnvLogRecordTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * LogRecord limits
     * =========================================================================
     */

    public function testLogRecordAttributeCountLimit(): void {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('log-attributes');

                $record->setAttributes([
                    'a1' => '1',
                    'a2' => '2',
                    'a3' => '3',
                ]);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT=2',
        );

        self::assertCount(
            2,
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[0].attributes[*]',
            ),
        );
    }

    public function testLogRecordAttributeValueLengthLimit(): void {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('log-value');

                $record->setAttributes([
                    'test.attribute' => 'abcdefghij',
                ]);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_VALUE_LENGTH_LIMIT=4',
        );

        self::assertSame(
            'abcd',
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[0].attributes[?(@.key == "test.attribute")].value.stringValue',
            )[0],
        );
    }

    /*
     * =========================================================================
     * Batch LogRecord Processor
     * =========================================================================
     */

    public function testBlrpMaxExportBatchSize(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                for ($i = 1; $i <= 5; ++$i) {
                    $logger->emit(new LogRecord("log-{$i}"));
                }
            },
            'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE=2',
            'OTEL_BLRP_MAX_QUEUE_SIZE=10',
            'OTEL_BLRP_SCHEDULE_DELAY=60000',
        );

        self::assertCount(3, $this->logs);

        self::assertCount(2, $this->logsInExport($this->logs[0]));
        self::assertCount(2, $this->logsInExport($this->logs[1]));
        self::assertCount(1, $this->logsInExport($this->logs[2]));
    }

    public function testBlrpMaxQueueSize(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                for ($i = 1; $i <= 5; ++$i) {
                    $logger->emit(new LogRecord("log-{$i}"));
                }
            },
            'OTEL_BLRP_MAX_QUEUE_SIZE=2',
            'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE=2',
            'OTEL_BLRP_SCHEDULE_DELAY=60000',
        );

        $exported = array_sum(
            array_map(
                fn(string $payload): int => count($this->logsInExport($payload)),
                $this->logs,
            ),
        );

        self::assertLessThanOrEqual(5, $exported);
        self::assertGreaterThan(0, $exported);
    }

    /*
     * Mirror of EnvBatchSpanProcessorTest::testBspDropsSpansWhenQueueIsFull:
     * log records are dropped once the queue is full, and OnEmit SHOULD NOT
     * block (SDK spec), so a full batch is exported asynchronously while new
     * records can still arrive and fill the queue.
     *
     * tbachert/otel-sdk defers exports to its event loop, which exhibits
     * exactly this behavior. The official SDK instead flushes synchronously
     * after every record (autoFlush hardcoded to true), so in a single-threaded
     * scenario the queue never fills and all records are exported — a
     * deviation from the spec's non-blocking requirement that makes the drop
     * path unobservable there.
     */
    public function testBlrpDropsLogRecordsWhenQueueIsFull(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                /*
                 * Four log records are emitted before the first export; with
                 * a queue size of two, the last two must be dropped.
                 */
                for ($i = 0; $i < 4; $i++) {
                    $logger->emit(new LogRecord("overflow-$i"));
                }
            },
            'OTEL_BLRP_MAX_QUEUE_SIZE=2',
            'OTEL_BLRP_MAX_EXPORT_BATCH_SIZE=1',
        );

        self::assertNotEmpty($this->logs);

        /*
         * Only the first two records fit into the queue; the rest are lost.
         */
        $bodies = [];

        foreach ($this->logs as $payload) {
            $bodies = [...$bodies, ...$this->path(
                $payload,
                '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue',
            )];
        }

        self::assertSame(['overflow-0', 'overflow-1'], $bodies);
    }

    public function testBlrpScheduleDelayDoesNotLoseLogsAfterFlush(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('scheduled-log'));
            },
            'OTEL_BLRP_SCHEDULE_DELAY=60000',
        );

        self::assertNotEmpty($this->logs);
    }

    #[Group('async')]
    public function testBlrpExportTimeout(): void {
        /*
         * OTEL_BLRP_EXPORT_TIMEOUT bounds each log export attempt in
         * milliseconds. The /v1/slow route answers 500 ms after the request,
         * beyond the 100 ms timeout, so the export is cancelled and the
         * record dropped.
         *
         * OTEL_PHP_SHUTDOWN_TIMEOUT is a tbachert/otel-sdk vendor variable
         * that bounds that SDK's retry backoff after the timed-out export;
         * open-telemetry/sdk ignores it and completes its shutdown on its
         * own within a second.
         */
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('timeout-log'));
            },
            'OTEL_BLRP_EXPORT_TIMEOUT=100',
            'OTEL_EXPORTER_OTLP_LOGS_ENDPOINT=' . $this->baseUrl . '/v1/slow',
            'OTEL_PHP_SHUTDOWN_TIMEOUT=1000',
        );

        /*
         * The export was attempted... the slow route delays its response
         * beyond the 100 ms timeout...
         */
        self::assertGreaterThanOrEqual(1, $this->slowRequests);

        /*
         * ...so the record was never delivered.
         */
        self::assertSame([], $this->logs);
    }


    /*
     * =========================================================================
     * Log details
     * =========================================================================
     */

    public function testLogSeverityNumberAndTextAreExported(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                $logger->emit((new LogRecord('debug-message'))->setSeverityNumber(5));
                $logger->emit((new LogRecord('warn-message'))
                    ->setSeverityNumber(13)
                    ->setSeverityText('Warning'));
            },
        );

        self::assertNotEmpty($this->logs);

        $records = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords',
        );

        $byBody = [];
        foreach ($records[0] as $record) {
            $byBody[$record['body']['stringValue']] = $record;
        }

        self::assertSame(5, $byBody['debug-message']['severityNumber']);
        self::assertArrayNotHasKey('severityText', $byBody['debug-message']);

        /*
         * The severity text is only exported when explicitly set.
         */
        self::assertSame(13, $byBody['warn-message']['severityNumber']);
        self::assertSame('Warning', $byBody['warn-message']['severityText']);
    }

    public function testLogBodySupportsNonStringTypes(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                $logger->emit(new LogRecord(true));
                $logger->emit(new LogRecord(3.5));
                $logger->emit(new LogRecord(1234567890123));
            },
        );

        self::assertNotEmpty($this->logs);

        $bodies = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body',
        );

        /*
         * Log bodies are exported as OTLP any values: booleans, doubles,
         * and int64 (encoded as a string in JSON).
         */
        self::assertContains(['boolValue' => true], $bodies);
        self::assertContains(['doubleValue' => 3.5], $bodies);
        self::assertContains(['intValue' => '1234567890123'], $bodies);
    }

    public function testLogBodySupportsNestedMaps(): void {
        $this->runOTel(
            static function (): void {
                $logger = Globals::loggerProvider()->getLogger('test');

                $logger->emit(new LogRecord(['nested' => ['key' => 'value'], 'count' => 2]));
            },
        );

        self::assertNotEmpty($this->logs);

        /*
         * Map bodies are exported as OTLP kvlist values, including nested
         * maps; int64 values are encoded as strings in JSON.
         */
        $body = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[*].body',
        )[0];

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
            $body,
        );
    }

    /**
     * A limit of zero means "no items allowed": with
     * OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT=0 no attributes may be exported on
     * the log record.
     */
    public function testZeroAttributeCountLimitDropsAllAttributes(): void
    {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('zero-attrs');

                $record->setAttributes([
                    'a1' => '1',
                    'a2' => '2',
                ]);

                Globals::loggerProvider()
                    ->getLogger('probe')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT=0',
        );

        self::assertCount(
            0,
            $this->path(
                $this->logs[0],
                '$.resourceLogs[*].scopeLogs[*].logRecords[?(@.body.stringValue == "zero-attrs")].attributes[*]',
            ),
        );
    }

    /**
     * OTEL_LOGS_EXPORTER=console must export log records to stdout.
     */
    public function testLogsConsoleExporterWritesToStdout(): void
    {
        $output = $this->runOTel(
            static function (): void {
                $record = new LogRecord('probe-log-body');

                Globals::loggerProvider()
                    ->getLogger('probe')
                    ->emit($record);
            },
            'OTEL_LOGS_EXPORTER=console',
        );

        self::assertStringContainsString('probe-log-body', $output);
    }
}
