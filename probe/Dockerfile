FROM mcr.microsoft.com/playwright:v1.65.1-noble
# OS security fixes published since the base image was built.
ARG OS_UPDATES_STAMP=local
RUN echo "$OS_UPDATES_STAMP" > /dev/null && apt-get update && apt-get upgrade -y --no-install-recommends && rm -rf /var/lib/apt/lists/*
WORKDIR /srv/probe
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts && npm cache clean --force
COPY server.mjs ./
USER pwuser
EXPOSE 3000
CMD ["node", "server.mjs"]
