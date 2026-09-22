# Replaces the repository's postgres Dockerfile with a self-contained SQLite
# build. libsqlite3-sys is bundled, so the runtime image needs no DB library.
FROM rust:1-bookworm AS builder
WORKDIR /run_dir
COPY . .
# default features = sqlite (see Cargo.toml)
RUN cargo build --release

FROM debian:bookworm-slim
WORKDIR /run_dir
COPY --from=builder /run_dir/target/release/rs-short ./rs-short
COPY --from=builder /run_dir/lists.toml /run_dir/lang.json ./
COPY --from=builder /run_dir/assets ./assets
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh /run_dir/rs-short && mkdir -p /run_dir/db
# SQLite store + generated secrets live here; a named volume keeps them
# across redeploys (the account home is not mounted for this strategy).
VOLUME ["/run_dir/db"]
EXPOSE 8080
ENTRYPOINT ["/entrypoint.sh"]
