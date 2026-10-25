<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TomRichmond\GarminPersonalApi\GarminClient;

$apiKey = getenv('GARMIN_PERSONAL_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set GARMIN_PERSONAL_API_KEY first.\n");
    exit(1);
}

$garmin = new GarminClient(apiKey: $apiKey);
$activities = $garmin->activities()->list(startDate: '2026-10-01', endDate: '2026-10-25');
echo json_encode($activities, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
