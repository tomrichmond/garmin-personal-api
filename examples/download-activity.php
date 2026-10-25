<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use TomRichmond\GarminPersonalApi\GarminClient;

$activityId = filter_var($argv[1] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$outputPath = $argv[2] ?? '';
if ($activityId === false || $outputPath === '') {
    fwrite(STDERR, "Usage: php examples/download-activity.php ACTIVITY_ID OUTPUT.zip\n");
    exit(1);
}

$apiKey = getenv('GARMIN_PERSONAL_API_KEY');
if ($apiKey === false || $apiKey === '') {
    fwrite(STDERR, "Set GARMIN_PERSONAL_API_KEY first.\n");
    exit(1);
}

$zipBytes = new GarminClient(apiKey: $apiKey)->activities()->downloadZip($activityId);
$output = new SplFileObject($outputPath, 'xb');

$complete = false;
try {
    $offset = 0;
    $length = strlen($zipBytes);
    while ($offset < $length) {
        $written = $output->fwrite(substr($zipBytes, $offset));
        if ($written === false || $written === 0) {
            throw new RuntimeException('Failed to write the complete ZIP file.');
        }

        $offset += $written;
    }

    if (! $output->fflush()) {
        throw new RuntimeException('Failed to flush the complete ZIP file.');
    }

    $complete = true;
} finally {
    unset($output);
    if (! $complete) {
        unlink($outputPath);
    }
}

fwrite(STDOUT, "Saved ZIP to $outputPath\n");
