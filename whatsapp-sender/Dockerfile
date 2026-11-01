# The WhatsApp sender on the server (deploy/docker-compose.yml): Node.js with Debian's Chromium, which
# whatsapp-web.js drives. The linked session and the message queue live in /data, a volume that outlasts deploys.
FROM node:22-bookworm-slim

RUN apt-get update \
 && apt-get install -y --no-install-recommends chromium ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Use Debian's Chromium instead of downloading another browser with the libraries.
ENV PUPPETEER_SKIP_DOWNLOAD=true
WORKDIR /sender
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY server.js ./
RUN mkdir -p /data && chown node:node /data

USER node
EXPOSE 3100
# Chromium locks the saved session to the container it runs in. After a crash, a reboot or a new container that
# lock names a machine that's gone and Chromium refuses to start; only one sender runs, so clear it first.
CMD ["sh", "-c", "rm -f /data/session/session/Singleton* && exec node server.js"]
