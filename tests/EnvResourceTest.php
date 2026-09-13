<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

    #[Group('env'), Group('traces')]
final class EnvResourceTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Resource
     * =========================================================================
     */

    public function testServiceName(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('service-name')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SERVICE_NAME=test-service',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertSame(
            'test-service',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );
    }

    public function testResourceAttributes(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('resource-attributes')
                    ->startSpan();

                $span->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=service.version=1.2.3,deployment.environment.name=test,custom.attribute=value',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        $payload = $this->traces[0];

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

    public function testServiceNameTakesPrecedenceOverResourceAttribute(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('service-name-precedence')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SERVICE_NAME=service-from-name',
            'OTEL_RESOURCE_ATTRIBUTES=service.name=service-from-resource',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertSame(
            'service-from-name',
            $this->resourceAttribute(
                $this->traces[0],
                'service.name',
            ),
        );
    }

    public function testResourceAttributesEnvironmentValuesAreStrings(): void
    {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.resource.attributes')
                    ->startSpan()
                    ->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=deployment.environment=production,test.number=42,test.boolean=true,test.decimal=1.5',
        );

        $payload = $this->traces[0];

        self::assertSame(
            ['production'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "deployment.environment")].value.stringValue',
            ),
        );

        self::assertSame(
            ['42'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "test.number")].value.stringValue',
            ),
        );

        self::assertSame(
            ['true'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "test.boolean")].value.stringValue',
            ),
        );

        self::assertSame(
            ['1.5'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "test.decimal")].value.stringValue',
            ),
        );
    }

    public function testResourceAttributesEnvironmentVariableParsesWhitespace(): void
    {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('environment-test')
                    ->spanBuilder('environment.resource.attributes.whitespace')
                    ->startSpan()
                    ->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=deployment.environment=production, service.version=1.2.3, service.instance.id=test-instance',
        );

        $payload = $this->traces[0];

        self::assertSame(
            ['production'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "deployment.environment")].value.stringValue',
            ),
        );

        self::assertSame(
            ['1.2.3'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "service.version")].value.stringValue',
            ),
        );

        self::assertSame(
            ['test-instance'],
            $this->path(
                $payload,
                '$.resourceSpans[*].resource.attributes[?(@.key == "service.instance.id")].value.stringValue',
            ),
        );
    }

    #[Group('resource')]
    public function testDefaultResourceAttributesIdentifySdkAndComposerPackage(): void {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('default-resource')
                    ->startSpan();

                $span->end();
            },
        );

        self::assertNotEmpty($this->traces);

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

        /*
         * The default resource also carries the SDK version and a unique,
         * randomly generated instance id (spec: service detector).
         */
        self::assertNotSame(
            '',
            $this->resourceAttribute($this->traces[0], 'telemetry.sdk.version'),
        );

        self::assertTrue(
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
                $this->resourceAttribute($this->traces[0], 'service.instance.id'),
            ) === 1,
            'Expected a randomly generated UUID service.instance.id',
        );
    }
}
