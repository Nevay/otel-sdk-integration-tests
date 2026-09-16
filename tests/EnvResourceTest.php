<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Composer\InstalledVersions;
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

        /*
         * ...with a required, stable identifier: the reference implementation
         * MUST use the reserved name "opentelemetry", vendor SDKs MUST use a
         * custom identifier instead.
         */
        $sdkName = $this->resourceAttribute($this->traces[0], 'telemetry.sdk.name');

        if (InstalledVersions::isInstalled('tbachert/otel-sdk')) {
            self::assertSame('tbachert/otel-sdk', $sdkName);
        } else {
            self::assertSame('opentelemetry', $sdkName);
        }

        /*
         * ...and sets a reasonable service name. The specification only says
         * that OTEL_SERVICE_NAME SHOULD default to a reasonable application or
         * system name, leaving the exact derivation to the SDK: deriving it
         * from the root composer package (tbachert/otel-sdk) and falling back
         * to "unknown_service:<language>" (open-telemetry/sdk, per semantic
         * conventions) are both acceptable.
         */
        self::assertContains(
            $this->resourceAttribute($this->traces[0], 'service.name'),
            [
                InstalledVersions::getRootPackage()['name'],
                'unknown_service:php',
            ],
        );

        /*
         * The default resource also carries the SDK version. (Per semantic
         * conventions, only service.name and the telemetry.sdk group are
         * mandatory defaults; for example service.instance.id is set by the
         * service detector at SHOULD level and is not asserted here.)
         */
        self::assertNotSame(
            '',
            $this->resourceAttribute($this->traces[0], 'telemetry.sdk.version'),
        );
    }

    /*
     * =========================================================================
     * Entities (OTEL_ENTITIES)
     * =========================================================================
     */

    #[Group('resource'), Group('entities')]
    public function testEntitiesFromEnvironmentVariable(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=abc,custom.other=xyz}[custom.desc=v1]@https://opentelemetry.io/schemas/1.21.0',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * The entity's identifying and descriptive attributes are merged
         * into the resource attributes.
         */
        self::assertSame('abc', $this->resourceAttribute($payload, 'custom.id'));
        self::assertSame('xyz', $this->resourceAttribute($payload, 'custom.other'));
        self::assertSame('v1', $this->resourceAttribute($payload, 'custom.desc'));

        /*
         * The entity is exported as a reference: type plus keys into the
         * resource attributes.
         */
        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
        self::assertSame(['custom.id', 'custom.other'], $refs[0]['idKeys']);
        self::assertSame(['custom.desc'], $refs[0]['descriptionKeys']);
    }

    #[Group('resource'), Group('entities')]
    public function testEntityDuplicateUsesLastOccurrence(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities-duplicate')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=abc}[custom.desc=v1]@https://opentelemetry.io/schemas/1.21.0;myapp{custom.id=abc}[custom.desc=v2]@https://opentelemetry.io/schemas/1.21.0',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * Duplicate entities of the same type with identical identifying
         * attributes: the last occurrence wins.
         */
        self::assertSame('v2', $this->resourceAttribute($payload, 'custom.desc'));

        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
    }

    #[Group('resource'), Group('entities')]
    public function testEntityConflictingIdentityPreservesOnlyLast(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities-conflict')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=first};myapp{custom.id=second}',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * Entities of the same type with conflicting values for an
         * identifying attribute: only the last entity is preserved.
         */
        self::assertSame('second', $this->resourceAttribute($payload, 'custom.id'));

        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
    }

    #[Group('resource'), Group('entities')]
    public function testEntityMalformedDefinitionIsSkipped(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities-malformed')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=malformed-without-braces;myapp{custom.id=abc}@https://opentelemetry.io/schemas/1.21.0',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * Malformed entity definitions are skipped while the valid parts are
         * still processed.
         */
        self::assertSame('abc', $this->resourceAttribute($payload, 'custom.id'));

        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
    }

    #[Group('resource'), Group('entities')]
    public function testEntityWithoutSchemaUrlIsExported(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities-no-url')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=abc}[custom.desc=v1]',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * Entities without a schema URL (the common case) are exported as
         * references with the default (omitted) schema URL.
         */
        self::assertSame('abc', $this->resourceAttribute($payload, 'custom.id'));

        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
        self::assertSame(['custom.id'], $refs[0]['idKeys']);
        self::assertSame(['custom.desc'], $refs[0]['descriptionKeys']);
        self::assertArrayNotHasKey('schemaUrl', $refs[0]);
    }

    #[Group('resource'), Group('entities')]
    public function testEntityInvalidSchemaUrlIsIgnored(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities-invalid-url')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=abc}@not-a-valid-url',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        $payload = $this->traces[0];

        /*
         * An invalid schema URL is ignored while the entity itself is still
         * processed.
         */
        self::assertSame('abc', $this->resourceAttribute($payload, 'custom.id'));

        $refs = array_values(array_filter(
            $this->resourceEntityRefs($payload),
            static fn(array $ref): bool => ($ref['type'] ?? null) === 'myapp',
        ));

        self::assertCount(1, $refs);
        self::assertArrayNotHasKey('schemaUrl', $refs[0]);
    }

    #[Group('resource'), Group('entities')]
    public function testEntityAttributeValuesArePercentDecoded(): void {
        $this->runOTel(
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('test')
                    ->spanBuilder('entities-percent-decoded')
                    ->startSpan()
                    ->end();
            },
            'OTEL_ENTITIES=myapp{custom.id=my%20value,custom.other=x%2Cy}[custom.desc=a%3Db%5Bc%5D]',
            'OTEL_TRACES_SAMPLER=always_on',
        );

        self::assertNotEmpty($this->traces);

        /*
         * Attribute values are percent-decoded per the W3C Baggage
         * specification.
         */
        self::assertSame('my value', $this->resourceAttribute($this->traces[0], 'custom.id'));
        self::assertSame('x,y', $this->resourceAttribute($this->traces[0], 'custom.other'));
        self::assertSame('a=b[c]', $this->resourceAttribute($this->traces[0], 'custom.desc'));
    }

    /**
     * An empty OTEL_SERVICE_NAME must be treated as unset, so the SDK's
     * default service name derivation applies (the composer root package
     * for tbachert/otel-sdk, unknown_service:<language> for the reference
     * SDK).
     */
    public function testEmptyServiceNameEnvironmentVariableFallsBackToDefault(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_SERVICE_NAME=',
        );

        self::assertContains(
            $this->resourceAttribute($this->traces[0], 'service.name'),
            [
                InstalledVersions::getRootPackage()['name'],
                'unknown_service:php',
            ],
        );
    }

    /**
     * OTEL_RESOURCE_ATTRIBUTES: "," and "=" in keys and values MUST be
     * percent encoded, so a%3Db=c%2Cd is the pair with key "a=b" and value
     * "c,d".
     * 
     * Both SDKs currently only percent-decode the value, not the key — as do
     * the Go, Java, Python and .NET SDKs; only the JS SDK decodes keys.
     */
    public function testResourceAttributesDecodePercentEncodedKeys(): void
    {
        $this->runOTel(
            static function (): void {
                $span = Globals::tracerProvider()
                    ->getTracer('probe')
                    ->spanBuilder('probe')
                    ->startSpan();

                $span->end();
            },
            'OTEL_RESOURCE_ATTRIBUTES=a%3Db=c%2Cd',
        );

        self::assertSame(
            'c,d',
            $this->resourceAttribute($this->traces[0], 'a=b'),
        );
    }
}
