<?php
// Shared by the gallery and the integrity page; this check never changes data.
// Unsupported schemas and conflicting reserved identities stay view-only.
function era_schema_ready(PDO $pdo): bool
{
    return era_schema_status($pdo) === 'ready';
}

// A usable primary key still supports exact reads when identity conflicts block writes.
function era_schema_status(PDO $pdo): string
{
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = 'Eras'
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'")->fetchColumn();
    if ($tables !== 1) return 'unsupported';

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
        || (int)($years['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 255) return 'unsupported';

    $key = $pdo->query("SELECT COLUMN_NAME FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'Eras'
          AND index_name = 'PRIMARY'")->fetchAll(PDO::FETCH_COLUMN);
    if ($key !== ['EraID']) return 'unsupported';

    // Match migration 06 byte-for-byte, but allow supplied eras to be deleted.
    $reserved = [
        1 => ['Medieval', 'Before 1400'],
        2 => ['Renaissance', '1400-1550'],
        3 => ['Post Renaissance', '1550-1700'],
        4 => ['Early Modern', '1700-1875'],
        5 => ['Modern', '1875-1945'],
        6 => ['Contemporary', 'After 1945'],
    ];
    $eras = $pdo->query('SELECT EraID, EraName, EraYears FROM Eras WHERE EraID BETWEEN 1 AND 6');
    foreach ($eras as $era) {
        if ([$era['EraName'], $era['EraYears']] !== $reserved[(int)$era['EraID']]) return 'conflict';
    }
    return 'ready';
}
