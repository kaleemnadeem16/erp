# syntax=docker/dockerfile:1
#
# Production only — dev runs via the plain `node:22-alpine` image directly (see the `node`
# service in docker-compose.yml); a static SPA has no need for a separate custom dev image.

# ---------------------------------------------------------------------------
# build — has npm/node, discarded after this stage; only its output (apps/main/dist) ships.
FROM node:22-alpine AS build

WORKDIR /app
COPY frontend/ .

# Vite inlines env vars at build time, so the API origin is baked into the
# bundle here and cannot be changed by the runtime container. Without it the
# build falls back to the dev URL in apps/main/src/main.tsx and the deployed
# SPA calls localhost. The CI build passes this as a build-arg; there is no
# default on purpose, so a build that forgets it fails loudly.
ARG VITE_API_BASE_URL
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
RUN test -n "$VITE_API_BASE_URL" || (echo 'VITE_API_BASE_URL build-arg is required' >&2; exit 1)
RUN npm ci && npm run build --workspace apps/main

# ---------------------------------------------------------------------------
# production — nginx serving the built static files. No Node at runtime.
# nginx:1.27-alpine, tag-pinned to match the dev `node` service's nginx version
# (docker-compose.yml) — not index-digest-pinned for invoiceflow/crm layer-sharing like the
# backend's PHP base is (docs/architecture/environments.md#image-layer-sharing); revisit if
# that turns out to matter here too.
FROM nginx:1.27-alpine AS production

COPY --from=build /app/apps/main/dist /usr/share/nginx/html
COPY docker/frontend/nginx.conf /etc/nginx/nginx.conf

EXPOSE 80
