FROM node:24-bookworm-slim

ENV NODE_ENV=production
ENV PORT=3000
WORKDIR /app

COPY --chown=node:node package.json ./
COPY --chown=node:node admin.html admin.js index.html script.js server.js styles.css hero.css hero.js ./
COPY --chown=node:node assets ./assets

RUN mkdir -p /app/data /app/assets/uploads \
    && chown -R node:node /app/data /app/assets/uploads

USER node
EXPOSE 3000
CMD ["npm", "start"]
