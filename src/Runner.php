<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\AppHost;
use Convoy\Concurrency\CancellationToken;
use Convoy\Handler\HandlerGroup;
use Convoy\Handler\MatchResult;
use Convoy\Support\SignalHandler;
use Convoy\Task\Executable;
use Convoy\Trace\TraceType;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use React\Stream\CompositeStream;
use React\Stream\ThroughStream;
use RuntimeException;

use function React\Async\async;

final class Runner
{
    private bool $running = false;
    private bool $shutdownRequested = false;

    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private ?TimerInterface $windowsTimer = null;

    /** @var ?callable(\Convoy\ExecutionScope, \React\Stream\ThroughStream, \Psr\Http\Message\ServerRequestInterface): ?\Psr\Http\Message\ResponseInterface */
    private $onUpgrade = null;

    public function __construct(
        private readonly AppHost $app,
        private readonly Executable $handler,
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    public static function withRoutes(
        AppHost $app,
        RouteGroup|HandlerGroup $routes,
        float $requestTimeout = 30.0,
    ): self {
        return new self($app, $routes, $requestTimeout);
    }

    public function withUpgradeHandler(callable $onUpgrade): self
    {
        $this->onUpgrade = $onUpgrade;
        return $this;
    }

    public function run(string $listen = '0.0.0.0:8080'): int
    {
        $this->app->startup();

        $this->socket = new SocketServer($listen);
        $this->server = new HttpServer($this->handleRequest(...));
        $this->server->listen($this->socket);

        $this->running = true;
        $this->app->trace()->log(TraceType::LifecycleStartup, 'ready', ['uri' => $listen]);

        printf("Server running at http://%s\n", $listen);

        $this->setupSignalHandlers();
        $this->setupWindowsShutdownCheck();

        Loop::run();

        return 0;
    }

    public function stop(): void
    {
        $this->shutdown();
    }

    private function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->isWebSocketUpgrade($request)) {
            return $this->handleUpgrade($request);
        }

        $token = CancellationToken::timeout($this->requestTimeout);
        $scope = $this->app->createScope($token);
        $scope = $scope->withAttribute('request', $request);
        $trace = $scope->trace();
        $trace->reset();

        try {
            $response = $scope->execute($this->handler);

            if ($response instanceof ResponseInterface) {
                return $response;
            }

            return self::toResponse($response);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'No route matches')) {
                return Response::json([
                    'error' => 'Not Found',
                    'message' => $e->getMessage(),
                ])->withStatus(404);
            }

            $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

            return Response::json([
                'error' => $e->getMessage(),
                'trace' => $this->formatTrace($e),
            ])->withStatus(500);
        } catch (\Throwable $e) {
            $trace->log(TraceType::Failed, 'request', ['error' => $e->getMessage()]);

            return Response::json([
                'error' => $e->getMessage(),
                'trace' => $this->formatTrace($e),
            ])->withStatus(500);
        } finally {
            $trace->print();
            $scope->dispose();
        }
    }

    /**
     * Handles WebSocket upgrade requests separately from HTTP.
     *
     * The onUpgrade callback receives (ExecutionScope, ThroughStream, ServerRequestInterface)
     * and is responsible for wiring the WsConnectionHandler. The scope lifecycle is owned
     * by the connection handler, not the Runner.
     */
    private function handleUpgrade(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->onUpgrade === null) {
            return Response::json([
                'error' => 'WebSocket upgrades not configured',
            ])->withStatus(426);
        }

        $scope = $this->app->createScope(CancellationToken::none());
        $scope = $scope->withAttribute('request', $request);

        try {
            $onUpgrade = $this->onUpgrade;

            // Two separate channels prevent the echo loop that occurs with a
            // single ThroughStream. React HTTP pipes client data INTO the
            // response body's writable side, while the WsConnectionHandler
            // writes server frames to the transport's writable side. With a
            // single ThroughStream both directions share the same 'data' event,
            // causing server frames to be fed back into the frame codec.
            $outgoing = new ThroughStream(); // server -> client
            $incoming = new ThroughStream(); // client -> server

            // Transport for the upgrade handler:
            //   read ('data') from $incoming (client frames)
            //   write() to $outgoing (server frames)
            $transport = new CompositeStream($incoming, $outgoing);

            // Response body for React HTTP:
            //   read ('data') from $outgoing (server frames sent to client)
            //   write() to $incoming (React HTTP pipes client data here)
            $body = new CompositeStream($outgoing, $incoming);

            $response = $onUpgrade($scope, $transport, $request);

            if (!$response instanceof ResponseInterface) {
                $scope->dispose();
                return Response::json(['error' => 'Upgrade handler must return ResponseInterface'])->withStatus(500);
            }

            if ($response->getStatusCode() !== 101) {
                $scope->dispose();
                return $response;
            }

            return new Response(
                101,
                $response->getHeaders(),
                $body,
            );
        } catch (RuntimeException $e) {
            $scope->dispose();
            if (str_starts_with($e->getMessage(), 'No route matches')) {
                return Response::json([
                    'error' => 'Not Found',
                    'message' => $e->getMessage(),
                ])->withStatus(404);
            }
            return Response::json(['error' => $e->getMessage()])->withStatus(500);
        } catch (\Throwable $e) {
            $scope->dispose();
            return Response::json(['error' => $e->getMessage()])->withStatus(500);
        }
    }

    private function isWebSocketUpgrade(ServerRequestInterface $request): bool
    {
        return strtolower($request->getHeaderLine('Upgrade')) === 'websocket'
            && stripos($request->getHeaderLine('Connection'), 'upgrade') !== false;
    }

    private function setupSignalHandlers(): void
    {
        SignalHandler::register($this->createShutdownHandler());
    }

    private function setupWindowsShutdownCheck(): void
    {
        if (!SignalHandler::isWindows()) {
            return;
        }

        $this->windowsTimer = Loop::addPeriodicTimer(0.1, function () {
            if ($this->shutdownRequested) {
                Loop::stop();
            }
        });
    }

    /** Intentionally captures $this - runner is process-scoped, no leak risk. */
    private function createShutdownHandler(): callable
    {
        return function (): void {
            $this->shutdown();
        };
    }

    private function shutdown(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        $this->shutdownRequested = true;

        $this->app->trace()->log(TraceType::LifecycleShutdown, 'shutdown');
        echo "\nShutting down...\n";

        if ($this->windowsTimer !== null) {
            Loop::cancelTimer($this->windowsTimer);
            $this->windowsTimer = null;
        }

        $this->socket?->close();
        $this->app->shutdown();

        if (!SignalHandler::isWindows()) {
            Loop::stop();
        }
    }

    public static function toResponse(mixed $data): ResponseInterface
    {
        if (is_array($data) || is_object($data)) {
            return Response::json($data);
        }

        if (is_string($data)) {
            return new Response(200, ['Content-Type' => 'text/plain'], $data);
        }

        return Response::json(['result' => $data]);
    }

    /** @return list<string> */
    private function formatTrace(\Throwable $e): array
    {
        $trace = [];

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 0;
            $func = $frame['function'];
            $class = isset($frame['class']) ? $frame['class'] . '::' : '';
            $trace[] = "{$class}{$func} at {$file}:{$line}";
        }

        return array_slice($trace, 0, 10);
    }
}
