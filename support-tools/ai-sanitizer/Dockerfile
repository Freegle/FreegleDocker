FROM node:20-alpine

# Install wget for healthcheck and build tools for better-sqlite3
RUN apk add --no-cache wget python3 make g++

WORKDIR /app

# Copy package files
COPY package.json ./

# Install dependencies
RUN npm install --production

# Copy application code
COPY server.js ./

# Environment variables
ENV PORT=8080
ENV LOKI_URL=http://loki:3100
ENV DATA_DIR=/data
ENV AUDIT_LOG_DIR=/var/log/mcp-audit
ENV DB_HOST=freegle-percona
ENV DB_PORT=3306
ENV DB_USER=root
ENV DB_PASSWORD=iznik
ENV DB_NAME=iznik

# Create data directories
RUN mkdir -p /data /var/log/mcp-audit

EXPOSE 8080

CMD ["node", "server.js"]
