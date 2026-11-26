<?php
declare(strict_types=1);

/* Creates the database tables (if needed). The system starts empty: HR adds the real records. */

function install_schema(): void
{
    $sql = file_get_contents(__DIR__ . '/schema.sql');
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        db()->exec($stmt);
    }
}
