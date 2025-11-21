FROM node:18-slim

WORKDIR /app

# Install docker CLI from official Docker repository (with retry for flaky networks)
RUN apt-get update -o Acquire::Retries=5 && apt-get install -o Acquire::Retries=5 -y \
    ca-certificates \
    curl \
    gnupg \
    && install -m 0755 -d /etc/apt/keyrings \
    && for i in 1 2 3 4 5; do curl -fsSL https://download.docker.com/linux/debian/gpg | gpg --dearmor -o /etc/apt/keyrings/docker.gpg && break || sleep 10; done \
    && chmod a+r /etc/apt/keyrings/docker.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/debian bookworm stable" | tee /etc/apt/sources.list.d/docker.list > /dev/null \
    && apt-get update -o Acquire::Retries=5 \
    && apt-get install -o Acquire::Retries=5 -y docker-ce-cli \
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