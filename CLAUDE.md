- Always restart the status monitor after making changes to its code.
- Remember that the process for checking whether this compose project is working should involve stopping all containers, doing a prune, rebulding and restarting, and monitoring progress using the status container.
- You don't need to rebuild the Freegle or ModTools containers to pick up code fixes - they run nuxt dev which will do that.
- After making changes to the status code, remember to restart the container
- When running in a docker compose environment and making changes, be careful to copy them to the container.

## Image Delivery Service Configuration

The delivery container uses weserv/images. For local development:

- **Custom nginx config**: `delivery-nginx.conf` overrides the default config to allow Docker network access
- **Environment variables**: `USER_SITE` and `IMAGE_BASE_URL` use hostnames (e.g., `http://freegle.localhost:3002`) for browser accessibility
- **Container IPs**: If containers are recreated, update the IP addresses in docker-compose.yml extra_hosts and environment variables

The image delivery service should work with URLs like:
`http://delivery.localhost/?url=http://freegle.localhost:3002/icon.png&w=116&output=png`
- When you create new files, add them to git automatically unless they are temporary for testing.
- Never add specific IP addresses in as extra_hosts in docker-compose config.  That will not work when a rebuild happens.
- Remember if that if you make changes directly to a container, they will be lost on restarte..  Any container changes miust aalso be make locally.