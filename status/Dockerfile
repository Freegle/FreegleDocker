FROM ghcr.io/freegle/freegle-base:latest

WORKDIR /app

COPY package.json ./
RUN npm install

COPY . .

EXPOSE 80

CMD ["npm", "start"]
