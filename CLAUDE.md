- Always restart the status monitor after making changes to its code.
- Remember that the process for checking whether this compose project is working should involve stopping all containers, doing a prune, rebulding and restarting, and monitoring progress using the status container.
- You don't need to rebuild the Freegle or ModTools containers to pick up code fixes - they run nuxt dev which will do that.
- After making changes to the status code, remember to restart the container
- When running in a docker compose environment and making changes, be careful to copy them to the container.

## Image Delivery Service Configuration

The delivery container uses weserv/images which has SSRF protection by default. For local development:

- **Custom nginx config**: `delivery-nginx.conf` overrides the default config to allow Docker network access
- **Environment variables**: `USER_SITE` and `IMAGE_BASE_URL` use direct IP addresses (e.g., `http://172.18.0.15:3002`) instead of hostnames
- **SSRF Protection**: Disabled for development by commenting out private IP blocks in the nginx config
- **Container IPs**: If containers are recreated, update the IP addresses in docker-compose.yml extra_hosts and environment variables

The image delivery service should work with URLs like:
`http://delivery.localhost/?url=http://172.18.0.15:3002/icon.png&w=116&output=png`
- When you create new files, add them to git automatically unless they are temporary for testing.