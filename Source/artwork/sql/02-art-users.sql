USE art;
CREATE TABLE IF NOT EXISTS art_users (
  forename VARCHAR(32) NOT NULL,
  surname VARCHAR(32) NOT NULL,
  username VARCHAR(32) NOT NULL PRIMARY KEY,
  password VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT INTO art_users VALUES ('first1', 'last1', 'user1', '$2y$10$P59GvJTWp.oDbw2L69QDC.QMuibZUs4i5yJ19K4B75JAzig.j5jlm')
ON DUPLICATE KEY UPDATE forename=VALUES(forename), surname=VALUES(surname), password=VALUES(password);
INSERT INTO art_users VALUES ('first2', 'last2', 'user2', '$2y$10$Sz1HlckoC2LB2oYNgLYVheodh5rsznr.T70i.KTSQbf2kE8l0opFm')
ON DUPLICATE KEY UPDATE forename=VALUES(forename), surname=VALUES(surname), password=VALUES(password);
INSERT INTO art_users VALUES ('first3', 'last3', 'user3', '$2y$10$J5heg1GRtqdHDAqHVdOAbehu5aci1KHBrM.iwY4xRg/EbQd67e5fG')
ON DUPLICATE KEY UPDATE forename=VALUES(forename), surname=VALUES(surname), password=VALUES(password);
INSERT INTO art_users VALUES ('first4', 'last4', 'user4', '$2y$10$AWDHUS9TRM3fwflkjLu2X.kHJXQu05Qzx079pNMtCb1tEnxpiy.KO')
ON DUPLICATE KEY UPDATE forename=VALUES(forename), surname=VALUES(surname), password=VALUES(password);
