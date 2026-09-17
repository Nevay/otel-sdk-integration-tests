<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('env')]
final class EnvEndToEndTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * End-to-end
     * =========================================================================
     *
     * Scenarios that span multiple child processes or export paths,
     * mirroring how the SDK is used in production.
     */

    #[Group('traces')]
    public function testTraceContextPropagatesAcrossServices(): void {
        /*
         * Service A creates a root span, then injects the trace context
         * and baggage into a carrier...
         */
        $carrierJson = $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('service-a')
                    ->spanBuilder('service-a.request')
                    ->startSpan();

                $scope = $span->activate();

                $context = Baggage::fromContext(Context::getCurrent())
                    ->toBuilder()
                    ->set('request.id', 'req-123')
                    ->build()
                    ->storeInContext(Context::getCurrent());

                $carrier = [];
                Globals::propagator()->inject($carrier, null, $context);

                echo json_encode($carrier, JSON_THROW_ON_ERROR);

                $scope->detach();
                $span->end();
            },
            'OTEL_PROPAGATORS=tracecontext,baggage',
        );

        $carrier = json_decode($carrierJson, true, 512, JSON_THROW_ON_ERROR);

        /*
         * ...and service B extracts it and continues the trace.
         */
        $baggageValue = $this->runOTel(
            static function () use ($carrier): void {
                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = Globals::tracerProvider()
                    ->getTracer('service-b')
                    ->spanBuilder('service-b.work')
                    ->startSpan();
                $child->end();

                $scope->detach();

                echo (string) Baggage::fromContext($context)->getValue('request.id');
            },
            'OTEL_PROPAGATORS=tracecontext,baggage',
        );

        /*
         * The baggage entry survives the round trip...
         */
        self::assertSame('req-123', $baggageValue);

        /*
         * ...and both services' spans are part of one trace: service B's
         * span is a child of service A's span.
         */
        $spans = $this->path(
            $this->combinedTracePayload(),
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );

        $byName = array_column($spans, null, 'name');

        self::assertArrayHasKey('service-a.request', $byName);
        self::assertArrayHasKey('service-b.work', $byName);

        self::assertSame(
            $byName['service-a.request']['traceId'],
            $byName['service-b.work']['traceId'],
        );

        self::assertSame(
            $byName['service-a.request']['spanId'],
            $byName['service-b.work']['parentSpanId'],
        );
    }

    #[Group('traces'), Group('config-file')]
    public function testTraceContextPropagatesBetweenEnvAndConfigFileModes(): void {
        /*
         * Service A is configured via environment variables (the default
         * tracecontext propagator)...
         */
        $carrierJson = $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('service-a')
                    ->spanBuilder('interop-a')
                    ->startSpan();

                $scope = $span->activate();

                $carrier = [];
                Globals::propagator()->inject($carrier, null, Context::getCurrent());

                echo json_encode($carrier, JSON_THROW_ON_ERROR);

                $scope->detach();
                $span->end();
            },
        );

        $carrier = json_decode($carrierJson, true, 512, JSON_THROW_ON_ERROR);

        /*
         * ...while service B is configured via a config file. The two
         * configuration modes must interoperate.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        encoding: json
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function () use ($carrier): void {
                $context = Globals::propagator()->extract($carrier);
                $scope = $context->activate();

                $child = Globals::tracerProvider()
                    ->getTracer('service-b')
                    ->spanBuilder('interop-b')
                    ->startSpan();
                $child->end();

                $scope->detach();
            },
        );

        $spans = $this->path(
            $this->combinedTracePayload(),
            '$.resourceSpans[*].scopeSpans[*].spans[*]',
        );

        $byName = array_column($spans, null, 'name');

        self::assertArrayHasKey('interop-a', $byName);
        self::assertArrayHasKey('interop-b', $byName);

        self::assertSame(
            $byName['interop-a']['traceId'],
            $byName['interop-b']['traceId'],
        );

        self::assertSame(
            $byName['interop-a']['spanId'],
            $byName['interop-b']['parentSpanId'],
        );
    }











}
