#!/usr/bin/env bash
# Verifica S2 su WordPress e Yoast isolati in Docker (sandbox ai-bus). Uso: bash tests/s2-wordpress.sh
# Rete interna senza porte esposte, DB in tmpfs, immagini locali senza pull (wordpress:php8.4-apache,
# wordpress:cli-php8.4, mariadb:10.11), Yoast 28.9 scaricato dal repository ufficiale fuori dalla rete interna.
# Risorse etichettate con ai-bus.sandbox=<id sandbox>, rimosse nel trap di uscita, anche su errore: se la pulizia
# fallisce o la sandbox resta su disco, lo script esce con rc non zero.
# Finestre misurate: no-yoast, missing (prima di yoast index), current (dopo), obsolete (stato preparato dopo la
# misura corrente). Ogni finestra verifica zero scritture SQL e digest invariati (tests/s2-wp-window.php).
set -euo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
AI_BUS="$ROOT/.ai/bin/ai-bus"
export AI_BUS_SANDBOX_ROOT="$ROOT/.ai/sandbox"
YOAST_URL='https://downloads.wordpress.org/plugin/wordpress-seo.28.9.zip'
YOAST_SHA256='1f1af9d3dff70288b37e6edc04e27cff4979b258f62960c0b91c092614bf2301'
WP_IMG='wordpress:php8.4-apache'
CLI_IMG='wordpress:cli-php8.4'
DB_IMG='mariadb:10.11'
SITE_HOST='seolab-web'

for image in "$WP_IMG" "$CLI_IMG" "$DB_IMG"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        echo "immagine locale mancante: $image (nessun pull eseguito)" >&2
        exit 2
    fi
done

SANDBOX="$("$AI_BUS" sandbox new --by EXECUTOR --purpose 'S2 WordPress e Yoast isolati' --quota-mb 800)"
SID="$(basename -- "$SANDBOX")"
SLUG="$(printf '%s' "$SID" | tr -c 'A-Za-z0-9' '-' | tr 'A-Z' 'a-z')"
NET="s2-$SLUG-net"
DB_NAME="s2-$SLUG-db"
WEB_NAME="s2-$SLUG-web"
VOL="s2-$SLUG-wp"

cleanup() {
    local rc=$?
    local clean_failed=0
    local ids
    ids="$(docker ps -aq --filter "label=ai-bus.sandbox=$SID")" || clean_failed=1
    if [ -n "$ids" ]; then
        printf '%s\n' "$ids" | xargs docker rm -f >/dev/null || clean_failed=1
    fi
    ids="$(docker network ls -q --filter "label=ai-bus.sandbox=$SID")" || clean_failed=1
    if [ -n "$ids" ]; then
        printf '%s\n' "$ids" | xargs docker network rm >/dev/null || clean_failed=1
    fi
    ids="$(docker volume ls -q --filter "label=ai-bus.sandbox=$SID")" || clean_failed=1
    if [ -n "$ids" ]; then
        printf '%s\n' "$ids" | xargs docker volume rm >/dev/null || clean_failed=1
    fi
    # Output grezzi delle fasi copiati prima della rimozione della sandbox: restano anche se lo script esce prima.
    if [ -d "${OUT_DIR:-/nonexistente}" ]; then
        mkdir -p "$ROOT/.ai/20_execution/s2-wp-out" && cp -a "$OUT_DIR/." "$ROOT/.ai/20_execution/s2-wp-out/" || clean_failed=1
    fi
    "$AI_BUS" sandbox clean --id "$SID" >/dev/null || clean_failed=1
    if [ -e "$SANDBOX" ]; then
        echo "sandbox ancora presente dopo la pulizia: $SANDBOX" >&2
        clean_failed=1
    fi
    if [ "$clean_failed" -ne 0 ] && [ "$rc" -eq 0 ]; then
        rc=1
    fi
    echo "pulizia sandbox completata (exit $rc, pulizia_fallita=$clean_failed)"
    exit "$rc"
}
trap cleanup EXIT

mkdir -p "$SANDBOX/yoast"
curl -fsSL --max-time 180 -o "$SANDBOX/yoast/wordpress-seo.zip" "$YOAST_URL"
YOAST_GOT="$(sha256sum "$SANDBOX/yoast/wordpress-seo.zip" | cut -d' ' -f1)"
if [ "$YOAST_GOT" != "$YOAST_SHA256" ]; then
    echo "sha256 Yoast inatteso: $YOAST_GOT" >&2
    exit 1
