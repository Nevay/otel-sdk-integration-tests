<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\TestCase;

final class ConfigLogRecordTest extends TestCase {
    use OTelEndpointTrait;

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
}
