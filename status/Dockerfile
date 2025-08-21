FROM node:18-alpine

WORKDIR /app

RUN apk add --no-cache docker-cli docker-compose

COPY package.json ./
RUN npm install

COPY . .

EXPOSE 80

CMD ["npm", "start"]