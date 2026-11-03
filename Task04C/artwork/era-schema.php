<?php
// Shared by the gallery and the integrity page; this check never changes data.
// Until 06-era-identities.sql has run, Eras stay view-only instead of pausing
// the whole gallery, because no other table depends on them.
function era_schema_ready(PDO $pdo): bool
{
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'Eras'
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'")->fetchColumn();
    if ($tables !== 1) return false;

    $columns = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
        IS_NULLABLE, EXTRA, CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'Eras'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $id = $columns['EraID'] ?? [];
    $name = $columns['EraName'] ?? [];
    $years = $columns['EraYears'] ?? [];
    if (($id['DATA_TYPE'] ?? '') !== 'int'
        || str_contains($id['COLUMN_TYPE'] ?? '', 'unsigned')
        || ($id['IS_NULLABLE'] ?? '') !== 'NO'
        || ($id['EXTRA'] ?? '') !== 'auto_increment'
        || ($name['DATA_TYPE'] ?? '') !== 'varchar'
        || (int)($name['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 255
        || ($name['IS_NULLABLE'] ?? '') !== 'NO'
        || ($years['DATA_TYPE'] ?? '') !== 'varchar'
        || (int)($years['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 255) return false;

    $key = $pdo->query("SELECT COLUMN_NAME FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'Eras'
          AND index_name = 'PRIMARY'")->fetchAll(PDO::FETCH_COLUMN);
    return $key === ['EraID'];
}
