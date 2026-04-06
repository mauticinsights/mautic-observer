<?php

declare(strict_types=1);

namespace Mautic\CoreBundle\Helper;

/**
 * @phpstan-type Params array<string, mixed>
 */
final class CoreParametersHelper
{
    /** @var Params */
    public array $params = [];

    public function get(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }
}
