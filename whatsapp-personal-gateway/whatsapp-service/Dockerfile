
FROM node:24-bookworm-slim

ENV NODE_ENV=production

WORKDIR /opt/whatsapp-service

# Git est nécessaire pour installer certaines dépendances npm.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY whatsapp-service/package*.json ./

RUN npm install --omit=dev \
    && npm cache clean --force \
    && apt-get purge -y git \
    && apt-get autoremove -y

COPY whatsapp-service/src ./src

EXPOSE 3000

CMD ["node", "src/server.js"]
