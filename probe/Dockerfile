FROM mcr.microsoft.com/playwright:v1.65.1-noble
# OS security fixes published since the base image was built.
ARG OS_UPDATES_STAMP=local
# Ubuntu's mirrors are sometimes mid-sync (a 404 or "unexpected size"): retry downloads, and the whole step a few times.
RUN echo "$OS_UPDATES_STAMP" > /dev/null \
    && for attempt in 1 2 3 4; do \
         apt-get -o Acquire::Retries=5 update && apt-get -o Acquire::Retries=5 upgrade -y --no-install-recommends && break; \
         [ "$attempt" = 4 ] && exit 1; echo "apt failed, retrying in $((attempt * 30))s"; sleep $((attempt * 30)); \
       done \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /srv/probe
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts && npm cache clean --force
COPY server.mjs ./
USER pwuser
EXPOSE 3000
CMD ["node", "server.mjs"]
