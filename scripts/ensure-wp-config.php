<?php
// scripts/ensure-wp-config.php
// Ensures the repository's wp-config.php is present after composer install.

$root = dirname(__DIR__);
$repoConfig = $root . '/wp-config.php';
$sample = $root . '/wp-config.php.sample';
$gitDir = $root . '/.git';

// If this is a git clone, try to restore the tracked file.
if (is_dir($gitDir)) {
    $cmd = 'git -C ' . escapeshellarg($root) . ' checkout -- wp-config.php 2>/dev/null';
    exec($cmd, $out, $rc);
    if ($rc === 0 && file_exists($repoConfig)) {
        // Restored successfully
        exit(0);
    }
}

// If wp-config.php is missing, fallback to copying the sample if available.
if (!file_exists($repoConfig) && file_exists($sample)) {
    if (!@copy($sample, $repoConfig)) {
        fwrite(STDERR, "Failed to copy wp-config.php.sample to wp-config.php\n");
        exit(1);
    }
    @chmod($repoConfig, 0644);
    exit(0);
}

// Nothing to do.
exit(0);
