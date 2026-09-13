<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\Context\Context;
use PHPUnit\Framework\TestCase;

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

    public function testOtlpHttpProtobufProtocolExportsAllSignals(): void {
        /*
         * http/protobuf is the SDK's default wire format; the test harness
         * otherwise forces http/json. All three signals must export over
         * protobuf.
         */
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('proto.span')
                    ->startSpan();

                $span->setAttribute('transport', 'protobuf');
                $span->end();

                Globals::meterProvider()
                    ->getMeter('test')
                    ->createCounter('proto.counter')
                    ->add(7);

                Globals::loggerProvider()
                    ->getLogger('test')
                    ->logRecordBuilder()
                    ->setBody('proto-log')
                    ->emit();
            },
            'OTEL_EXPORTER_OTLP_TRACES_PROTOCOL=http/protobuf',
            'OTEL_EXPORTER_OTLP_METRICS_PROTOCOL=http/protobuf',
            'OTEL_EXPORTER_OTLP_LOGS_PROTOCOL=http/protobuf',
        );

        self::assertNotEmpty($this->traces);
        self::assertNotEmpty($this->metrics);
        self::assertNotEmpty($this->logs);

        $span = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].scopeSpans[*].spans[?(@.name == "proto.span")]',
        );

        self::assertCount(1, $span);
        self::assertSame(
            ['key' => 'transport', 'value' => ['stringValue' => 'protobuf']],
            $span[0]['attributes'][0],
        );

        /*
         * The collector re-serializes protobuf messages to JSON, so byte
         * fields appear as base64 of the raw 16/8 byte ids.
         */
        self::assertSame(
            16,
            strlen(base64_decode($span[0]['traceId'])),
        );

        self::assertSame(
            8,
            strlen(base64_decode($span[0]['spanId'])),
        );

        self::assertSame(
            ['7'],
            $this->path(
                $this->metrics[0],
                '$.resourceMetrics[*].scopeMetrics[*].metrics[?(@.name == "proto.counter")].sum.dataPoints[*].asInt',
            ),
        );

        self::assertSame(
            ['proto-log'],
            $this->path($this->logs[0], '$.resourceLogs[*].scopeLogs[*].logRecords[*].body.stringValue'),
        );
    }

    public function testOtlpRequestHeadersAreSentToCollector(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('headers-span')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_HEADERS=auth=secret-token,x-custom=v1',
        );

        self::assertNotEmpty($this->traces);

        $headers = array_change_key_case($this->requestHeaders[0]);

        /*
         * Header values are multi-value lists.
         */
        self::assertSame(['secret-token'], $headers['auth']);
        self::assertSame(['v1'], $headers['x-custom']);
    }

    public function testOtlpHttpGzipCompressionIsApplied(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('gzip-span')
                    ->startSpan();

                $span->end();
            },
            'OTEL_EXPORTER_OTLP_COMPRESSION=gzip',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The request body is gzip-compressed and marked as such; the fake
         * collector decodes it before parsing.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['gzip'], $headers['content-encoding']);

        self::assertContains('gzip-span', $this->spanNames($this->traces[0]));
    }
}
