<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('env'), Group('logs')]
final class EnvLogRecordTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * LogRecord limits
     * =========================================================================
     */

    public function testLogRecordAttributeCountLimitEnvironmentVariable(): void
    {
        $this->runOTel(
            static function (): void {
                $record = new LogRecord('environment.log.attribute.count');

                $record->setAttributes([
                    'test.attribute.1' => 'one',
                    'test.attribute.2' => 'two',
                    'test.attribute.3' => 'three',
                    'test.attribute.4' => 'four',
                ]);

                Globals::loggerProvider()
                    ->getLogger('environment-test')
                    ->emit($record);
            },
            'OTEL_LOGRECORD_ATTRIBUTE_COUNT_LIMIT=2',
        );

        $attributes = $this->path(
            $this->logs[0],
            '$.resourceLogs[*].scopeLogs[*].logRecords[?(@.body.stringValue == "environment.log.attribute.count")].attributes[*]',
        );

        self::assertCount(2, $attributes);
    }

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

    public function testBlrpExportTimeout(): void {
        $this->runOTel(
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('test')
                    ->emit(new LogRecord('timeout-log'));
            },
            'OTEL_BLRP_EXPORT_TIMEOUT=100',
        );

        self::assertIsArray($this->logs);
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
}
