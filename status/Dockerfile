FROM node:18-slim

WORKDIR /app

# Copy retry script for flaky network operations
COPY --from=scripts retry.sh /usr/local/bin/retry
RUN chmod +x /usr/local/bin/retry

# Install docker CLI from official Docker repository (with retry for flaky networks including DNS)
RUN retry bash -c 'apt-get update && apt-get install -y ca-certificates curl gnupg' \
    && install -m 0755 -d /etc/apt/keyrings \
    && retry bash -c 'curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg' \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && retry bash -c 'apt-get update && apt-get install -y docker-ce-cli' \
    && rm -rf /var/lib/apt/lists/*

COPY package.json ./

# Configure npm retries for flaky networks
RUN npm config set fetch-retries 5 && \
    npm config set fetch-retry-mintimeout 20000 && \
    npm config set fetch-retry-maxtimeout 120000 && \
    npm install

COPY . .

EXPOSE 80

CMD ["npm", "start"]
