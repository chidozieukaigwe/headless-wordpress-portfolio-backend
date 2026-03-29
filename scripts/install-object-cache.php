<?php
// Copy vendor object-cache drop-in into wp-content/object-cache.php if available.
// This is safe to run multiple times and fails silently if the vendor file is missing.

$vendorPaths = [
    __DIR__ . '/../vendor/tillkruss/redis-cache/object-cache.php',
    __DIR__ . '/../vendor/tillkruss/redis-cache/src/object-cache.php',
];

$dest = realpath(__DIR__ . '/../wp-content') . '/object-cache.php';

$found = false;
foreach ($vendorPaths as $p) {
    if (file_exists($p)) {
        $found = $p;
        break;
    }
}

if (! $found) {
    // Nothing to do (developer can install plugin manually or via composer)
    fwrite(STDOUT, "install-object-cache: no tillkruss drop-in found in vendor, skipping\n");
    exit(0);
}

if (! is_dir(dirname($dest))) {
    mkdir(dirname($dest), 0755, true);
}

if (copy($found, $dest)) {
    fwrite(STDOUT, "install-object-cache: copied object-cache drop-in to wp-content/object-cache.php\n");
} else {
    fwrite(STDOUT, "install-object-cache: failed to copy drop-in\n");
}

return 0;
