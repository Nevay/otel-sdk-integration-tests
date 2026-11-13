<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file')]
final class ConfigComponentListsTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Required component lists
     * =========================================================================
     *
     * Basis:
     *   specification/configuration/sdk.md#parse - "Parse MUST differentiate
     *   between properties that are missing and properties that are present
     *   but null";
     *   specification/configuration/sdk.md#create - "If a property is required,
     *   and not present, Create MUST return an error."
     *
     * In the configuration model (v1.0.0, v1.1.0, v1.2.0 and main alike)
     * TracerProvider.processors, MeterProvider.readers and
     * LoggerProvider.processors are "type: array" with "minItems: 1" and are
     * listed in the type's "required" array, described as "Property is
     * required and must be non-null"; the model defines neither a null
     * behaviour nor a default for them. MeterProvider.views is "type: array"
     * with "minItems: 1" as well, but it is not required and it does declare
     * "defaultBehavior: no views are registered" (no nullBehavior).
     *
     * So every provider block that is present must carry a present, non-null
     * and non-empty component list, and each of those tests expects the SDK
     * initialization to fail and nothing to be exported. The optional "views"
     * list is held to the same value rule: "type: array" with "minItems: 1"
     * accepts neither an empty list nor a null, because the data model encodes
     * nullability in the type union and "null" is not part of it.
     *
     * Every fixture keeps a valid sibling node inside the provider block
     * (sampler / exemplar_filter / limits), so the block itself is present and
     * only the list under test is missing, null or empty. All fixtures use
     * file_format "1.0", the oldest format both SDKs implement, so no SDK
     * emits a warning about a newer configuration model.
     *
     * The assertions are not vacuous: for every family there is an existing
     * test in this repository that exports telemetry through the same provider
     * with a valid, non-empty list - named in each test below.
     */

    /*
     * =========================================================================
     * tracer_provider.processors
     * =========================================================================
     */

    #[Group('traces')]
    public function testTracerProviderWithoutProcessorsKeyFailsInitialization(): void
    {
        /*
         * A present tracer_provider block whose required "processors" key is
         * missing must fail Create.
         *
         * The valid shape is proven by
         * tests/ConfigBasicTest.php::testConfigFileConfiguresTracerProvider:
         * the same fixture with a non-empty processors list configures a
         * pipeline that exports spans.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            tracer_provider:
              sampler:
                always_on:
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('component-list-test')
                    ->spanBuilder('processors-key-missing')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertSame([], $this->traces);
    }

    #[Group('traces')]
    public function testTracerProviderWithNullProcessorsFailsInitialization(): void
    {
        /*
         * "processors: ~" is a property that is present but null, which Parse
         * must not treat as absent; the required list is not present, so
         * Create must return an error.
         *
         * Valid shape: tests/ConfigBasicTest.php
         * ::testConfigFileConfiguresTracerProvider.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            tracer_provider:
              sampler:
                always_on:
              processors: ~
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('component-list-test')
                    ->spanBuilder('processors-null')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertSame([], $this->traces);
    }

    #[Group('traces')]
    public function testTracerProviderWithEmptyProcessorsFailsInitialization(): void
    {
        /*
         * "processors: []" violates "minItems: 1" on a required list.
         *
         * Valid shape: tests/ConfigBasicTest.php
         * ::testConfigFileConfiguresTracerProvider.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            tracer_provider:
              sampler:
                always_on:
              processors: []
            YAML,
            static function (): void {
                Globals::tracerProvider()
                    ->getTracer('component-list-test')
                    ->spanBuilder('processors-empty')
                    ->startSpan()
                    ->end();
            },
        );

        self::assertSame([], $this->traces);
    }

    /*
     * =========================================================================
     * meter_provider.readers
     * =========================================================================
     */

    #[Group('metrics')]
    public function testMeterProviderWithoutReadersKeyFailsInitialization(): void
    {
        /*
         * A present meter_provider block (here with a valid exemplar_filter
         * sibling) whose required "readers" key is missing must fail Create.
         *
         * The valid shape is proven by
         * tests/ConfigMetricReaderTest.php::testPeriodicMetricReader: the same
         * provider with a non-empty readers list exports a counter.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            meter_provider:
              exemplar_filter: trace_based
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('component-list-test')
                    ->createCounter('readers.key.missing')
                    ->add(1);
            },
        );

        self::assertSame([], $this->metrics);
    }

    #[Group('metrics')]
    public function testMeterProviderWithNullReadersFailsInitialization(): void
    {
        /*
         * "readers: ~" is present but null; a required list must not be null.
         *
         * Valid shape: tests/ConfigMetricReaderTest.php
         * ::testPeriodicMetricReader.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            meter_provider:
              exemplar_filter: trace_based
              readers: ~
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('component-list-test')
                    ->createCounter('readers.null')
                    ->add(1);
            },
        );

        self::assertSame([], $this->metrics);
    }

    #[Group('metrics')]
    public function testMeterProviderWithEmptyReadersFailsInitialization(): void
    {
        /*
         * "readers: []" violates "minItems: 1" on a required list.
         *
         * Valid shape: tests/ConfigMetricReaderTest.php
         * ::testPeriodicMetricReader.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            meter_provider:
              exemplar_filter: trace_based
              readers: []
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('component-list-test')
                    ->createCounter('readers.empty')
                    ->add(1);
            },
        );

        self::assertSame([], $this->metrics);
    }

    /*
     * =========================================================================
     * logger_provider.processors
     * =========================================================================
     */

    #[Group('logs')]
    public function testLoggerProviderWithoutProcessorsKeyFailsInitialization(): void
    {
        /*
         * A present logger_provider block (here with a valid limits sibling)
         * whose required "processors" key is missing must fail Create.
         *
         * The valid shape is proven by
         * tests/ConfigLogRecordTest.php
         * ::testLogRecordsExportedWithSeverityAndTypedBodies: the same
         * provider with a non-empty processors list exports log records.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            logger_provider:
              limits:
                attribute_count_limit: 32
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('component-list-test')
                    ->emit(new LogRecord('processors-key-missing'));
            },
        );

        self::assertSame([], $this->logs);
    }

    #[Group('logs')]
    public function testLoggerProviderWithNullProcessorsFailsInitialization(): void
    {
        /*
         * "processors: ~" is present but null; a required list must not be
         * null.
         *
         * Valid shape: tests/ConfigLogRecordTest.php
         * ::testLogRecordsExportedWithSeverityAndTypedBodies.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            logger_provider:
              limits:
                attribute_count_limit: 32
              processors: ~
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('component-list-test')
                    ->emit(new LogRecord('processors-null'));
            },
        );

        self::assertSame([], $this->logs);
    }

    #[Group('logs')]
    public function testLoggerProviderWithEmptyProcessorsFailsInitialization(): void
    {
        /*
         * "processors: []" violates "minItems: 1" on a required list.
         *
         * Valid shape: tests/ConfigLogRecordTest.php
         * ::testLogRecordsExportedWithSeverityAndTypedBodies.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            logger_provider:
              limits:
                attribute_count_limit: 32
              processors: []
            YAML,
            static function (): void {
                Globals::loggerProvider()
                    ->getLogger('component-list-test')
                    ->emit(new LogRecord('processors-empty'));
            },
        );

        self::assertSame([], $this->logs);
    }

    /*
     * =========================================================================
     * meter_provider.views (optional; neither null nor an empty list is valid)
     * =========================================================================
     */

    #[Group('metrics')]
    public function testMeterProviderWithNullViewsIsRejected(): void
    {
        /*
         * "views: ~" is a property that is present but null, and null is not a
         * value of the declared type here. The data model encodes nullability
         * in the type union: ExemplarFilter in the very same schema file is
         * "type: [string, \"null\"]", every genuinely nullable property spells
         * it out, and opentelemetry-configuration' CONTRIBUTING.md states the
         * negative case directly - "Setting `exporter` to `null` or any
         * non-object value is invalid", "Setting `limits` to `null` is
         * invalid". views is "type: array" with "minItems: 1" and no "null"
         * (v1.0.0 and main alike), is not in the type's "required" list (only
         * "readers" is) and declares no nullBehavior. A null is therefore
         * rejected like the too-short list below, and not treated as absent.
         * Nulls are not hypothetical input: substituting an undefined
         * environment variable produces one (specification/configuration/
         * data-model.md), which is why env-substitutable properties declare
         * the union - arrays are not substitution candidates.
         *
         * This is our reading of the data model's nullability encoding, not a
         * quoted MUST. The permissive reading exists and is the one
         * open-telemetry/sdk implements: "Parse MUST differentiate between
         * properties that are missing and properties that are present but
         * null", and "If a property is present and the value is null, Create
         * MUST use the nullBehavior, or defaultBehavior if nullBehavior is not
         * set" - views does define defaultBehavior "no views are registered",
         * so there a null behaves like an omitted key and this test fails.
         *
         * Not vacuous: tests/ConfigViewsTest.php
         * ::testViewRenamesMetricAndChangesDescription (same reader setup,
         * non-empty views list) shows this fixture does export telemetry when
         * the configuration is valid, and
         * testMeterProviderWithEmptyViewsFailsInitialization below shows both
         * SDKs reject the explicit empty list. This test fails on an SDK that
         * accepts the null form.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              views: ~
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('component-list-test')
                    ->createCounter('views.null')
                    ->add(1);
            },
        );

        self::assertSame([], $this->metrics);
    }

    #[Group('metrics')]
    public function testMeterProviderWithEmptyViewsFailsInitialization(): void
    {
        /*
         * An explicit "views: []" violates "minItems: 1": a value of the
         * declared type that is simply too short. The null form above is
         * rejected for the same reason - null is not a value of that type at
         * all - not because a default behaviour was consulted.
         *
         * Valid shape: tests/ConfigViewsTest.php
         * ::testViewRenamesMetricAndChangesDescription.
         */
        $this->runOTelConfigExpectingInitError(
            <<<'YAML'
            file_format: "1.0"

            meter_provider:
              readers:
                - periodic:
                    exporter:
                      otlp_http:
                        endpoint: ${OTEL_EXPORTER_OTLP_METRICS_ENDPOINT}
              views: []
            YAML,
            static function (): void {
                Globals::meterProvider()
                    ->getMeter('component-list-test')
                    ->createCounter('views.empty')
                    ->add(1);
            },
        );

        self::assertSame([], $this->metrics);
    }
}
