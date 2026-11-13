
FROM node:24-bookworm-slim

ENV NODE_ENV=production

WORKDIR /opt/whatsapp-service

COPY whatsapp-service/package*.json ./

RUN npm install --omit=dev \
    && npm cache clean --force

COPY whatsapp-service/src ./src

EXPOSE 3000

CMD ["node", "src/server.js"]
