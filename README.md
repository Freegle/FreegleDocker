This is a Docker Compose file which starts up a standalone Freegle system, typically for development.

First, add `apiv1` and `apiv2` to your hosts file, resolving to `127.0.0.1`.

# Building

Then start the system::

`docker-compose up --build`

This takes a few minutes to settle down.  It pulls up to date code from git each time you start the containers.

# Using

Then you can access:
* [Freegle](http://freegle.localhost), the user site.  Log in as `test@test.com` / `freegle`, or register.
* [ModTools](http://modtools.localhost/modtools), the moderator site.  Log in as `testmod@test.com` / `freegle`
* [PhpMyAdmin](http://phpmyadmin.localhost), to view or tweak the database.
* [Mailhog](http://mailhog.localhost) (to view emails sent by the system; TODO none actually sent yet)

Note that these are all http links; no SSL.  This means that at the moment images won't work in local testing
because they are served over https.

# Configuration

The system contains one test group, FreeglePlayground, centered around Edinburgh.  
The only recognised postcode is EH3 6SS.

# Rebuilding

If you need to wipe it and build from scratch:

`docker system prune -a`

# Google Cloud

You can also deploy this on Google Cloud.

* Get `gcloud` from https://cloud.google.com/sdk/docs/install
* 