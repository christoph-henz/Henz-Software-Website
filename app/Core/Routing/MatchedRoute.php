<?php

declare(strict_types=1);

namespace App\Core\Routing;

final class MatchedRoute
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly Route $route,
        public readonly array $params,
    ) {
    }
}
