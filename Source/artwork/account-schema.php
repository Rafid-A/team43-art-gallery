<?php
// Shared by readiness and the gallery; this check never changes data.
// Migration 08 adds both columns together; anything else is unsupported.
function account_schema_ready(PDO $pdo): bool
{
    $columns = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'art_users'
          AND COLUMN_NAME IN ('role', 'status')")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $role = $columns['role'] ?? [];
    $status = $columns['status'] ?? [];
    return ($role['COLUMN_TYPE'] ?? '') === "enum('client','admin')"
        && ($role['IS_NULLABLE'] ?? '') === 'NO'
        && ($status['COLUMN_TYPE'] ?? '') === "enum('active','disabled')"
        && ($status['IS_NULLABLE'] ?? '') === 'NO';
}
