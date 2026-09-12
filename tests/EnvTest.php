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
                    ->emit();
            }
        );

        $this->assertNotEmpty($this->traces);
        $this->assertNotEmpty($this->metrics);
        $this->assertNotEmpty($this->logs);
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