<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Resources;

use Generator;
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
        self::assertPagination($start, $limit);

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

    /**
     * Lazily request activity pages until Garmin returns an empty page.
     *
     * @return Generator<int, array<string, mixed>, mixed, void>
     */
    public function iterate(
        ?string $startDate = null,
        ?string $endDate = null,
        int $start = 0,
        int $limit = 20,
        ?string $activityType = null,
        ?int $maxPages = null,
    ): Generator {
        self::assertPagination($start, $limit);
        if ($maxPages !== null && $maxPages < 1) {
            throw new InvalidArgumentException('Max pages must be positive.');
        }

        return $this->iteratePages($startDate, $endDate, $start, $limit, $activityType, $maxPages);
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

    /** @return Generator<int, array<string, mixed>, mixed, void> */
    private function iteratePages(
        ?string $startDate,
        ?string $endDate,
        int $start,
        int $limit,
        ?string $activityType,
        ?int $maxPages,
    ): Generator {
        $pages = 0;
        do {
            $activities = $this->list($startDate, $endDate, $start, $limit, $activityType);
            foreach ($activities as $activity) {
                yield $activity;
            }

            $start += count($activities);
            $pages++;
        } while ($activities !== [] && ($maxPages === null || $pages < $maxPages));
    }

    private static function assertPagination(int $start, int $limit): void
    {
        if ($start < 0 || $limit < 1) {
            throw new InvalidArgumentException('Start must be non-negative and limit must be positive.');
        }
    }
}
