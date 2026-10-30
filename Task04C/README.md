# CS 4485 Task04C

Phuc Tran · Team 43 · pmt220000

Branch: `Task04C-Phuc`.

| Part | What it is | Folder | Status |
| --- | --- | --- | --- |
| 1 | www10: books table, view, add, delete | `www10` | Done |
| 2 | www13: login against a users table | `www13` | Done |
| 3 | `art-main.php`: view artworks (all or by artist) and delete per artwork | `artwork` | Done |
| 3 Bonus | Add an artwork with an ADD button (`artwork.php`) | `artwork` | Done |
| 4 | `art-login.php` customer login with four accounts, lands on `art-main.php` | `artwork` | Done |

The gallery builds on Lab 14 (Exercise 14.11). All database access uses PDO
prepared statements, passwords are bcrypt hashes, every printed value goes
through `htmlspecialchars`, and Add and Delete are POST forms with a CSRF token.

## Folders

| Path | Contents |
| --- | --- |
| `artwork/` | Parts 3, 3 Bonus and 4. Full file list and demo script in [artwork/README.txt](artwork/README.txt) |
| `www10/` | Part 1 books app, with `create-books.sql` |
| `www13/` | Part 2 login app, with `create-users.sql` |
| `docs/screenshots/` | Screenshots for part1, part2 and part3-4 |
| `sql/` | Database exports taken after testing (`art`, `art.users`, `www10db`, `www13db`) |

## Setup with XAMPP (Windows or macOS)

1. Copy `artwork`, `www10` and `www13` into XAMPP's `htdocs`
   (Windows `C:\xampp\htdocs`, macOS `/Applications/XAMPP/xamppfiles/htdocs`).
2. Start **Apache** and **MySQL** in the XAMPP control panel.
3. In phpMyAdmin, import these files in this order:

   | Order | File | What it does |
   | --- | --- | --- |
   | 1 | `artwork/art-small.sql` | Lab 14 art database (319 paintings). Starts with `DROP DATABASE art` |
   | 2 | `artwork/users.sql` | `art.users` with the four gallery customers |
   | 3 | `www10/create-books.sql` | `www10db` with the books table |
   | 4 | `www13/create-users.sql` | `www13db` with the login users |

4. All three apps connect as `testuser` / `mypassword`. If that account does not
   exist yet, run this in the SQL tab:

   ```sql
   CREATE USER IF NOT EXISTS 'testuser'@'localhost' IDENTIFIED BY 'mypassword';
   GRANT ALL PRIVILEGES ON art.*     TO 'testuser'@'localhost';
   GRANT ALL PRIVILEGES ON www10db.* TO 'testuser'@'localhost';
   GRANT ALL PRIVILEGES ON www13db.* TO 'testuser'@'localhost';
   FLUSH PRIVILEGES;
   ```

5. Open http://localhost/artwork/ (log in as `user1` / `pass1`),
   http://localhost/www10/ and http://localhost/www13/.

## Setup without XAMPP

From this `Task04C` folder:

```sh
mysql -u root -p < artwork/art-small.sql
mysql -u root -p < artwork/users.sql
mysql -u root -p < www10/create-books.sql
mysql -u root -p < www13/create-users.sql
php -S localhost:8000 -t .
```

Create `testuser` as in step 4 above, then open http://localhost:8000/artwork/.

## Accounts

| App | User | Password |
| --- | --- | --- |
| artwork | `user1` … `user4` | `pass1` … `pass4` |
| www13 | `alice`, `bob`, `carol` | `alice123`, `bob123`, `carol123` |

The Add Artwork upload needs `artwork/images/art/works/square-medium` to be
writable by the web server. Choosing a picture already on the server needs no
write access.
