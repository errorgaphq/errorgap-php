<?php

declare(strict_types=1);

// The fake ingestors here serve one request each; the settings poll is
// exercised on its own in RemoteSettingsTest.
putenv('ERRORGAP_REMOTE_SETTINGS=false');

require __DIR__ . '/../vendor/autoload.php';
