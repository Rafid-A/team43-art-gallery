# The Art Library · Team 43

A PHP/Apache/MariaDB art gallery for browsing artworks and managing the
existing collection. From the `Task04C` directory, start Docker Desktop and run:

```sh
docker compose up -d --build --wait --wait-timeout 180
```

Open http://localhost:8084/artwork/art-login.php and sign in with `user1` /
`pass1`. The local course accounts `user2` / `pass2` through `user4` /
`pass4` are also available. See [Task04C/README.md](Task04C/README.md) for
requirements, ports, launchers, persistence and manual setup.

The supplied schema has no Era primary key or SubjectID mapping. Era and
Subject writes and new subject assignments remain unavailable until those
identities are established; existing references are preserved.
