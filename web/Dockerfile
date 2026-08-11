# SchoolPilot web admin — production image.
#
# Built on Next's `output: 'standalone'` tracing (see next.config.ts): the
# builder works out which files each route actually needs and emits a server
# plus a pruned node_modules, so the runtime stage ships neither the npm cache
# nor the dev dependencies.

# ---------------------------------------------------------------------------
FROM node:20-alpine AS deps

WORKDIR /app

# Lockfile first: dependencies only rebuild when the lockfile changes, not on
# every source edit.
COPY package.json package-lock.json ./
RUN npm ci

# ---------------------------------------------------------------------------
FROM node:20-alpine AS builder

WORKDIR /app

COPY --from=deps /app/node_modules ./node_modules
COPY . .

ENV NEXT_TELEMETRY_DISABLED=1

RUN npm run build

# ---------------------------------------------------------------------------
FROM node:20-alpine AS runner

WORKDIR /app

ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV PORT=3000
ENV HOSTNAME=0.0.0.0

# Do not run the server as root.
RUN addgroup -g 1001 -S nodejs \
    && adduser -u 1001 -S nextjs -G nodejs

COPY --from=builder /app/public ./public

# standalone/ carries server.js and the traced node_modules. static/ and
# public/ are deliberately not copied into it by the build — the docs are
# explicit that you place them yourself, on the assumption most deployments put
# them behind a CDN. There is no CDN here, so the Node server serves them.
COPY --from=builder --chown=nextjs:nodejs /app/.next/standalone ./
COPY --from=builder --chown=nextjs:nodejs /app/.next/static ./.next/static

USER nextjs

EXPOSE 3000

CMD ["node", "server.js"]
