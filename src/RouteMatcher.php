<?php

declare(strict_types=1);

namespace Convoy\Http;

use Convoy\ExecutionScope;
use Convoy\Handler\Handler;
use Convoy\Handler\HandlerMatcher;
use Convoy\Handler\MatchResult;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class RouteMatcher implements HandlerMatcher
{
    /** @param array<string, Handler> $handlers */
    public function match(ExecutionScope $scope, array $handlers): ?MatchResult
    {
        $request = $scope->attribute('request');

        if (!$request instanceof ServerRequestInterface) {
            return null;
        }

        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        $upgradeHeader = (string) $request->getHeaderLine('Upgrade');
        $connectionHeader = (string) $request->getHeaderLine('Connection');
        $isWsUpgrade = strtolower($upgradeHeader) === 'websocket'
            && stripos($connectionHeader, 'upgrade') !== false;
        $requestProtocol = $isWsUpgrade ? 'ws' : 'http';

        foreach ($handlers as $handler) {
            if (!$handler->config instanceof RouteConfig) {
                continue;
            }

            if ($handler->config->protocol !== $requestProtocol) {
                continue;
            }

            $params = $handler->config->matches($method, $path);

            if ($params !== null) {
                $scope = $scope->withAttribute('route.params', $params);

                foreach ($params as $name => $value) {
                    $scope = $scope->withAttribute("route.$name", $value);
                }

                $scope = new ExecutionContext(
                    $scope,
                    $request,
                    new RouteParams($params),
                    new QueryParams($request->getQueryParams()),
                    $handler->config,
                );

                return new MatchResult($handler, $scope);
            }
        }

        throw new RuntimeException("No route matches $method $path");
    }
}