fi

DB_PASS="$(openssl rand -hex 16)"
DB_ROOT="$(openssl rand -hex 16)"
ADMIN_PASS="$(openssl rand -hex 16)"

docker network create --internal --label "ai-bus.sandbox=$SID" "$NET" >/dev/null
docker run -d --pull never --name "$DB_NAME" --network "$NET" --network-alias seolab-db --label "ai-bus.sandbox=$SID" \
    --tmpfs /var/lib/mysql \
    -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD="$DB_PASS" -e MARIADB_ROOT_PASSWORD="$DB_ROOT" \
    "$DB_IMG" >/dev/null
docker volume create --label "ai-bus.sandbox=$SID" "$VOL" >/dev/null
docker run -d --pull never --name "$WEB_NAME" --network "$NET" --network-alias "$SITE_HOST" --label "ai-bus.sandbox=$SID" \
    -v "$VOL:/var/www/html" \
    -e WORDPRESS_DB_HOST=seolab-db -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
    -e WP_ENVIRONMENT_TYPE=production \
    "$WP_IMG" >/dev/null

wait_for() {
    local tries=0
    until "$@" >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -gt 90 ]; then
            echo "timeout in attesa: $*" >&2
            return 1
        fi
        sleep 2
    done
}

wait_for docker exec -e MYSQL_PWD="$DB_PASS" "$DB_NAME" mariadb -uwp wp -e 'SELECT 1'
wait_for docker exec "$WEB_NAME" test -f /var/www/html/wp-config.php

wpcli() {
    docker run --rm --pull never --network "$NET" --label "ai-bus.sandbox=$SID" --user 33:33 \
        -v "$VOL:/var/www/html" \
        -v "$SANDBOX/yoast:/yoast:ro" \
        -v "$ROOT/src:/seolab/src:ro" \
        -v "$ROOT/tests:/seolab/tests:ro" \
        -e WORDPRESS_DB_HOST=seolab-db -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
        -e WP_ENVIRONMENT_TYPE=production \
        -e S2_PHASE -e S2_STATE -e S2_POST_ID -e S2_PROBE_URL \
        "$CLI_IMG" wp "$@"
}

wpcli core install --url="http://$SITE_HOST" --title='SEO Lab S2 sandbox' \
    --admin_user=s2admin --admin_password="$ADMIN_PASS" --admin_email=s2-sandbox@example.invalid --skip-email >/dev/null

POST_ID="$(wpcli post create --post_type=page --post_status=publish \
    --post_title='Pagina S2 sintetica' --post_content='<p>Contenuto sintetico di verifica</p>' --porcelain)"

echo "Versioni: WordPress $(wpcli core version) · PHP $(wpcli eval 'echo PHP_VERSION;')"
echo "Immagini: WP $(docker image inspect --format '{{.Id}}' "$WP_IMG") · CLI $(docker image inspect --format '{{.Id}}' "$CLI_IMG") · DB $(docker image inspect --format '{{.Id}}' "$DB_IMG")"

OUT_DIR="$SANDBOX/out"
mkdir -p "$OUT_DIR"
FAILED=0

# Fase 1: nessun Yoast. Sonda di stato e finestra misurata.
S2_PHASE=no-yoast S2_POST_ID="$POST_ID" S2_PROBE_URL='' \
    wpcli eval-file /seolab/tests/s2-wp-check.php > "$OUT_DIR/check-no-yoast.txt" 2>&1 || true
echo "--- fase senza Yoast ---"
head -n 6 "$OUT_DIR/check-no-yoast.txt"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/check-no-yoast.txt" || FAILED=1
S2_STATE=no-yoast S2_POST_ID="$POST_ID" \
    wpcli eval-file /seolab/tests/s2-wp-window.php > "$OUT_DIR/window-no-yoast.txt" 2>&1 || true
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/window-no-yoast.txt" || FAILED=1

wpcli plugin install /yoast/wordpress-seo.zip --activate >/dev/null
echo "Yoast $(wpcli plugin get wordpress-seo --field=version) attivo nella sola sandbox"

# Fase 2: indexable assente (prima di yoast index). Precondizione: nessuna riga per il post.
S2_STATE=missing S2_POST_ID="$POST_ID" \
    wpcli eval-file /seolab/tests/s2-wp-window.php > "$OUT_DIR/window-missing.txt" 2>&1 || true
