<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TomRichmond\GarminPersonalApi\GarminClient;

$activityId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($activityId === false) {
    fwrite(STDERR, "Usage: php examples/get-activity.php ACTIVITY_ID\n");
    exit(1);
}

$apiKey = getenv('GARMIN_PERSONAL_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set GARMIN_PERSONAL_API_KEY first.\n");
    exit(1);
}

$activity = new GarminClient(apiKey: $apiKey)->activities()->get($activityId);
try {
    $json = json_encode($activity, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
} catch (JsonException) {
    fwrite(STDERR, "Could not encode activity data as JSON.\n");
    exit(1);
}

echo $json . PHP_EOL;
