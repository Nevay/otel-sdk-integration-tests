<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('config-file'), Group('traces')]
final class ConfigResourceTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Resource attributes
     * =========================================================================
     */

    public function testResourceAttributes(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: config-test
                - name: service.version
                  value: "1.2.3"
                - name: deployment.environment.name
                  value: test
                - name: custom.attribute
                  value: value

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('resource-attributes')
                    ->startSpan();

                $span->end();
            },
        );

        $payload = $this->traces[0];

        self::assertSame(
            'config-test',
            $this->resourceAttribute($payload, 'service.name'),
        );

        self::assertSame(
            '1.2.3',
            $this->resourceAttribute($payload, 'service.version'),
        );

        self::assertSame(
            'test',
            $this->resourceAttribute(
                $payload,
                'deployment.environment.name',
            ),
        );

        self::assertSame(
            'value',
            $this->resourceAttribute(
                $payload,
                'custom.attribute',
            ),
        );
    }

    public function testResourceAttributesList(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes_list: "service.name=config-test,service.version=1.2.3"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('resource-attributes-list')
                    ->startSpan();

                $span->end();
            },
        );

        $payload = $this->traces[0];

        self::assertSame(
            'config-test',
            $this->resourceAttribute($payload, 'service.name'),
        );

        self::assertSame(
            '1.2.3',
            $this->resourceAttribute($payload, 'service.version'),
        );
    }

    public function testExplicitResourceAttributesOverrideAttributesList(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes_list: "service.name=from-list,service.version=1.0"
              attributes:
                - name: service.name
                  value: from-attributes
                - name: service.version
                  value: "2.0"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('config-test')
                    ->spanBuilder('resource-precedence')
                    ->startSpan();

                $span->end();
            },
        );

        $payload = $this->traces[0];

        self::assertSame(
            'from-attributes',
            $this->resourceAttribute($payload, 'service.name'),
        );

        self::assertSame(
            '2.0',
            $this->resourceAttribute($payload, 'service.version'),
        );
    }

    public function testResourceAttributesSupportNonStringValues(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: service.name
                  value: non-string-test
                - name: limits.concurrency
                  value: 42
                - name: feature.enabled
                  value: true
                - name: ratio.keepalive
                  value: 0.5

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('non-string-test')
                    ->spanBuilder('non-string-values')
                    ->startSpan();

                $span->end();
            },
        );

        $payload = $this->traces[0];

        /*
         * OTLP JSON encodes int64 values as strings.
         */
        self::assertSame(
            '42',
            $this->resourceAttribute($payload, 'limits.concurrency'),
        );

        self::assertTrue(
            $this->resourceAttribute($payload, 'feature.enabled'),
        );

        self::assertSame(
            0.5,
            $this->resourceAttribute($payload, 'ratio.keepalive'),
        );
    }

    public function testResourceSchemaUrlMatchingDetectedResourcesIsExported(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              schema_url: https://opentelemetry.io/schemas/1.43.0
              attributes:
                - name: service.name
                  value: schema-url-test

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('schema-url-test')
                    ->spanBuilder('schema-url-match')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The configured schema URL matches the one used by the detected
         * resources, so the merged resource keeps it in the export.
         */
        self::assertSame(
            ['https://opentelemetry.io/schemas/1.43.0'],
            $this->path($this->traces[0], '$.resourceSpans[*].schemaUrl'),
        );
    }

    public function testResourceSchemaUrlConflictingWithDetectedResourcesIsDropped(): void
    {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              schema_url: https://example.com/schemas/custom-1.0
              attributes:
                - name: service.name
                  value: schema-url-test

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('schema-url-test')
                    ->spanBuilder('schema-url-conflict')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The configured schema URL conflicts with the one used by the
         * detected resources; merging resources with different schema
         * URLs drops the schema URL from the export.
         */
        self::assertSame(
            [],
            $this->path($this->traces[0], '$.resourceSpans[*].schemaUrl'),
        );
    }

    #[Group('resource')]
    public function testServiceNameFallsBackToSpecDefaultWhenUnset(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('default-service-name')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * Without an explicit service name, the config-file based resource
         * falls back to the spec default.
         */
        self::assertSame(
            'unknown_service:php',
            $this->resourceAttribute($this->traces[0], 'service.name'),
        );
    }

    #[Group('resource')]
    public function testResourceDetectorExcludesSelectedAttributes(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                attributes:
                  excluded:
                    - process.pid
                detectors:
                  - process:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-exclude')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertNotContains('process.pid', $keys);
        self::assertContains('process.runtime.name', $keys);
    }

    #[Group('resource')]
    public function testResourceDetectorIncludesOnlySelectedAttributes(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                attributes:
                  included:
                    - process.runtime.*
                detectors:
                  - process:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-include')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertNotContains('process.pid', $keys);
        self::assertContains('process.runtime.name', $keys);
    }

    #[Group('resource')]
    public function testContainerDetectorReportsContainerId(): void {
        /*
         * The suite runs inside a Docker container, so the container
         * detector finds the 64-character container id in /proc/self/
         * mountinfo.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                detectors:
                  - container:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-container')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        $containerId = $this->resourceAttribute($this->traces[0], 'container.id');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $containerId);
    }

    #[Group('resource')]
    public function testHostDetectorPopulatesHostAndOsAttributes(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                detectors:
                  - host:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-host')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * The host detector populates host.* and os.* attributes; detectors
         * that are not listed do not run.
         */
        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertContains('host.id', $keys);
        self::assertContains('os.type', $keys);
        self::assertNotContains('process.pid', $keys);
    }

    #[Group('resource')]
    public function testServiceDetectorReadsServiceNameFromEnvironmentVariable(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                detectors:
                  - service:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-service')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SERVICE_NAME=config-detected-service',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The service detector populates service.name from the environment
         * variable and a unique service.instance.id.
         */
        $payload = $this->traces[0];

        self::assertSame('config-detected-service', $this->resourceAttribute($payload, 'service.name'));
        self::assertNotEmpty($this->resourceAttribute($payload, 'service.instance.id'));
    }

    #[Group('resource')]
    public function testResourceDetectionIsDisabledWithoutDetectionNode(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              attributes:
                - name: custom.attribute
                  value: value

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-disabled')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * Per the data model, omitting the detection node disables resource
         * detection: only explicitly configured attributes (plus the SDK's
         * own defaults) are present.
         */
        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertContains('custom.attribute', $keys);
        self::assertNotContains('host.id', $keys);
        self::assertNotContains('process.pid', $keys);
    }

    #[Group('resource')]
    public function testMultipleResourceDetectorsAreCombined(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                detectors:
                  - host:
                  - process:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-multi')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

        /*
         * All listed detectors run and their attributes are merged into the
         * resource.
         */
        $keys = $this->path(
            $this->traces[0],
            '$.resourceSpans[*].resource.attributes[*].key',
        );

        self::assertContains('host.id', $keys);
        self::assertContains('process.pid', $keys);
    }

    #[Group('resource'), Group('entities')]
    public function testEnvDetectorParsesEntitiesFromEnvironmentVariable(): void {
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            resource:
              detection/development:
                detectors:
                  - env:

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('detector-env-entities')
                    ->startSpan();

                $span->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=abc}[custom.desc=v1]@https://opentelemetry.io/schemas/1.21.0',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * The entity's identifying and descriptive attributes are merged
         * into the resource attributes, and the entity is exported as a
         * reference carrying its own schema URL.
         */
        self::assertSame('abc', $this->resourceAttribute($payload, 'custom.id'));
        self::assertSame('v1', $this->resourceAttribute($payload, 'custom.desc'));

        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
        self::assertSame(['custom.id'], $refs[0]['idKeys']);
        self::assertSame(['custom.desc'], $refs[0]['descriptionKeys']);
        self::assertSame('https://opentelemetry.io/schemas/1.21.0', $refs[0]['schemaUrl']);
    }
}
