<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Globals;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Environment variable substitution in configuration files, as specified by
 * the SDK configuration data model. These tests exercise the substitution
 * machinery itself; a traces otlp_http exporter is used only as a carrier,
 * with its headers as the observation point: they are arbitrary strings
 * that arrive at the collector verbatim, so any substitution behavior is
 * visible in the captured request headers.
 */
#[Group('spec')]
#[Group('config-file')]
final class ConfigSubstitutionTest extends TestCase {
    use OTelEndpointTrait;

    /**
     * Emits a single span through an otlp_http exporter carrying the given
     * header values, and returns the YAML document.
     *
     * @param list<string> $values
     */
    private function configWithHeaderValues(array $values): string {
        $headers = "            headers:\n";
        foreach ($values as $i => $value) {
            $headers .= sprintf(
                "              - name: subst-%d\n                value: \"%s\"\n",
                $i,
                str_replace('"', '\"', $value),
            );
        }

        return "file_format: \"1.2\"\n"
            . "\n"
            . "tracer_provider:\n"
            . "  processors:\n"
            . "    - batch:\n"
            . "        exporter:\n"
            . "          otlp_http:\n"
            . "            endpoint: \${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}\n"
            . $headers;
    }

    public static function emitSpan(): void {
        Globals::tracerProvider()->getTracer('config-test')
            ->spanBuilder('substitution')
            ->startSpan()
            ->end();
    }

    public function testPlainAndEnvPrefixedReferencesResolveToTheSameValue(): void {
        $this->runOTelConfig(
            $this->configWithHeaderValues(['${SUBST_GREETING}', '${env:SUBST_GREETING}']),
            self::emitSpan(...),
            'SUBST_GREETING=hello',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The env: prefix is explicit and optional; both forms resolve the
         * same variable.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['hello'], $headers['subst-0']);
        self::assertSame(['hello'], $headers['subst-1']);
    }

    public function testDefaultValueIsUsedOnlyWhenVariableIsUnset(): void {
        $this->runOTelConfig(
            $this->configWithHeaderValues([
                '${SUBST_SET:-fallback}',
                '${SUBST_UNSET_VARIABLE:-fallback}',
            ]),
            self::emitSpan(...),
            'SUBST_SET=actual',
        );

        self::assertNotEmpty($this->traces);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['actual'], $headers['subst-0']);
        self::assertSame(['fallback'], $headers['subst-1']);
    }

    public function testDoubleDollarEscapesSubstitution(): void {
        $this->runOTelConfig(
            $this->configWithHeaderValues([
                '$${SUBST_SET}',
                '$$${SUBST_SET}',
                'a $$ b',
            ]),
            self::emitSpan(...),
            'SUBST_SET=actual',
        );

        self::assertNotEmpty($this->traces);

        /*
         * $$ is an escaped dollar sign: the first form stays a literal
         * reference, the second yields a literal dollar followed by the
         * resolved value, and the third escapes a bare dollar.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['${SUBST_SET}'], $headers['subst-0']);
        self::assertSame(['$actual'], $headers['subst-1']);
        self::assertSame(['a $ b'], $headers['subst-2']);
    }

    public function testInvalidSubstitutionFailsInitialization(): void {
        $template = <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        headers:
                          - name: invalid
                            value: "__REF__"
        YAML;

        /*
         * A malformed reference is a configuration error: the SDK reports
         * it during initialization and degrades to a no-op.
         */
        foreach (['${1KEY}', '${bogus:SUBST_SET}', '${SUBST_SET:?err}'] as $reference) {
            $this->runOTelConfigExpectingInitError(
                str_replace('__REF__', $reference, $template),
                self::emitSpan(...),
            );

            self::assertSame([], $this->traces);
        }
    }

    public function testSubstitutionIsNotRecursive(): void {
        $this->runOTelConfig(
            $this->configWithHeaderValues(['${SUBST_INNER}']),
            self::emitSpan(...),
            'SUBST_INNER=${SUBST_OUTER}',
            'SUBST_OUTER=outer-value',
        );

        self::assertNotEmpty($this->traces);

        /*
         * The substituted value is used verbatim: a reference inside an
         * environment variable's value is not resolved in a second pass.
         */
        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['${SUBST_OUTER}'], $headers['subst-0']);
    }

    public function testMultipleReferencesInOneString(): void {
        $this->runOTelConfig(
            $this->configWithHeaderValues(['${SUBST_A}-${SUBST_B}']),
            self::emitSpan(...),
            'SUBST_A=alpha',
            'SUBST_B=beta',
        );

        self::assertNotEmpty($this->traces);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['alpha-beta'], $headers['subst-0']);
    }

    public function testSubstitutedHexIntegerIsCoercedForTypedNode(): void {
        /*
         * The substituted value must be coerced to the node's type: 0x10 is
         * a valid YAML/OTLP hex integer (16), which no OTLP payload can fit,
         * so every export is rejected before it reaches the collector.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
                        max_request_size: "${SUBST_HEX_SIZE}"
            YAML,
            self::emitSpan(...),
            'SUBST_HEX_SIZE=0x10',
        );

        self::assertSame([], $this->traces);
        self::assertStringContainsString(
            'maximum request size',
            strtolower($this->lastStderr),
        );
    }

    public function testSubstitutedBooleanIsCoercedForTypedNode(): void {
        /*
         * The string 'true' from the environment is coerced to a boolean for
         * the disabled flag: the SDK degrades to a no-op and nothing is
         * exported.
         */
        $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            disabled: "${SUBST_DISABLED}"

            tracer_provider:
              processors:
                - batch:
                    exporter:
                      otlp_http:
                        endpoint: ${env:OTEL_EXPORTER_OTLP_TRACES_ENDPOINT}
            YAML,
            self::emitSpan(...),
            'SUBST_DISABLED=true',
        );

        self::assertSame([], $this->traces);
    }

    public function testEnvironmentValueCannotInjectYamlStructure(): void {
        /*
         * Substitution happens after the document is parsed, so a value that
         * looks like YAML structure stays an opaque string.
         */
        $this->runOTelConfig(
            $this->configWithHeaderValues(['${SUBST_INJECTION}']),
            self::emitSpan(...),
            'SUBST_INJECTION=a: {b: c}',
        );

        self::assertNotEmpty($this->traces);

        $headers = array_change_key_case($this->requestHeaders[0]);
        self::assertSame(['a: {b: c}'], $headers['subst-0']);
    }
}
