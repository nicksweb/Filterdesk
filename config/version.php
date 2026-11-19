<?php

return [
    /** Code version in this image, from the VERSION file. */
    'app' => trim((string) @file_get_contents(base_path('VERSION'))) ?: '0.0.0',

    /** Oldest installed version this release can upgrade from directly. */
    'min_upgrade_from' => '0.1.0',

    'stored_version_key' => 'installation.version',
];
