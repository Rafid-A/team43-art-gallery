-- Reverses 10-saved-works.sql. Run as a database administrator, only when
-- removing "My collection": it permanently deletes every user's saved
-- artworks (back them up first). Nothing else is touched. Safe to repeat.
-- Afterwards the gallery keeps working; the save button is hidden and the
-- profile says My collection is unavailable until 10 is applied again.
USE `art`;
DROP TABLE IF EXISTS `art_saved_works`;
