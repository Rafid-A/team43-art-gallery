<?php
// Shared by readiness and the gallery; this check never changes database data.
function subject_schema_ready(PDO $pdo): bool
{
    $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name IN ('Subjects', 'PaintingSubjects')
          AND table_type = 'BASE TABLE' AND engine = 'InnoDB'")->fetchColumn();
    if ($tables !== 2) return false;

    $columns = $pdo->query("SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE,
        IS_NULLABLE, EXTRA, CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'Subjects'")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $id = $columns['SubjectID'] ?? [];
    $name = $columns['SubjectName'] ?? [];
    if (($id['DATA_TYPE'] ?? '') !== 'int'
        || str_contains($id['COLUMN_TYPE'] ?? '', 'unsigned')
        || ($id['IS_NULLABLE'] ?? '') !== 'NO'
        || ($id['EXTRA'] ?? '') !== 'auto_increment'
        || ($name['DATA_TYPE'] ?? '') !== 'varchar'
        || (int)($name['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 255
        || ($name['IS_NULLABLE'] ?? '') !== 'NO') return false;

    $key = $pdo->query("SELECT COLUMN_NAME FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = 'Subjects'
          AND index_name = 'PRIMARY'")->fetchAll(PDO::FETCH_COLUMN);
    return $key === ['SubjectID'];
}
