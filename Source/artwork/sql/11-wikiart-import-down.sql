-- Reverses 11-wikiart-import.sql. Run as a database administrator, only when
-- removing the WikiArt import: it deletes the provenance rows (back them up
-- first). Imported artists and artworks stay as ordinary gallery records.
-- Nothing else is touched. Safe to repeat. Afterwards the import page says an
-- upgrade is required, and a later import after reapplying 11 links the
-- records it finds by name and year instead of adding them again.
USE `art`;
DROP TABLE IF EXISTS `art_import_sources`;