echo "--- finestra: indexable assente ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/window-missing.txt" || FAILED=1

wpcli yoast index --skip-confirmation --interval=0 >/dev/null

# Fase 3: indexable corrente. Precondizione: una riga, versione corrente.
S2_STATE=current S2_POST_ID="$POST_ID" \
    wpcli eval-file /seolab/tests/s2-wp-window.php > "$OUT_DIR/window-current.txt" 2>&1 || true
echo "--- finestra: indexable corrente ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/window-current.txt" || FAILED=1

# Confronto admin/frontend: sonda separata, fuori dalla finestra di sola lettura.
S2_PHASE=yoast S2_POST_ID="$POST_ID" S2_PROBE_URL="http://$SITE_HOST/?p=$POST_ID" \
    wpcli eval-file /seolab/tests/s2-wp-check.php > "$OUT_DIR/check-yoast.txt" 2>&1 || true
echo "--- fase con Yoast: admin e frontend ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/check-yoast.txt" || FAILED=1

# Fase 4 (S4): contesto admin con indexable corrente. Finestra misurata e JSON-LD letto dentro la finestra.
S2_STATE=current S2_POST_ID="$POST_ID" \
    wpcli --context=admin eval-file /seolab/tests/s2-wp-window.php > "$OUT_DIR/window-current-admin.txt" 2>&1 || true
echo "--- finestra: indexable corrente, contesto admin ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/window-current-admin.txt" || FAILED=1

# Fase 4b (S4): admin e frontend dello stesso post, sonda fuori dalla finestra provider.
S2_PHASE=yoast-admin S2_POST_ID="$POST_ID" S2_PROBE_URL="http://$SITE_HOST/?p=$POST_ID" \
    wpcli --context=admin eval-file /seolab/tests/s2-wp-check.php > "$OUT_DIR/check-yoast-admin.txt" 2>&1 || true
echo "--- fase con Yoast: admin, JSON-LD e frontend ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/check-yoast-admin.txt" || FAILED=1

# Fase 4c (S4): enable_schema=false in admin. Preparazione e ripristino fuori dalle finestre misurate.
S2_POST_ID="$POST_ID" wpcli --context=admin eval 'YoastSEO()->helpers->options->set("enable_schema", false); exit(YoastSEO()->helpers->options->get("enable_schema", true) === false ? 0 : 1);' || FAILED=1
S2_PHASE=schema-off-admin S2_POST_ID="$POST_ID" S2_PROBE_URL='' \
    wpcli --context=admin eval-file /seolab/tests/s2-wp-check.php > "$OUT_DIR/check-schema-off-admin.txt" 2>&1 || true
echo "--- fase con schema disattivato in admin ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/check-schema-off-admin.txt" || FAILED=1
wpcli --context=admin eval 'YoastSEO()->helpers->options->set("enable_schema", true); exit(YoastSEO()->helpers->options->get("enable_schema", true) === true ? 0 : 1);' || FAILED=1

# Fase 5: indexable obsoleto. Preparazione fuori dalla finestra, dopo le misure corrente e admin/frontend.
S2_POST_ID="$POST_ID" wpcli eval 'global $wpdb; exit($wpdb->query($wpdb->prepare("UPDATE {$wpdb->prefix}yoast_indexable SET version = %d WHERE object_id = %d AND object_type = %s", 0, (int) getenv("S2_POST_ID"), "post")) === 1 ? 0 : 1);' || FAILED=1
S2_STATE=obsolete S2_POST_ID="$POST_ID" \
    wpcli eval-file /seolab/tests/s2-wp-window.php > "$OUT_DIR/window-obsolete.txt" 2>&1 || true
echo "--- finestra: indexable obsoleto ---"
php "$ROOT/tests/s2-wp-compare.php" "$OUT_DIR/window-obsolete.txt" || FAILED=1

EVIDENCE="$ROOT/.ai/20_execution"
mkdir -p "$EVIDENCE"
for name in check-no-yoast window-no-yoast window-missing window-current check-yoast window-current-admin check-yoast-admin check-schema-off-admin window-obsolete; do
    cp "$OUT_DIR/$name.txt" "$EVIDENCE/s2-wp-$name-output.txt"
done
echo "Output grezzi copiati in $EVIDENCE"
exit "$FAILED"
