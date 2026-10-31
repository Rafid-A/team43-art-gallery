# Pin the multi-platform index, allowing Docker to select amd64 or arm64.
FROM mariadb:10.11@sha256:d769e37960821dfdcf096954f6e7ac89f747e116c1bba98e48edd265dfc7deae
COPY artwork/sql/art-small.sql /docker-entrypoint-initdb.d/01-art.sql
COPY artwork/sql/02-art-users.sql /docker-entrypoint-initdb.d/02-art-users.sql
COPY artwork/sql/03-samples.sql /docker-entrypoint-initdb.d/03-samples.sql
COPY docker-grants.sql /docker-entrypoint-initdb.d/04-grants.sql
