FROM node:20-alpine

WORKDIR /app

# Copy package files
COPY package.json ./

# Install dependencies
RUN npm install --production

# Copy application code
COPY server.js ./

# Environment variables
ENV PORT=8080
ENV PSEUDONYMIZER_URL=http://pseudonymizer:8080

EXPOSE 8080

CMD ["node", "server.js"]
