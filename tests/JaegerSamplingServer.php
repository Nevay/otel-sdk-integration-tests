<?php declare(strict_types=1);
namespace Nevay\OTelTest;

use Amp\Http\Http2\Http2ConnectionException;
use Amp\Http\Http2\Http2Parser;
use Amp\Http\Http2\Http2Processor;
use Amp\Http\Http2\Http2StreamException;
use Amp\Http\HPack;
use Amp\Socket\InternetAddress;
use Amp\Socket\ResourceServerSocketFactory;
use Amp\Socket\ServerSocket;
use Amp\Socket\Socket;
use Throwable;
use function Amp\async;
use function pack;
use function strlen;
use function str_starts_with;
use function substr;

/**
 * Minimal plaintext (h2c prior knowledge) gRPC server serving the Jaeger
 * remote sampling API (jaeger.api_v2.SamplingManager/GetSamplingStrategy).
 *
 * This is the only way to verify full jaeger_remote sampler round trips in
 * this environment: the amphp HTTP server cannot serve prior-knowledge h2c,
 * and a C-core based server cannot complete an HTTP/2 exchange here. The
 * server speaks just enough HTTP/2 (SETTINGS handshake, request streams with
 * gRPC framing and trailers) to satisfy the SDK's sampling client, and
 * answers every GetSamplingStrategy call with the configured strategy.
 *
 * Only one concurrent connection is supported — the SDK's HTTP client pools
 * a single connection per endpoint, which is all the tests need.
 */
final class JaegerSamplingServer implements Http2Processor {

    private ServerSocket $server;

    /** @var array<int, string> streamId → accumulated request body */
    private array $bodies = [];

    /** @var list<int> streams whose request body has been fully received */
    private array $completedStreams = [];

    private ?Socket $connection = null;
    private ?HPack $hpack = null;

    public function __construct(
        private readonly string $strategy,
    ) {}

    public function start(): int {
        $this->server = (new ResourceServerSocketFactory())->listen(new InternetAddress('127.0.0.1', 0));
        $port = $this->server->getAddress()->getPort();

        async(function (): void {
            while (null !== ($connection = $this->server->accept())) {
                if ($this->connection !== null) {
                    // Single-connection server; drop surplus connections.
                    $connection->close();
                    continue;
                }

                $this->handleConnection($connection);
            }
        });

        return $port;
    }

    public function stop(): void {
        $this->server->close();
    }

    /**
     * The last complete request body received, decoded as a gRPC frame.
     *
     * @return array{compressed: int, payload: string}|null
     */
    public function lastRequest(): ?array {
        foreach (array_reverse($this->bodies) as $body) {
            if ($body === '') {
                continue;
            }

            return [
                'compressed' => unpack('C', $body)[1],
                'payload' => substr($body, 5),
            ];
        }

        return null;
    }

    private function handleConnection(Socket $connection): void {
        $this->connection = $connection;
        $this->hpack = new HPack();

        try {
            // The server's SETTINGS frame must be the first frame on the connection.
            $connection->write(Http2Parser::compileFrame('', Http2Parser::SETTINGS, 0));

            // Consume the client preface before feeding frames to the parser.
            $buffer = '';
            while (null !== ($chunk = $connection->read())) {
                $buffer .= $chunk;
                if (strlen($buffer) < strlen(Http2Parser::PREFACE)) {
                    continue;
                }

                if (!str_starts_with($buffer, Http2Parser::PREFACE)) {
                    throw new Http2ConnectionException('Expected the h2c connection preface', Http2Parser::PROTOCOL_ERROR);
                }

                $buffer = substr($buffer, strlen(Http2Parser::PREFACE));
                break;
            }

            $parser = new Http2Parser($this, $this->hpack);
            if ($buffer !== '') {
                $parser->push($buffer);
                $this->flushResponses();
            }

            while (null !== ($chunk = $connection->read())) {
                $parser->push($chunk);
                $this->flushResponses();
            }
        } catch (Throwable) {
            // The client went away; nothing to do.
        } finally {
            $this->connection = null;
            $connection->close();
        }
    }

