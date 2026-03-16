<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\AppHost;
use Convoy\Concurrency\CancellationToken;
use Convoy\Handler\HandlerGroup;
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
use RuntimeException;

final class Runner
{
    private bool $running = false;
    private bool $shutdownRequested = false;

    private ?HttpServer $server = null;
    private ?SocketServer $socket = null;
    private ?TimerInterface $windowsTimer = null;

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
