This is a Docker Compose file which starts up a standalone Freegle system, typically for development.

First, add `apiv1` and `apiv2` to your hosts file, resolving to `127.0.0.1`.

# Building

Then start the system::

`docker-compose up --build`

This takes a few minutes to settle down.  It pulls up to date code from git each time you start the containers.

# Using

Then you can access:
* [Freegle](http://localhost:8080), the user site.  Log in as `test@test.com` / `freegle`, or register.
* [ModTools](http://localhost:8082/modtools), the moderator site.  Log in as `testmod@test.com` / `freegle`
* [PhpMyAdmin](http://localhost:8081), to view or tweak the database.
* [Mailhog](http://localhost:8025) (to view emails sent by the system; TODO none actually sent yet)

There is a self-signed certificate which your browser may not accept.  If you right click on an image and open in a new tab, then you can use the Advanced option (or similar) to proceed to view the image.  After that the site should load images ok.

# Configuration

The system contains one test group, FreeglePlayground, centered around Edinburgh.  The only recognised postcode is EH3 6SS.

# Rebuilding

If you need to wipe it and build from scratch:

`docker system prune -a`