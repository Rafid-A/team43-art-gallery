Art Gallery - CS 4485 Task04C, Parts (3), (3)-Bonus and (4)
Phuc Tran (pmt220000), Team 43, Prof. Min
==========================================================================

What it does
------------
Part (4)  art-login.php / authenticate.php: customers log in with a user ID and password
          from the users table in the art database. Only logged-in users can use the
          gallery pages; everyone else is sent back to the login page.
Part (3)  art-main.php: (a) view the artworks from the Paintings table (all artworks, 24 per
          page, newest first, or one artist's artworks from the artist list on the left),
          (c) delete an artwork with the red Delete button on each artwork.
Bonus     artwork.php: (b) add an artwork with the ADD button (Add Artwork in the top bar).

Files
-----
art-login.php       Login form (Part 4). Shows "Login failed" for a wrong user ID/password.
authenticate.php    Checks the login: prepared SELECT on users, password_verify(), new
                    session ID, then art-main.php. Failure -> art-login.php?error=1.
logout.php          Ends the session, back to the login page.
index.php           Opens art-login.php (so http://localhost/artwork/ works too).
art-main.php        Main gallery page (Part 3 a + c). Based on Lab 14 Exercise 14.11.
artwork.php         Add Artwork form (Part 3 Bonus).
delete-artwork.php  Deletes one painting (POST) and its rows in PaintingGenres,
                    PaintingSubjects, Reviews and OrderDetails, in one transaction.
auth.php            Session guard included by every protected page.
session.php         Session start + helpers: e() escaping, flash messages, CSRF token.
header.php          Top bar (Art Gallery, Welcome first last, Add Artwork, Logout).
db.php / config.php PDO connection to the art database (testuser / mypassword).
style.css           Page styles on top of Semantic UI.
users.sql           Creates art.users with the 4 users (passwords stored as bcrypt hashes).
art-small.sql       The Lab 14 art database (same file as Lab 14).
semantic/, images/  Semantic UI and the Lab 14 painting pictures
                    (images/art/works/square-medium/<ImageFileName>.jpg).

Users (users table)
-------------------
  user ID  password  first name  last name
  user1    pass1     first1      last1
  user2    pass2     first2      last2
  user3    pass3     first3      last3
  user4    pass4     first4      last4

Setup on XAMPP (for the demo)
-----------------------------
1. Copy this artwork folder to the XAMPP htdocs folder:
     macOS:   /Applications/XAMPP/xamppfiles/htdocs/artwork
     Windows: C:\xampp\htdocs\artwork
2. Start Apache and MySQL in the XAMPP control panel / manager.
3. Open http://localhost/phpmyadmin
   a. Import tab -> art-small.sql -> Go   (creates the art database; it starts with
      DROP DATABASE art, so import it FIRST)
   b. Import tab -> users.sql -> Go       (creates art.users with the 4 users)
   c. If testuser does not exist yet (it was made in Lab 14 Exercise 14.7):
      User accounts -> Add user account: user name testuser, host localhost,
      password mypassword; then Database -> art -> Privileges: ALL PRIVILEGES.
      Or in the SQL tab:
        CREATE USER 'testuser'@'localhost' IDENTIFIED BY 'mypassword';
        GRANT ALL PRIVILEGES ON art.* TO 'testuser'@'localhost';
   (Same from the MySQL monitor: mysql -u root < art-small.sql ; mysql -u root < users.sql)
4. Browse to http://localhost/artwork/art-login.php and log in as user1 / pass1.

The images folder must be writable by Apache for the picture upload on the Add Artwork
page (macOS: chmod -R 777 images/art/works/square-medium if an upload says "could not be
saved"). Adding with a picture already on the server needs no write access.

Demo script
-----------
1. Open art-main.php while logged out -> sent to the login page ("Please log in").
2. Log in with a wrong password -> "Login failed". Log in as user1 / pass1 ->
   art-main.php with "Welcome, first1 last1".
3. View: page through All artworks, click an artist (e.g. Bruegel the Elder, ?id=118).
4. Delete: press Delete on an artwork, confirm -> "Artwork '...' deleted", card gone.
5. Add (Bonus): Add Artwork -> fill Title, Artist, ... -> pick the picture of the artwork
   you just deleted from "Picture already on the server" (or upload a JPEG/PNG) -> ADD ->
   "Artwork '...' added", the new card is marked NEW.
6. Logout -> back to the login page. Log in as user2, user3, user4 (pass2..pass4).

Notes
-----
* The browser should not remember the password: the login form uses autocomplete="off"
  (and autocomplete="new-password" on the password box). If Chrome still offers to save it,
  choose "Never" so every demo login is typed in. Logging out ends the session; restarting
  Apache/the browser also forces a new login.
* Passwords are stored as bcrypt hashes (password_hash), never in plain text.
* Every query is a PDO prepared statement; every value printed on a page is escaped with
  htmlspecialchars(). Delete and Add are POST forms with a per-session CSRF token.
* Deleting an artwork keeps its picture file on the server, so the same picture can be
  used again on the Add Artwork page. Uploaded pictures are cropped/resized to 150 x 150
  with PHP's GD extension (enabled in XAMPP) and named like the Lab 14 files
  (3-digit artist ID + number, e.g. 073030.jpg).
* To reset the gallery to the original data: import art-small.sql, then users.sql again
  (and delete any uploaded pictures that are no longer in the Paintings table).
* Tested with PHP 8.2 (XAMPP) / 8.5 and MariaDB 10.4 (XAMPP), no PHP warnings or notices
  with error_reporting = E_ALL.
