<?php
define('DBHOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DBPORT', getenv('DB_PORT') ?: '3306');
define('DBNAME', getenv('DB_NAME') ?: 'art');
define('DBUSER', getenv('DB_USER') ?: 'testuser');
define('DBPASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'mypassword');
