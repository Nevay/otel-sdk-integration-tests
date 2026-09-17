<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use OpenTelemetry\API\Baggage\Baggage;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use function sprintf;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('spec')]
#[Group('config-file'), Group('propagation')]
final class ConfigPropagatorTest extends TestCase {
    use OTelEndpointTrait;

    /*
     * =========================================================================
     * Propagators
     * =========================================================================
     */

    public function testConfigFilePropagatorsConfigureTraceContextAndBaggage(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:
                - baggage:
            YAML,
            static function (): void {
                $traceId = '0123456789abcdef0123456789abcdef';
                $spanId = '0123456789abcdef';

                $carrier = [
                    'traceparent' => sprintf(
                        '00-%s-%s-01',
                        $traceId,
                        $spanId,
                    ),
                    'baggage' => 'test-key=test-value',
                ];

                $context = Globals::propagator()->extract($carrier);
                $spanContext = Span::fromContext($context)->getContext();

                echo json_encode([
                    'traceId' => $spanContext->getTraceId(),
                    'spanId' => $spanContext->getSpanId(),
                    'sampled' => $spanContext->isSampled(),
                    'baggage' => Baggage::fromContext($context)->getValue('test-key'),
                ], JSON_THROW_ON_ERROR);
            },
        );

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            '0123456789abcdef0123456789abcdef',
            $result['traceId'],
        );

        self::assertSame(
            '0123456789abcdef',
            $result['spanId'],
        );

        self::assertTrue($result['sampled']);

        self::assertSame(
            'test-value',
            $result['baggage'],
        );
    }

    public function testConfigFileCanConfigureOnlyTraceContextPropagator(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:
            YAML,
            static function (): void {
                $carrier = [
                    'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
                    'baggage' => 'test-key=test-value',
                ];

                $context = Globals::propagator()->extract($carrier);
                $spanContext = Span::fromContext($context)->getContext();

                echo json_encode([
                    'valid' => $spanContext->isValid(),
                    'traceId' => $spanContext->getTraceId(),
                    'baggage' => Baggage::fromContext($context)->getValue('test-key'),
                ], JSON_THROW_ON_ERROR);
            },
        );

        $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($result['valid']);

        self::assertSame(
            '0123456789abcdef0123456789abcdef',
            $result['traceId'],
        );

        self::assertNull($result['baggage']);
    }

    public function testConfigFilePropagatorsAreUsedForInjection(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - tracecontext:
                - baggage:
            YAML,
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $baggage = Baggage::fromContext($context)
                    ->toBuilder()
                    ->set('test-key', 'test-value')
                    ->build();

                $context = $baggage->storeInContext($context);

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        $carrier = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(
            '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
            $carrier['traceparent'],
        );

        self::assertSame(
            'test-key=test-value',
            $carrier['baggage'],
        );
    }

    public function testConfigFileWithoutPropagatorInjectsNothing(): void
    {
        /*
         * The config file schema only supports 'propagator.composite' (or
         * 'propagator.composite_list'); there is no 'none' propagator
         * component. Omitting the propagator section entirely leaves the
         * default, which injects nothing.
         */
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"
            YAML,
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $baggage = Baggage::fromContext($context)
                    ->toBuilder()
                    ->set('test-key', 'test-value')
                    ->build();

                $context = $baggage->storeInContext($context);

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame(
            [],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testConfigFilePropagatorB3CompositeInjectsSingleHeader(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - b3:
            YAML,
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame(
            [
                'b3' => '0123456789abcdef0123456789abcdef-0123456789abcdef-1',
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testConfigFilePropagatorB3MultiCompositeInjectsHeaders(): void
    {
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite:
                - b3multi:
            YAML,
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame(
            [
                'X-B3-TraceId' => '0123456789abcdef0123456789abcdef',
                'X-B3-SpanId' => '0123456789abcdef',
                'X-B3-Sampled' => '1',
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testConfigFileCompositeListConfiguresPropagators(): void
    {
        /*
         * composite_list is the scalar alternative to the composite list;
         * it configures the same propagators from a comma-separated string.
         */
        $output = $this->runOTelConfig(
            <<<'YAML'
            file_format: "1.2"

            propagator:
              composite_list: tracecontext,baggage
            YAML,
            static function (): void {
                $spanContext = SpanContext::create(
                    '0123456789abcdef0123456789abcdef',
                    '0123456789abcdef',
                    TraceFlags::SAMPLED,
                );

                $context = Context::getCurrent()
                    ->withContextValue(Span::wrap($spanContext));

                $context = Baggage::fromContext($context)
                    ->toBuilder()
                    ->set('test-key', 'test-value')
                    ->build()
                    ->storeInContext($context);

                $carrier = [];

                Globals::propagator()->inject(
                    $carrier,
                    null,
                    $context,
                );

                echo json_encode($carrier, JSON_THROW_ON_ERROR);
            },
        );

        self::assertSame(
            [
                'traceparent' => '00-0123456789abcdef0123456789abcdef-0123456789abcdef-01',
                'baggage' => 'test-key=test-value',
            ],
            json_decode($output, true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
