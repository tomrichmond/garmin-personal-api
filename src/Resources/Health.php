<?php

declare(strict_types=1);

namespace TomRichmond\GarminPersonalApi\Resources;

use DateTimeImmutable;
use InvalidArgumentException;
use TomRichmond\GarminPersonalApi\Http\ApiClient;

final readonly class Health
{
    public function __construct(private ApiClient $apiClient) {}

    /** @return array<array-key, mixed>|null */
    public function dailyHeartRate(string $date): ?array
    {
        self::assertDate($date);

        return $this->apiClient->getJson('/wellness-service/wellness/dailyHeartRate', ['date' => $date]);
    }

    /** @return array<array-key, mixed>|null */
    public function heartRateZones(): ?array
    {
        return $this->apiClient->getJson('/biometric-service/heartRateZones/v2');
    }

    /** @return array<array-key, mixed>|null */
    public function dailyHrv(string $date): ?array
    {
        self::assertDate($date);

        return $this->apiClient->getJson('/hrv-service/hrv/' . $date);
    }

    /** @return array<array-key, mixed>|null */
    public function dailyHrvSummaries(string $startDate, string $endDate): ?array
    {
        self::assertDateRange($startDate, $endDate);

        return $this->apiClient->getJson('/hrv-service/hrv/daily/' . $startDate . '/' . $endDate);
    }

    /** @return array<array-key, mixed>|null */
    public function dailySleep(string $date): ?array
    {
        self::assertDate($date);

        return $this->apiClient->getJson('/sleep-service/sleep/dailySleep', ['date' => $date]);
    }

    /** @return array<array-key, mixed>|null */
    public function dailySleepSummaries(string $startDate, string $endDate): ?array
    {
        self::assertDateRange($startDate, $endDate);

        return $this->apiClient->getJson('/sleep-service/sleep/dailySleepSummariesByDate', [
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /** @return array<array-key, mixed>|null */
    public function dailyBodyBattery(string $startDate, string $endDate, ?int $chunks = null): ?array
    {
        self::assertDateRange($startDate, $endDate);

        $query = ['startDate' => $startDate, 'endDate' => $endDate];
        if ($chunks !== null) {
            $query['chunks'] = $chunks;
        }

        return $this->apiClient->getJson('/wellness-service/wellness/bodyBattery/reports/daily', $query);
    }

    /** @return array<array-key, mixed>|null */
    public function dailySummaries(string $startDate, string $endDate): ?array
    {
        self::assertDateRange($startDate, $endDate);

        return $this->apiClient->getJson('/usersummary-service/v2/dailysummaries', [
            'startDate' => $startDate,
            'endDate' => $endDate,
        ]);
    }

    /** @return array<array-key, mixed>|null */
    public function dailySteps(string $startDate, string $endDate): ?array
    {
        self::assertDateRange($startDate, $endDate);

        return $this->apiClient->getJson('/usersummary-service/stats/steps/daily/' . $startDate . '/' . $endDate);
    }

    /** @return array<array-key, mixed>|null */
    public function dailyStress(string $startDate, string $endDate): ?array
    {
        self::assertDateRange($startDate, $endDate);

        return $this->apiClient->getJson('/usersummary-service/stats/stress/daily/' . $startDate . '/' . $endDate);
    }

    private static function assertDateRange(string $startDate, string $endDate): void
    {
        self::assertDate($startDate);
        self::assertDate($endDate);
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('Start date must not be after end date.');
        }
    }

    private static function assertDate(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            throw new InvalidArgumentException('Date must be in YYYY-MM-DD format.');
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Date must be a valid calendar date.');
        }
    }
}
