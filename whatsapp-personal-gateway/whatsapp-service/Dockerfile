FROM node:24-bookworm-slim

ENV PUPPETEER_SKIP_DOWNLOAD=true
ENV NODE_ENV=production

# Chromium et dépendances nécessaires à Puppeteer/whatsapp-web.js.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
       chromium \
       ca-certificates \
       fonts-liberation \
       libasound2 \
       libatk-bridge2.0-0 \
       libatk1.0-0 \
       libc6 \
       libcairo2 \
       libcups2 \
       libdbus-1-3 \
       libexpat1 \
       libfontconfig1 \
       libgbm1 \
       libgcc-s1 \
       libglib2.0-0 \
       libgtk-3-0 \
       libnspr4 \
       libnss3 \
       libpango-1.0-0 \
       libstdc++6 \
       libx11-6 \
       libx11-xcb1 \
       libxcb1 \
       libxcomposite1 \
       libxdamage1 \
       libxext6 \
       libxfixes3 \
       libxrandr2 \
       xdg-utils \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/whatsapp-service

COPY whatsapp-service/package*.json ./
RUN npm install --omit=dev

COPY whatsapp-service/src ./src

ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
EXPOSE 3000

CMD ["node", "src/server.js"]
