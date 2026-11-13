# Pin the multi-platform index, allowing Docker to select amd64 or arm64.
FROM mariadb:10.11@sha256:d769e37960821dfdcf096954f6e7ac89f747e116c1bba98e48edd265dfc7deae
COPY artwork/sql/art-small.sql /docker-entrypoint-initdb.d/01-art.sql
COPY artwork/sql/02-art-users.sql /docker-entrypoint-initdb.d/02-art-users.sql
COPY artwork/sql/03-samples.sql /docker-entrypoint-initdb.d/03-samples.sql
COPY docker-grants.sql /docker-entrypoint-initdb.d/04-grants.sql
COPY artwork/sql/05-subject-identities.sql /docker-entrypoint-initdb.d/05-subject-identities.sql
COPY artwork/sql/06-era-identities.sql /docker-entrypoint-initdb.d/06-era-identities.sql
COPY artwork/sql/07-subject-names.sql /docker-entrypoint-initdb.d/07-subject-names.sql
COPY artwork/sql/08-account-roles.sql /docker-entrypoint-initdb.d/08-account-roles.sql
COPY artwork/sql/09-editor-grants.sql /docker-entrypoint-initdb.d/09-editor-grants.sql
COPY artwork/sql/10-saved-works.sql /docker-entrypoint-initdb.d/10-saved-works.sql
COPY artwork/sql/07-subject-names-down.sql /opt/gallery-migrations/07-subject-names-down.sql
COPY artwork/sql/10-saved-works-down.sql /opt/gallery-migrations/10-saved-works-down.sql
