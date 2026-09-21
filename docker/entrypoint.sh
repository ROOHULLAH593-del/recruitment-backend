#!/bin/sh
#
# Container start-up / deploy script. Runs on every container start (i.e. every
# Render deploy or restart), then hands over to supervisord (nginx + php-fpm).
#
# Why this lives at start-up rather than in the Docker build:
#   * Render only exposes dashboard env vars at runtime, so config:cache must
#     run here, after the environment is available.
#   * The credential files below come from env vars and must never be baked
#     into the image.
#
# Any failure aborts start-up (set -e), so Render marks the deploy as failed
# and keeps serving the previous version instead of running a half-migrated app.

set -eu

APP_DIR=/var/www/html
cd "$APP_DIR"

log() {
    printf '[entrypoint] %s\n' "$*"
}

# materialize <dest> <raw-env-var-name> <base64-env-var-name> <verbatim|pem>
#
# Writes a credential file from an env var. The base64 variant is preferred as
# dashboards mangle multi-line values; the raw variant is accepted too. For
# `pem`, a literal "\n" sequence is turned back into a real newline; JSON is
# written verbatim, since its "\n" escapes (inside the private_key string) must
# stay as-is. Returns 1 if neither variable is set.
materialize() {
    dest="$1"
    raw_var="$2"
    b64_var="$3"
    kind="$4"

    eval "raw=\${${raw_var}:-}"
    eval "b64=\${${b64_var}:-}"

    mkdir -p "$(dirname "$dest")"

    if [ -n "$b64" ]; then
        printf '%s' "$b64" | tr -d '[:space:]' | base64 -d > "$dest"
    elif [ -n "$raw" ]; then
        if [ "$kind" = "pem" ]; then
            printf '%s\n' "$raw" | awk '{ gsub(/\\n/, "\n"); print }' > "$dest"
        else
            printf '%s\n' "$raw" > "$dest"
        fi
    else
        return 1
    fi

    chmod 600 "$dest"
}

# --- 1. Required configuration ------------------------------------------------

if [ -z "${APP_KEY:-}" ]; then
    log "APP_KEY is not set. Generate one with 'php artisan key:generate --show' and add it to the Render environment."
    exit 1
fi

# --- 2. Storage skeleton -------------------------------------------------------
# storage/ may be a freshly mounted persistent disk, and the image ships without
# the framework directories (they are excluded by .dockerignore).

mkdir -p \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# --- 3. Credential files from environment variables ---------------------------

GOOGLE_CALENDAR_DEST="$APP_DIR/storage/${GOOGLE_CALENDAR_CREDENTIALS_PATH:-app/private/google-calendar-service-account.json}"
if materialize "$GOOGLE_CALENDAR_DEST" GOOGLE_CALENDAR_CREDENTIALS_JSON GOOGLE_CALENDAR_CREDENTIALS_BASE64 verbatim; then
    log "Wrote Google Calendar service account credentials."
else
    log "WARNING: no GOOGLE_CALENDAR_CREDENTIALS_JSON/_BASE64 set; interviews will not sync to Google Calendar."
fi

JAAS_DEST="$APP_DIR/storage/${JAAS_PRIVATE_KEY_PATH:-app/private/jaas-private-key.pem}"
if materialize "$JAAS_DEST" JAAS_PRIVATE_KEY JAAS_PRIVATE_KEY_BASE64 pem; then
    log "Wrote JaaS private key."
else
    log "WARNING: no JAAS_PRIVATE_KEY/_BASE64 set; video interview tokens cannot be signed."
fi

# Aiven MySQL only accepts TLS connections; config/database.php picks the CA up
# from MYSQL_ATTR_SSL_CA. A CA certificate is public, so it can be world-readable.
if [ -z "${MYSQL_ATTR_SSL_CA:-}" ]; then
    DB_CA_DEST=/usr/local/share/db-ca.pem
    if materialize "$DB_CA_DEST" MYSQL_SSL_CA_CERT MYSQL_SSL_CA_CERT_BASE64 pem; then
        chmod 644 "$DB_CA_DEST"
        export MYSQL_ATTR_SSL_CA="$DB_CA_DEST"
        log "Wrote database CA certificate."
    else
        log "WARNING: no MYSQL_SSL_CA_CERT/_BASE64 set; connecting to the database without a CA certificate."
    fi
fi

# --- 4. Optimize ----------------------------------------------------------------
# Config is cached first so migrations run against exactly the configuration the
# app will serve with. Routes/events/views don't depend on env vars.

php artisan package:discover --no-interaction
php artisan config:cache --no-interaction

# --- 5. Migrate -----------------------------------------------------------------
# Retried a few times: a managed database can take a moment to accept
# connections (e.g. waking from idle) right when a deploy starts.

attempt=1
max_attempts=5
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge "$max_attempts" ]; then
        log "Migrations still failing after $max_attempts attempts; aborting start-up."
        exit 1
    fi
    log "Migration attempt $attempt failed; retrying in 5s..."
    attempt=$((attempt + 1))
    sleep 5
done

php artisan route:cache --no-interaction
php artisan event:cache --no-interaction
php artisan view:cache --no-interaction

# --- 6. Permissions ---------------------------------------------------------------
# Artisan ran as root; PHP-FPM workers run as www-data and must own everything
# they write to (sessions, cache, logs, uploaded documents) and be able to read
# the credential files written above.

chown -R www-data:www-data storage bootstrap/cache

# --- 7. Web server ------------------------------------------------------------------
# Render tells the container which port to listen on via $PORT (default 10000).

sed "s/__PORT__/${PORT:-10000}/" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
nginx -t

log "Starting nginx + php-fpm on port ${PORT:-10000}."
exec /usr/bin/supervisord --nodaemon --configuration /etc/supervisord.conf
