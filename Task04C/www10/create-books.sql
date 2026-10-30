-- www10: create the database and a classic "books" table, then seed it.
-- Run with:  mysql -u root < create-books.sql

DROP DATABASE IF EXISTS www10db;
CREATE DATABASE www10db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE www10db;

CREATE TABLE books (
    id        INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    title     VARCHAR(200)  NOT NULL,
    author    VARCHAR(120)  NOT NULL,
    isbn      VARCHAR(17)   NOT NULL,
    publisher VARCHAR(120)  NOT NULL,
    year      SMALLINT      NOT NULL,
    price     DECIMAL(6,2)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_isbn (isbn)
) ENGINE=InnoDB;

INSERT INTO books (title, author, isbn, publisher, year, price) VALUES
('Pride and Prejudice',      'Jane Austen',          '978-0141439518', 'Penguin Classics',     1813,  9.99),
('Moby-Dick',                'Herman Melville',      '978-0142437247', 'Penguin Classics',     1851, 12.00),
('Great Expectations',       'Charles Dickens',      '978-0141439563', 'Penguin Classics',     1861, 10.50),
('Crime and Punishment',     'Fyodor Dostoevsky',    '978-0143107637', 'Penguin Classics',     1866, 14.00),
('The Adventures of Huckleberry Finn', 'Mark Twain', '978-0143107323', 'Penguin Classics',     1884,  8.95),
('The Great Gatsby',         'F. Scott Fitzgerald',  '978-0743273565', 'Scribner',             1925, 15.00),
('Nineteen Eighty-Four',     'George Orwell',        '978-0451524935', 'Signet Classics',      1949,  9.99),
('To Kill a Mockingbird',    'Harper Lee',           '978-0060935467', 'Harper Perennial',     1960, 16.99);

-- Give the course's web user access to this database only.
CREATE USER IF NOT EXISTS 'testuser'@'localhost' IDENTIFIED BY 'mypassword';
GRANT SELECT, INSERT, UPDATE, DELETE ON www10db.* TO 'testuser'@'localhost';
FLUSH PRIVILEGES;
