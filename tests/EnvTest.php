<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Nevay\OTelTest\OTelEndpointTrait;
use OpenTelemetry\API\Globals;
use PHPUnit\Framework\TestCase;

final class EnvTest extends TestCase {
    use OTelEndpointTrait;

    public function testEnvGeneratesTelemetryData(): void {
        $this->runOTel(
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $meter = Globals::meterProvider()->getMeter('test');
                $logger = Globals::loggerProvider()->getLogger('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
                $meter
                    ->createCounter('c')
                    ->add(1);
                $logger
                    ->logRecordBuilder()
                    ->setBody('smoke-log')
                    ->emit();
            }
        );

        $this->assertNotEmpty($this->traces);
        $this->assertNotEmpty($this->metrics);
        $this->assertNotEmpty($this->logs);

        /*
         * The log record body must round-trip through the exporter.
         */
        self::assertSame(
            ['smoke-log'],
            $this->path($this->logs[0], '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue'),
        );

        /*
         * The default resource identifies the SDK...
         */
        self::assertSame(
            'php',
            $this->resourceAttribute($this->traces[0], 'telemetry.sdk.language'),
        );

        self::assertSame(
            'tbachert/otel-sdk',
            $this->resourceAttribute($this->traces[0], 'telemetry.sdk.name'),
        );

        /*
         * ...and derives the service name from the root composer package.
         */
        self::assertSame(
            'tbachert/otel-test',
            $this->resourceAttribute($this->traces[0], 'service.name'),
        );
    }

    public function testSdkCanBeDisabled(): void {
        $this->runOTel(
            static function(): void {
                $tracer = Globals::tracerProvider()->getTracer('test');
                $meter = Globals::meterProvider()->getMeter('test');
                $logger = Globals::loggerProvider()->getLogger('test');

                $tracer
                    ->spanBuilder('test')
                    ->startSpan()
                    ->end();
                $meter
                    ->createCounter('c')
                    ->add(1);
                $logger
                    ->logRecordBuilder()
                    ->emit();
            },
            OTEL_SDK_DISABLED: 'true',
        );

        $this->assertEmpty($this->traces);
        $this->assertEmpty($this->metrics);
        $this->assertEmpty($this->logs);
    }
}