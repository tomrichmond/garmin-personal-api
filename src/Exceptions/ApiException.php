<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Exceptions;

final class ApiException extends GarminException
{
    public function __construct(public readonly int $statusCode)
    {
        parent::__construct(sprintf('Garmin API returned HTTP %d.', $statusCode));
    }
}
