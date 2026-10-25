<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Resources;

use InvalidArgumentException;
use TomRichmond\GarminPersonalApi\Http\ApiClient;

final readonly class Activities
{
    public function __construct(private ApiClient $apiClient) {}

    /** @return list<array<string, mixed>> */
    public function list(
        ?string $startDate = null,
        ?string $endDate = null,
        int $start = 0,
        int $limit = 20,
        ?string $activityType = null,
    ): array {
        if ($start < 0 || $limit < 1) {
            throw new InvalidArgumentException('Start must be non-negative and limit must be positive.');
        }

        $query = ['start' => $start, 'limit' => $limit];
        if ($startDate !== null) {
            $query['startDate'] = $startDate;
        }

        if ($endDate !== null) {
            $query['endDate'] = $endDate;
        }

        if ($activityType !== null) {
            $query['activityType'] = $activityType;
        }

        return $this->apiClient->getList('/activitylist-service/activities/search/activities', $query);
    }

    /** @return array<string, mixed> */
    public function get(int $activityId): array
    {
        self::assertValidId($activityId);

        return $this->apiClient->getObject('/activity-service/activity/' . $activityId);
    }

    /** Return the unextracted ZIP archive containing the activity file. */
    public function downloadZip(int $activityId): string
    {
        self::assertValidId($activityId);

        return $this->apiClient->getBytes('/download-service/files/activity/' . $activityId);
    }

    private static function assertValidId(int $activityId): void
    {
        if ($activityId <= 0) {
            throw new InvalidArgumentException('Activity ID must be a positive integer.');
        }
    }
}