    private function flushResponses(): void {
        if ($this->connection === null || $this->hpack === null) {
            return;
        }

        foreach ($this->completedStreams as $streamId) {
            array_shift($this->completedStreams);

            $frame = Http2Parser::compileFrame(
                $this->hpack->encode([
                    [':status', '200'],
                    ['content-type', 'application/grpc+proto'],
                ]),
                Http2Parser::HEADERS,
                Http2Parser::END_HEADERS,
                $streamId,
            );

            // gRPC frame: compression flag 0 (uncompressed) + message length.
            $frame .= Http2Parser::compileFrame(
                pack('CN', 0, strlen($this->strategy)) . $this->strategy,
                Http2Parser::DATA,
                0,
                $streamId,
            );

            $frame .= Http2Parser::compileFrame(
                $this->hpack->encode([['grpc-status', '0']]),
                Http2Parser::HEADERS,
                Http2Parser::END_HEADERS | Http2Parser::END_STREAM,
                $streamId,
            );

            $this->connection->write($frame);
        }
    }

    // -- Http2Processor -----------------------------------------------------

    public function handleHeaders(int $streamId, array $pseudo, array $headers, bool $streamEnded): void {
        if ($streamEnded) {
            $this->bodies[$streamId] ??= '';
            $this->completedStreams[] = $streamId;
        }
    }

    public function handleData(int $streamId, string $data): void {
        $this->bodies[$streamId] = ($this->bodies[$streamId] ?? '') . $data;
    }

    public function handleStreamEnd(int $streamId): void {
        $this->completedStreams[] = $streamId;
    }

    public function handleSettings(array $settings): void {
        // The receiver of a SETTINGS frame must acknowledge it.
        $this->connection?->write(Http2Parser::compileFrame('', Http2Parser::SETTINGS, Http2Parser::ACK));
    }

    public function handlePing(string $data): void {
        $this->connection?->write(Http2Parser::compileFrame($data, Http2Parser::PING, Http2Parser::ACK));
    }

    public function handlePong(string $data): void {}

    public function handleShutdown(int $lastId, int $error, string $message): void {}

    public function handleStreamWindowIncrement(int $streamId, int $windowSize): void {}

    public function handleConnectionWindowIncrement(int $windowSize): void {}

    public function handlePushPromise(int $streamId, int $pushId, array $pseudo, array $headers): void {}

    public function handlePriority(int $streamId, int $parentId, int $weight): void {}

    public function handleStreamReset(int $streamId, int $errorCode): void {}

    public function handleStreamException(Http2StreamException $exception): void {}

    public function handleConnectionException(Http2ConnectionException $exception): void {}

    // -- SamplingStrategyResponse builders ----------------------------------
    //
    // Wire format of jaeger-idl api_v2 sampling.proto:
    //
    // message SamplingStrategyResponse {
    //     StrategyType strategy_type = 1;   // 0=PROBABILITY, 1=RATE_LIMITING, 2=OPERATIONS
    //     ProbabilisticSampling probabilistic_sampling = 2; // { double sampling_rate = 1 }
    //     RateLimitingSampling rate_limiting_sampling = 3;  // { int max_traces_per_second = 1 }
    //     OperationBasedSampling operation_sampling = 4;    // { double default_sampling_probability = 1,
    //                                                       //   repeated PerOperationStrategies per_operation_strategies = 3 }
    // }

    public static function probabilityStrategy(float $samplingRate): string {
        return "\x08\x00" . self::lenField(2, "\x09" . pack('e', $samplingRate));
    }

    public static function rateLimitingStrategy(int $maxTracesPerSecond): string {
        return "\x08\x01" . self::lenField(3, "\x08" . self::varint($maxTracesPerSecond));
    }

    /**
     * @param array<string, float> $perOperation operation name → sampling rate
     */
    public static function operationsStrategy(float $defaultProbability, array $perOperation): string {
        $operations = "\x09" . pack('e', $defaultProbability);
        foreach ($perOperation as $operation => $samplingRate) {
            $operations .= self::lenField(
                3,
                self::lenField(1, $operation) . self::lenField(2, "\x09" . pack('e', $samplingRate)),
            );
        }

        return "\x08\x02" . self::lenField(4, $operations);
    }

    private static function lenField(int $field, string $value): string {
        return chr(($field << 3) | 2) . self::varint(strlen($value)) . $value;
    }

    private static function varint(int $value): string {
        $out = '';
        do {
            $byte = $value & 0x7f;
            $value >>= 7;
            $out .= chr($byte | ($value > 0 ? 0x80 : 0));
        } while ($value > 0);

        return $out;
    }
}
