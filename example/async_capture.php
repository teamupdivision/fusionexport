<?php

// Async capture

require __DIR__ . '/../vendor/autoload.php';

use FusionExport\ExportManager;
use FusionExport\ExportConfig;

$exportConfig = new ExportConfig();
$exportConfig->set('chartConfig', file_get_contents('single.json'));
$exportConfig->set('callbackFilePath', realpath('expand_scroll.js'));
$exportConfig->set('asyncCapture', 'true');

$onStateChange = function ($state) {
  echo('STATE: [' . $state->reporter . '] ' . $state->customMsg . "\n");
};

$onDone = function ($export, $e) {
    if ($e) {
        echo('ERROR: ' . $e->getMessage());
    } else {
        foreach ($export as $file) {
            echo('DONE: ' . $file->realName . "\n");
            copy($file->tmpPath, $file->realName);
        }
    }
};

$exportManager = new ExportManager();
$exportManager->export($exportConfig, $onDone, $onStateChange);
