<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\AppHost;
use Convoy\Task\Executable;
use Symfony\Component\Runtime\RunnerInterface;

final class ReactRunner implements RunnerInterface
{
    private ?Executable $handler = null;

    public function __construct(
        private readonly AppHost $app,
        private readonly string $host,
        private readonly int $port,
        private readonly float $requestTimeout = 30.0,
    ) {
    }

    public function withHandler(Executable $handler): self
    {
        $clone = clone $this;
        $clone->handler = $handler;
        return $clone;
    }

    public function run(): int
    {
        if ($this->handler === null) {
            throw new \LogicException('No request handler configured. Call withHandler() before run().');
        }

        $runner = new Runner($this->app, $this->handler, $this->requestTimeout);

        return $runner->run("{$this->host}:{$this->port}");
    }
}
