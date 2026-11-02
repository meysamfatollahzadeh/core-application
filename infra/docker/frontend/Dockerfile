FROM node:24.22.1-bookworm-slim@sha256:be8204d4bd327363357f532187b752ff62759c31bedf98e9e18fc08c9cbd605c
WORKDIR /opt/dependencies
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --ignore-scripts --no-fund --no-audit
WORKDIR /app
COPY infra/docker/frontend/entrypoint.sh /usr/local/bin/development-entrypoint
ENTRYPOINT ["development-entrypoint"]
CMD ["npm", "run", "dev"]
