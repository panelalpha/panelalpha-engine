# The admin-seed one-shot: files/panelalpha/ruseon-core/seed, built inside the
# repository's own Go module (it uses internal/models and pkg/storage) with the
# same toolchain and base image as the repository's Dockerfile, and run as the
# same uid, so the data volume stays the server's.
FROM golang:1.26-alpine AS build
WORKDIR /app
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -ldflags="-s -w" -o /seed ./panelalpha/ruseon-core/seed

FROM alpine:3.21
RUN addgroup -g 10001 -S appgroup && \
    adduser -u 10001 -S appuser -G appgroup && \
    mkdir -p /app/data && \
    chown -R appuser:appgroup /app
WORKDIR /app
COPY --from=build /seed /usr/local/bin/panelalpha-seed
USER appuser:appgroup
CMD ["/usr/local/bin/panelalpha-seed"]
