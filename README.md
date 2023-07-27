This is a Docker Compose file which starts up a standalone Freegle system, typically for development.

First, add `apiv1` and `apiv2` to your hosts file, resolving to `127.0.0.1`.

Then start the system::

`docker-compose up --build`

This takes a few minutes to settle down.

Then you can access:
* Freegle: http://localhost:8080 (to view the user site).  Log in as `test@test.com` / `freegle`, or register.
* ModTools: http://localhost:8082/modtools (to view the moderator site).  Log in as `testmod@test.com` / `freegle`
* PhpMyAdmin: http://localhost:8081 (to view or tweak the database)
* Mailhog: http://localhost:8025/ (to view emails sent by the system; TODO none actually sent yet)

This pulls up to date code from git each time you start.