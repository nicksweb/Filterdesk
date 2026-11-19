FROM mcr.microsoft.com/playwright:v1.58.2-noble
WORKDIR /srv/probe
COPY package.json package-lock.json ./
RUN npm ci --omit=dev --ignore-scripts && npm cache clean --force
COPY server.mjs ./
USER pwuser
EXPOSE 3000
CMD ["node", "server.mjs"]
