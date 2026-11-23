#!/usr/bin/env bash
# Verifica S4 su WordPress multisite isolato in Docker (sandbox ai-bus). Uso: bash tests/s4-wordpress.sh
# Rete interna senza porte esposte, DB in tmpfs, immagini locali senza pull (wordpress:php8.4-apache,
# wordpress:cli-php8.4, mariadb:10.11), Yoast 28.9 scaricato dal repository ufficiale fuori dalla rete interna.
# Client curl nella stessa rete per login, nonce e POST reali verso admin-post.php. Due siti: A (radice) e B
# (sitio-b). Admin del solo sito A e admin del solo sito B. Il caso "ok" di cattura non e' provabile qui: la rete
# interna non risolve example.com; quel caso e' coperto dal runner offline tests/s4-report.php (N-5).
# Risorse etichettate con ai-bus.sandbox=<id sandbox>, rimosse nel trap di uscita anche su errore.
set -uo pipefail

# --release-zip <file>: installa il pacchetto ZIP (sola lettura) invece di montare il checkout; esegue anche P-005.
# --p1-ai: revisione AI privata P1.2 sul checkout, con connettore sintetico nella sola sandbox; esegue anche P-005.
RELEASE_ZIP=''
P1_AI=0
while [ "$#" -gt 0 ]; do
    case "$1" in
        --release-zip)
            [ "$#" -ge 2 ] || { echo '--release-zip richiede un file' >&2; exit 2; }
            RELEASE_ZIP="$2"
            shift 2
            ;;
        --p1-ai)
            P1_AI=1
            shift
            ;;
        *)
            echo "argomento non valido: $1" >&2
            exit 2
            ;;
    esac
done
if [ "$P1_AI" = 1 ] && [ -n "$RELEASE_ZIP" ]; then
    echo '--p1-ai non si combina con --release-zip: usa il checkout' >&2
    exit 2
fi
if [ "$P1_AI" = 1 ]; then
    # Yoast non e' installato in --p1-ai: le sentinelle wpseo_* del passo P-005 non si applicano.
    export S5_SKIP_YOAST=1
fi

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
if [ -n "$RELEASE_ZIP" ]; then
    [ -f "$RELEASE_ZIP" ] || { echo "ZIP non trovato: $RELEASE_ZIP" >&2; exit 2; }
    RELEASE_ZIP="$(cd -- "$(dirname -- "$RELEASE_ZIP")" && pwd)/$(basename -- "$RELEASE_ZIP")"
fi
AI_BUS="$ROOT/.ai/bin/ai-bus"
export AI_BUS_SANDBOX_ROOT="$ROOT/.ai/sandbox"
YOAST_URL='https://downloads.wordpress.org/plugin/wordpress-seo.28.9.zip'
YOAST_SHA256='1f1af9d3dff70288b37e6edc04e27cff4979b258f62960c0b91c092614bf2301'
WP_IMG='wordpress:php8.4-apache'
CLI_IMG='wordpress:cli-php8.4'
DB_IMG='mariadb:10.11'
SITE_HOST='seolab-web'
BASE_A="http://$SITE_HOST"
BASE_B="http://$SITE_HOST/sitio-b"
FAILED=0
FAILS=0
PASSED=0

for image in "$WP_IMG" "$CLI_IMG" "$DB_IMG"; do
    if ! docker image inspect "$image" >/dev/null 2>&1; then
        echo "immagine locale mancante: $image (nessun pull eseguito)" >&2
        exit 2
    fi
done

SANDBOX="$("$AI_BUS" sandbox new --by EXECUTOR --purpose 'S4 WordPress multisite e archivio report' --quota-mb 800)" || exit 2
SID="$(basename -- "$SANDBOX")"
SLUG="$(printf '%s' "$SID" | tr -c 'A-Za-z0-9' '-' | tr 'A-Z' 'a-z')"
NET="s4-$SLUG-net"
DB_NAME="s4-$SLUG-db"
WEB_NAME="s4-$SLUG-web"
CLIENT="s4-$SLUG-client"
VOL="s4-$SLUG-wp"
PLUGIN_DIR='/var/www/html/wp-content/plugins/seo-lab'

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

check() {
    # check <nome> <yes|no>
    if [ "$2" = yes ]; then
        PASSED=$((PASSED + 1))
        echo "PASS $1"
    else
        FAILED=1
        FAILS=$((FAILS + 1))
        echo "FAIL $1"
    fi
}

if [ "$P1_AI" != 1 ]; then
    mkdir -p "$SANDBOX/yoast"
    curl -fsSL --max-time 180 -o "$SANDBOX/yoast/wordpress-seo.zip" "$YOAST_URL" || exit 1
    YOAST_GOT="$(sha256sum "$SANDBOX/yoast/wordpress-seo.zip" | cut -d' ' -f1)"
    if [ "$YOAST_GOT" != "$YOAST_SHA256" ]; then
        echo "sha256 Yoast inatteso: $YOAST_GOT" >&2
        exit 1
    fi
fi

DB_PASS="$(openssl rand -hex 16)"
DB_ROOT="$(openssl rand -hex 16)"
ROOT_PASS="$(openssl rand -hex 16)"
PASS_A="$(openssl rand -hex 16)"
PASS_B="$(openssl rand -hex 16)"

PLUGIN_MOUNTS=(
    -v "$ROOT/seo-lab.php:$PLUGIN_DIR/seo-lab.php:ro"
    -v "$ROOT/src:$PLUGIN_DIR/src:ro"
    -v "$ROOT/languages:$PLUGIN_DIR/languages:ro"
)
if [ "$P1_AI" = 1 ]; then
    # P1.2: il drop e la pulizia delle opzioni AI passano dal uninstall.php reale del checkout.
    PLUGIN_MOUNTS+=(-v "$ROOT/uninstall.php:$PLUGIN_DIR/uninstall.php:ro")
fi
ZIP_MOUNTS=()
if [ -n "$RELEASE_ZIP" ]; then
    # Modalita' ZIP: nessun bind-mount del checkout; il plugin viene installato dal pacchetto nel volume.
    PLUGIN_MOUNTS=()
    ZIP_MOUNTS=(-v "$RELEASE_ZIP:/zip/seo-lab.zip:ro")
fi

docker network create --internal --label "ai-bus.sandbox=$SID" "$NET" >/dev/null || exit 1
docker run -d --pull never --name "$DB_NAME" --network "$NET" --network-alias seolab-db --label "ai-bus.sandbox=$SID" \
    --tmpfs /var/lib/mysql \
    -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD="$DB_PASS" -e MARIADB_ROOT_PASSWORD="$DB_ROOT" \
    "$DB_IMG" >/dev/null || exit 1
docker volume create --label "ai-bus.sandbox=$SID" "$VOL" >/dev/null || exit 1
docker run -d --pull never --name "$WEB_NAME" --network "$NET" --network-alias "$SITE_HOST" --label "ai-bus.sandbox=$SID" \
    -v "$VOL:/var/www/html" "${PLUGIN_MOUNTS[@]}" \
    -e WORDPRESS_DB_HOST=seolab-db -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
    -e WP_ENVIRONMENT_TYPE=production \
    -e WORDPRESS_DEBUG=1 -e "WORDPRESS_CONFIG_EXTRA=define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);" \
    "$WP_IMG" >/dev/null || exit 1
docker run -d --pull never --name "$CLIENT" --network "$NET" --label "ai-bus.sandbox=$SID" \
    --entrypoint sh "$CLI_IMG" -c 'sleep 7200' >/dev/null || exit 1

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
        -v "$VOL:/var/www/html" "${PLUGIN_MOUNTS[@]}" "${ZIP_MOUNTS[@]}" \
        -v "$SANDBOX/yoast:/yoast:ro" \
        -v "$ROOT/tests:/seolab/tests:ro" \
        -e WORDPRESS_DB_HOST=seolab-db -e WORDPRESS_DB_NAME=wp -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD="$DB_PASS" \
        -e WP_ENVIRONMENT_TYPE=production \
        -e S4_POST_ID -e S4_OTHER_BLOG -e S4_SQL -e S4_ADMIN_LOGIN -e S5_STEP -e S5_TABLE_BLOGS -e P1_POST_ID -e S5_SKIP_YOAST \
        "$CLI_IMG" wp "$@"
}

# Client HTTP nella rete interna: nessuna porta pubblicata.
cl() {
    docker exec "$CLIENT" "$@"
}

# fetch <jar> <url> [argomenti curl...] -> stampa il codice HTTP; body in /tmp/s4-body, intestazioni in /tmp/s4-hdr.
fetch() {
    local jar="$1" url="$2"
    shift 2
    cl curl -sS -o /tmp/s4-body -D /tmp/s4-hdr -c "$jar" -b "$jar" -w '%{http_code}' "$@" "$url"
}

location() {
    cl sh -c "grep -i '^location:' /tmp/s4-hdr | tail -n 1 | tr -d '\r' | sed 's/^[Ll]ocation: //'"
}

body() {
    cl cat /tmp/s4-body
}

# has <pattern>: vero se il corpo appena letto contiene il pattern fisso (nessuna pipeline con grep -q).
has() {
    local page
    page="$(body)"
    grep -q -- "$1" <<<"$page"
}

has_i() {
    local page
    page="$(body)"
    grep -qi -- "$1" <<<"$page"
}

# nonce_n <n>: n-esimo valore del campo seo_lab_nonce nel corpo appena letto (1 = primo form).
nonce_n() {
    body | grep -o 'name="seo_lab_nonce" value="[^"]*"' | sed -n "${1}p" | sed 's/.*value="//; s/"$//'
}

uuid_in() {
    printf '%s' "$1" | sed -n 's/.*report=\([0-9a-f-]\{36\}\).*/\1/p'
}

# sql_scalar <url> <sql>: una riga, letta con $wpdb (il client mysql della CLI richiede SSL su MariaDB).
sql_scalar() {
    S4_SQL="$2" wpcli --url="$1" eval 'global $wpdb; echo (string) $wpdb->get_var(getenv("S4_SQL"));' 2>/dev/null | tr -d '\r'
}

# Prima del provisioning la tabella non esiste: il conteggio e' 0.
rows_a() {
    local v
    v="$(sql_scalar "$BASE_A" "SELECT COUNT(*) FROM wp_seolab_reports")"
    echo "${v:-0}"
}

rows_b() {
    local v
    v="$(sql_scalar "$BASE_B" "SELECT COUNT(*) FROM wp_2_seolab_reports")"
    echo "${v:-0}"
}

report_json() {
    sql_scalar "$BASE_A" "SELECT report FROM wp_seolab_reports WHERE scan_id = '$1'"
}

# --- Installazione multisite: due siti, admin del solo sito A e del solo sito B ---
wpcli core multisite-install --url="$BASE_A" --title='Sito sintetico S4' \
    --admin_user=seolabroot --admin_password="$ROOT_PASS" --admin_email=s4-root@example.invalid --skip-email >/dev/null || exit 1
SITE_B="$(wpcli site create --slug=sitio-b --title='Sito B sintetico' --email=s4-b@example.invalid --porcelain)" || exit 1
wpcli user create admina s4-a@example.invalid --role=administrator --user_pass="$PASS_A" --url="$BASE_A" >/dev/null || exit 1
wpcli user create adminb s4-b-admin@example.invalid --role=administrator --user_pass="$PASS_B" --url="$BASE_B" >/dev/null || exit 1
if [ -n "$RELEASE_ZIP" ]; then
    wpcli plugin install /zip/seo-lab.zip --activate-network >/dev/null || exit 1
    # Non vacuita': classi e file installati dal pacchetto, nessun bind-mount del plugin sul sito web.
    CLASS_FILE="$(wpcli --url="$BASE_A" eval 'echo (new ReflectionClass("SeoLab\\Admin\\Plugin"))->getFileName();' 2>/dev/null | tr -d '\r')"
    check 'zip_classe_caricata_dal_plugin_installato' "$(case "$CLASS_FILE" in "$PLUGIN_DIR"/src/*) echo yes ;; *) echo no ;; esac)"
    WEB_MOUNTS="$(docker inspect -f '{{range .Mounts}}{{.Destination}} {{end}}' "$WEB_NAME")"
    check 'zip_nessun_bind_mount_del_plugin' "$(case "$WEB_MOUNTS" in *plugins/seo-lab*) echo no ;; *) echo yes ;; esac)"
    # Il contenuto installato deve coincidere byte per byte con lo ZIP (hash per file, stesso elenco).
    EXPECTED_HASHES="$(python3 -c '
import hashlib, sys, zipfile
archive = zipfile.ZipFile(sys.argv[1])
for info in sorted(archive.infolist(), key=lambda item: item.filename):
    if not info.filename.endswith("/"):
        print(hashlib.sha256(archive.read(info.filename)).hexdigest() + "  " + info.filename[len("seo-lab/"):])
' "$RELEASE_ZIP")"
    INSTALLED_HASHES="$(docker run --rm --pull never --network none --label "ai-bus.sandbox=$SID" --user 33:33 \
        --entrypoint sh -v "$VOL:/var/www/html:ro" "$CLI_IMG" -c "cd '$PLUGIN_DIR' && find . -type f | sed 's#^\./##' | LC_ALL=C sort | while IFS= read -r f; do sha256sum \"\$f\"; done")"
    echo "info zip_file=$(printf '%s\n' "$EXPECTED_HASHES" | grep -c .) installati=$(printf '%s\n' "$INSTALLED_HASHES" | grep -c .) zip_sha256=$(sha256sum "$RELEASE_ZIP" | cut -d' ' -f1)"
    check 'zip_installazione_identica_al_pacchetto' "$([ -n "$EXPECTED_HASHES" ] && [ "$EXPECTED_HASHES" = "$INSTALLED_HASHES" ] && echo yes || echo no)"
else
    wpcli plugin activate seo-lab --network >/dev/null || exit 1
fi
# Lingua dell'admin B: preferenza utente (WordPress senza pacchetto core it_IT usa en_US per il sito).
wpcli user meta update adminb locale it_IT >/dev/null || exit 1
# Regole di rewrite dei sottositi (subdirectory multisite) nell'albero condiviso: WordPress non le genera da solo.
cat > "$SANDBOX/htaccess" <<'HTACCESS'
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
HTACCESS
docker run --rm --pull never --network none --label "ai-bus.sandbox=$SID" --user 33:33 --entrypoint cp \
    -v "$VOL:/var/www/html" -v "$SANDBOX/htaccess:/s4-htaccess:ro" "$CLI_IMG" /s4-htaccess /var/www/html/.htaccess >/dev/null || exit 1

# --- Contenuti sintetici: stesso ID su entrambi i siti ---
PA="$(wpcli post create --post_type=page --post_status=publish --post_title='Pagina A sintetica' \
    --post_content='<p>Contenuto sintetico di verifica A</p>' --porcelain --url="$BASE_A")"
PB="$(wpcli post create --post_type=page --post_status=publish --post_title='Pagina B sintetica' \
    --post_content='<p>Contenuto sintetico di verifica B</p>' --porcelain --url="$BASE_B")"
DA="$(wpcli post create --post_type=page --post_status=draft --post_title='Bozza sintetica' --porcelain --url="$BASE_A")"
PRV="$(wpcli post create --post_type=page --post_status=private --post_title='Privata sintetica' --porcelain --url="$BASE_A")"
PWD_ID="$(wpcli post create --post_type=page --post_status=publish --post_title='Protetta sintetica' --post_password=s4-pass --porcelain --url="$BASE_A")"
MAL="$(wpcli post create --post_type=page --post_status=publish --post_title='<script>alert(1)</script>' --porcelain --url="$BASE_A")"
if [ "$PA" != "$PB" ]; then
    S4_SQL="UPDATE wp_2_posts SET ID = $PA WHERE ID = $PB" wpcli --url="$BASE_B" eval 'global $wpdb; $wpdb->query(getenv("S4_SQL")); echo "ok";' >/dev/null 2>&1
    PB="$PA"
fi
check 'stesso_id_post_nei_due_siti' "$([ "$PA" = "$PB" ] && [ "$(sql_scalar "$BASE_B" "SELECT COUNT(*) FROM wp_2_posts WHERE ID = $PB")" = 1 ] && echo yes || echo no)"
ROWS_A0="$(rows_a)"
ROWS_A0="${ROWS_A0:-0}"
ROWS_B0="$(rows_b)"
ROWS_B0="${ROWS_B0:-0}"

# --- P1.2 (--p1-ai): revisione AI privata su rapporto archiviato, connettore sintetico nella sola sandbox ---
# p1_field <name>: valore del campo nascosto nel corpo appena letto.
p1_field() {
    body | grep -o "name=\"$1\" value=\"[^\"]*\"" | head -n 1 | sed 's/.*value="//; s/"$//'
}

# p1_calls: chiamate del modello di prova registrate nella sandbox (una riga per chiamata).
p1_calls() {
    docker exec "$WEB_NAME" sh -c 'cat /var/www/html/wp-content/s4-p1-calls.log 2>/dev/null | wc -l' | tr -d ' \r'
}

# p1_egress: tentativi di rete verso i provider bloccati dal mu-plugin di prova.
p1_egress() {
    docker exec "$WEB_NAME" sh -c 'cat /var/www/html/wp-content/s4-p1-egress.log 2>/dev/null | wc -l' | tr -d ' \r'
}

# p1_normal <file>: pagina con i nonce normalizzati, per il confronto dell'identita' della vista P0.
p1_normal() {
    sed -E -e 's/(name="(seo_lab_nonce|seo_lab_ctx_nonce|_wpnonce)" value=")[^"]*"/\1X"/g' -e 's/"time":"[0-9]+"/"time":"X"/g' "$1"
}

# conc_post <n> <jar> <argomenti curl...>: POST concorrente verso admin-post.php, codice HTTP in $SANDBOX/p1-conc-<n>.code.
conc_post() {
    local n="$1" jar="$2"
    shift 2
    cl curl -sS -o "/tmp/p1-body-$n" -D "/tmp/p1-hdr-$n" -b "$jar" -w '%{http_code}' "$@" "$BASE_A/wp-admin/admin-post.php" > "$SANDBOX/p1-conc-$n.code"
}

p1_ai_flow() {
    local U07 U10 U07B ISSUED CONF_ID C_PHASE C_CTX PREVIEW_NONCE SET_NONCE MD5_BEFORE MD5_AFTER LEDGER AUTOLOAD CODES code
    local P1_JAR_A=/tmp/s4-p1-jar-a
    local P1_JAR_B=/tmp/s4-p1-jar-b
    local CONFIRM_ARGS=()
    cl rm -f /tmp/s4-p1-jar-a /tmp/s4-p1-jar-b

    # Semina: rapporti idonei dalle fixture 07 e 10 sui contenuti A e B (stessa UUID, tabelle separate).
    P1_POST_ID="$PA" wpcli --url="$BASE_A" eval-file /seolab/tests/p1-admin-wp.php > "$SANDBOX/p1-seed-a.txt" 2>&1
    P1_POST_ID="$PB" wpcli --url="$BASE_B" eval-file /seolab/tests/p1-admin-wp.php > "$SANDBOX/p1-seed-b.txt" 2>&1
    U07="$(sed -n 's/^P1WP {"fixture":"07","uuid":"\([^"]*\)"}$/\1/p' "$SANDBOX/p1-seed-a.txt")"
    U10="$(sed -n 's/^P1WP {"fixture":"10","uuid":"\([^"]*\)"}$/\1/p' "$SANDBOX/p1-seed-a.txt")"
    U07B="$(sed -n 's/^P1WP {"fixture":"07","uuid":"\([^"]*\)"}$/\1/p' "$SANDBOX/p1-seed-b.txt")"
    check 'p1_semina_rapporti_a_e_b' "$([ -n "$U07" ] && [ -n "$U10" ] && [ -n "$U07B" ] && ! grep -q '"ok":false' "$SANDBOX/p1-seed-a.txt" && ! grep -q '"ok":false' "$SANDBOX/p1-seed-b.txt" && echo yes || echo no)"

    # Login admin A, baseline della vista del rapporto con opt-in spento e impostazioni.
    fetch "$P1_JAR_A" "$BASE_A/wp-login.php" >/dev/null
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-login.php" --data-urlencode "log=admina" --data-urlencode "pwd=$PASS_A" \
        --data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$BASE_A/wp-admin/" --data-urlencode testcookie=1)"
    check 'p1_login_admin_a' "$([ "$code" = 302 ] && echo yes || echo no)"
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$U07")"
    check 'p1_rapporto_200_opt_in_spento' "$([ "$code" = 200 ] && has 'Verify again' && echo yes || echo no)"
    check 'p1_nessun_form_ai_con_opt_in_spento' "$(has 'seo_lab_ai_preview' && echo no || echo yes)"
    body > "$SANDBOX/p1-report-off-baseline.html"
    MD5_BEFORE="$(sql_scalar "$BASE_A" "SELECT MD5(report) FROM wp_seolab_reports WHERE scan_id = '$U07'")"
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/options-general.php?page=seo-lab-ai")"
    SET_NONCE="$(p1_field seo_lab_nonce)"
    check 'p1_impostazioni_opt_in_spento_di_default' "$([ "$code" = 200 ] && has 'SEO Lab AI' && [ -n "$SET_NONCE" ] && has 'value="0" checked' && echo yes || echo no)"

    # Attivazione: il Core deve dichiarare AI disponibile (altrimenti la prova si ferma qui).
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_ai_settings \
        --data-urlencode seolab_ai_enabled=1 --data-urlencode "seo_lab_nonce=$SET_NONCE")"
    echo "info p1_attivazione http=$code location=$(location)"
    check 'p1_attivazione_opt_in_redirect' "$([ "$code" = 302 ] && grep -q 'saved=1' <<<"$(location)" && echo yes || echo no)"

    # Connettore assente: nessun form, e un POST con nonce non valido non ammette nulla.
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$U07")"
    check 'p1_nessun_form_senza_connettore' "$(has 'seo_lab_ai_preview' && echo no || echo yes)"
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_ai_preview \
        --data-urlencode "report=$U07" --data-urlencode provider=openai --data-urlencode model=p1-fixture \
        --data-urlencode language=it --data-urlencode seo_lab_nonce=errato)"
    check 'p1_anteprima_nonce_errato_403' "$([ "$code" = 403 ] && echo yes || echo no)"

    # Installazione del connettore sintetico nei MU-plugin della sola sandbox (mai nello ZIP).
    docker run --rm --pull never --network none --label "ai-bus.sandbox=$SID" --user 33:33 --entrypoint sh \
        -v "$VOL:/var/www/html" -v "$ROOT/tests:/seolab/tests:ro" "$CLI_IMG" \
        -c 'mkdir -p /var/www/html/wp-content/mu-plugins && cp /seolab/tests/p1-admin-mu.php /var/www/html/wp-content/mu-plugins/s4-p1-fixture.php' >/dev/null || FAILED=1
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$U07")"
    PREVIEW_NONCE="$(nonce_n 3)"
    check 'p1_form_con_connettore_solo_openai' "$(has 'seo_lab_ai_preview' && has 'value="openai"' && ! has 'value="anthropic"' && [ -n "$PREVIEW_NONCE" ] && echo yes || echo no)"

    # Anteprima: testo letterale, cap, nessuna scrittura del registro e nessuna chiamata al modello.
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_ai_preview \
        --data-urlencode "report=$U07" --data-urlencode provider=openai --data-urlencode model=p1-fixture \
        --data-urlencode language=it --data-urlencode "seo_lab_nonce=$PREVIEW_NONCE")"
    check 'p1_anteprima_200_privata' "$([ "$code" = 200 ] && [ -z "$(location)" ] && cl grep -qi 'cache-control: private, no-store, max-age=0' /tmp/s4-hdr && echo yes || echo no)"
    check 'p1_anteprima_system_letterale_e_cap' "$(has 'You review one WordPress SEO diagnosis' && has '768 tokens' && has 'Send a request' && echo yes || echo no)"
    check 'p1_anteprima_nessuna_chiamata' "$([ "$(p1_calls)" = 0 ] && echo yes || echo no)"
    ISSUED="$(p1_field issued_at)"
    CONF_ID="$(p1_field confirmation_id)"
    C_PHASE="$(p1_field seo_lab_nonce)"
    C_CTX="$(p1_field seo_lab_ctx_nonce)"
    CONFIRM_ARGS=(--data-urlencode action=seo_lab_ai_confirm --data-urlencode "report=$U07" --data-urlencode provider=openai
        --data-urlencode model=p1-fixture --data-urlencode language=it --data-urlencode "issued_at=$ISSUED"
        --data-urlencode "confirmation_id=$CONF_ID" --data-urlencode "seo_lab_nonce=$C_PHASE" --data-urlencode "seo_lab_ctx_nonce=$C_CTX")

    # Conferma singola: un solo tentativo al modello, risultato privato senza redirect.
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" "${CONFIRM_ARGS[@]}")"
    check 'p1_conferma_200_con_risultato' "$([ "$code" = 200 ] && has 'Suggested test' && has 'Explanation' && echo yes || echo no)"
    check 'p1_conferma_senza_redirect_e_no_store' "$([ -z "$(location)" ] && cl grep -qi 'cache-control: private, no-store' /tmp/s4-hdr && echo yes || echo no)"
    check 'p1_un_tentativo_al_modello' "$([ "$(p1_calls)" = 1 ] && echo yes || echo no)"

    # Replay immediato dello stesso invio: 429, nessuna chiamata.
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" "${CONFIRM_ARGS[@]}")"
    check 'p1_replay_429_senza_chiamata' "$([ "$code" = 429 ] && [ "$(p1_calls)" = 1 ] && echo yes || echo no)"

    # Alterazione del modello su una nuova anteprima: 409, poi l'invio corretto passa.
    sleep 11
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_ai_preview \
        --data-urlencode "report=$U07" --data-urlencode provider=openai --data-urlencode model=p1-fixture \
        --data-urlencode language=it --data-urlencode "seo_lab_nonce=$PREVIEW_NONCE")"
    ISSUED="$(p1_field issued_at)"; CONF_ID="$(p1_field confirmation_id)"; C_PHASE="$(p1_field seo_lab_nonce)"; C_CTX="$(p1_field seo_lab_ctx_nonce)"
    CONFIRM_ARGS=(--data-urlencode action=seo_lab_ai_confirm --data-urlencode "report=$U07" --data-urlencode provider=openai
        --data-urlencode model=p1-other --data-urlencode language=it --data-urlencode "issued_at=$ISSUED"
        --data-urlencode "confirmation_id=$CONF_ID" --data-urlencode "seo_lab_nonce=$C_PHASE" --data-urlencode "seo_lab_ctx_nonce=$C_CTX")
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" "${CONFIRM_ARGS[@]}")"
    check 'p1_modello_alterato_409_senza_chiamata' "$([ "$code" = 409 ] && [ "$(p1_calls)" = 1 ] && echo yes || echo no)"
    CONFIRM_ARGS=(--data-urlencode action=seo_lab_ai_confirm --data-urlencode "report=$U07" --data-urlencode provider=openai
        --data-urlencode model=p1-fixture --data-urlencode language=it --data-urlencode "issued_at=$ISSUED"
        --data-urlencode "confirmation_id=$CONF_ID" --data-urlencode "seo_lab_nonce=$C_PHASE" --data-urlencode "seo_lab_ctx_nonce=$C_CTX")
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" "${CONFIRM_ARGS[@]}")"
    check 'p1_invio_corretto_dopo_rifiuto_200' "$([ "$code" = 200 ] && [ "$(p1_calls)" = 2 ] && echo yes || echo no)"

    # Concorrenza: due POST identici in parallelo su una nuova anteprima. Un solo 200, un solo 429, una chiamata.
    sleep 11
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_ai_preview \
        --data-urlencode "report=$U07" --data-urlencode provider=openai --data-urlencode model=p1-fixture \
        --data-urlencode language=it --data-urlencode "seo_lab_nonce=$PREVIEW_NONCE")"
    ISSUED="$(p1_field issued_at)"; CONF_ID="$(p1_field confirmation_id)"; C_PHASE="$(p1_field seo_lab_nonce)"; C_CTX="$(p1_field seo_lab_ctx_nonce)"
    CONFIRM_ARGS=(--data-urlencode action=seo_lab_ai_confirm --data-urlencode "report=$U07" --data-urlencode provider=openai
        --data-urlencode model=p1-fixture --data-urlencode language=it --data-urlencode "issued_at=$ISSUED"
        --data-urlencode "confirmation_id=$CONF_ID" --data-urlencode "seo_lab_nonce=$C_PHASE" --data-urlencode "seo_lab_ctx_nonce=$C_CTX")
    local first_pid second_pid first_rc=0 second_rc=0
    conc_post 1 "$P1_JAR_A" "${CONFIRM_ARGS[@]}" &
    first_pid=$!
    conc_post 2 "$P1_JAR_A" "${CONFIRM_ARGS[@]}" &
    second_pid=$!
    wait "$first_pid" || first_rc=$?
    wait "$second_pid" || second_rc=$?
    CODES="$(printf '%s\n' "$(<"$SANDBOX/p1-conc-1.code")" "$(<"$SANDBOX/p1-conc-2.code")" | LC_ALL=C sort)"
    echo "info p1_concorrenza codici=$(printf '%s' "$CODES" | tr '\n' ',') rc=$first_rc,$second_rc chiamate=$(p1_calls)"
    echo "info p1_concorrenza corpo1=$(cl sh -c "sed 's/<[^>]*>//g' /tmp/p1-body-1 | tr -s ' \n' ' ' | head -c 240")"
    echo "info p1_concorrenza corpo2=$(cl sh -c "sed 's/<[^>]*>//g' /tmp/p1-body-2 | tr -s ' \n' ' ' | head -c 240")"
    check 'p1_concorrenza_un_200_un_429' "$([ "$first_rc" = 0 ] && [ "$second_rc" = 0 ] && [ "$CODES" = $'200\n429' ] && echo yes || echo no)"
    check 'p1_concorrenza_una_sola_chiamata' "$([ "$(p1_calls)" = 3 ] && echo yes || echo no)"

    # Registro: una sola riga per sito, senza modello, UUID del rapporto o segreto; non in autoload.
    LEDGER="$(sql_scalar "$BASE_A" "SELECT option_value FROM wp_options WHERE option_name = 'seolab_ai_ledger'")"
    AUTOLOAD="$(sql_scalar "$BASE_A" "SELECT autoload FROM wp_options WHERE option_name = 'seolab_ai_ledger'")"
    check 'p1_registro_senza_modello_uuid_segreto' "$(case "$LEDGER" in *p1-fixture*|*"$U07"*|*sk-p1*) echo no ;; '') echo no ;; *) echo yes ;; esac)"
    check 'p1_registro_non_in_autoload' "$(case "$AUTOLOAD" in yes|on|auto|auto-on|'') echo no ;; *) echo yes ;; esac)"
    MD5_AFTER="$(sql_scalar "$BASE_A" "SELECT MD5(report) FROM wp_seolab_reports WHERE scan_id = '$U07'")"
    check 'p1_rapporto_archiviato_invariato' "$([ -n "$MD5_BEFORE" ] && [ "$MD5_BEFORE" = "$MD5_AFTER" ] && echo yes || echo no)"

    # Spegnimento: la vista torna identica al baseline e la conferma con opt-in spento e' 403, senza chiamata.
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_ai_settings \
        --data-urlencode seolab_ai_enabled=0 --data-urlencode "seo_lab_nonce=$SET_NONCE")"
    check 'p1_spegnimento_redirect' "$([ "$code" = 302 ] && echo yes || echo no)"
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$U07")"
    body > "$SANDBOX/p1-report-off-final.html"
    check 'p1_nessun_form_ai_dopo_spegnimento' "$(has 'seo_lab_ai_preview' && echo no || echo yes)"
    mkdir -p "$ROOT/.ai/20_execution/p1-2"
    p1_normal "$SANDBOX/p1-report-off-baseline.html" > "$ROOT/.ai/20_execution/p1-2/p1-report-off-baseline.norm.html"
    p1_normal "$SANDBOX/p1-report-off-final.html" > "$ROOT/.ai/20_execution/p1-2/p1-report-off-final.norm.html"
    echo "info p1_diff_vista_off:"
    diff "$ROOT/.ai/20_execution/p1-2/p1-report-off-baseline.norm.html" "$ROOT/.ai/20_execution/p1-2/p1-report-off-final.norm.html" | head -n 30
    check 'p1_vista_identica_al_baseline_con_nonce_normalizzati' "$(cmp -s "$ROOT/.ai/20_execution/p1-2/p1-report-off-baseline.norm.html" "$ROOT/.ai/20_execution/p1-2/p1-report-off-final.norm.html" && echo yes || echo no)"
    code="$(fetch "$P1_JAR_A" "$BASE_A/wp-admin/admin-post.php" "${CONFIRM_ARGS[@]}")"
    check 'p1_opt_in_spento_blocca_conferma_403' "$([ "$code" = 403 ] && [ "$(p1_calls)" = 3 ] && echo yes || echo no)"

    # Isolamento: il nonce del sito A non vale sul sito B; con opt-in spento B non mostra il form.
    fetch "$P1_JAR_B" "$BASE_B/wp-login.php" >/dev/null
    code="$(fetch "$P1_JAR_B" "$BASE_B/wp-login.php" --data-urlencode "log=adminb" --data-urlencode "pwd=$PASS_B" \
        --data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$BASE_B/wp-admin/" --data-urlencode testcookie=1)"
    check 'p1_login_admin_b' "$([ "$code" = 302 ] && echo yes || echo no)"
    code="$(fetch "$P1_JAR_B" "$BASE_B/wp-admin/tools.php?page=seo-lab&report=$U07B")"
    check 'p1_b_nessun_form_ai_con_opt_in_spento' "$(has 'seo_lab_ai_preview' && echo no || echo yes)"
    code="$(fetch "$P1_JAR_B" "$BASE_B/wp-admin/admin-post.php" "${CONFIRM_ARGS[@]}")"
    check 'p1_nonce_di_a_non_vale_su_b' "$([ "$code" = 403 ] && echo yes || echo no)"
    check 'p1_nessuna_richiesta_verso_provider' "$([ "$(p1_egress)" = 0 ] && echo yes || echo no)"
    check 'p1_chiamate_solo_del_flusso' "$([ "$(p1_calls)" = 3 ] && echo yes || echo no)"
}

if [ "$P1_AI" = 1 ]; then
    p1_ai_flow
fi

# --- Fase 1: senza Yoast ---
if [ "$P1_AI" != 1 ]; then
JAR_ANON=/tmp/s4-jar-anon
JAR_A=/tmp/s4-jar-a
JAR_B=/tmp/s4-jar-b
cl rm -f /tmp/s4-jar-anon /tmp/s4-jar-a /tmp/s4-jar-b

# H1: POST anonimo senza sessione: nessuna riga, redirect al login.
code="$(fetch "$JAR_ANON" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode post_id="$PA" --data-urlencode seo_lab_nonce=x)"
echo "info anonimo http=$code location=$(location)"
check 'anonimo_respinto_senza_nopriv' "$([ "$code" = 400 ] || ([ "$code" = 302 ] && location | grep -q wp-login) && echo yes || echo no)"
check 'anonimo_nessuna_riga' "$([ "$(rows_a)" = "$ROWS_A0" ] && echo yes || echo no)"

# Login admin A (GET preliminare per il cookie di test di WordPress).
fetch "$JAR_A" "$BASE_A/wp-login.php" >/dev/null
code="$(fetch "$JAR_A" "$BASE_A/wp-login.php" --data-urlencode "log=admina" --data-urlencode "pwd=$PASS_A" \
    --data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$BASE_A/wp-admin/" --data-urlencode testcookie=1)"
echo "info login_admin_a http=$code location=$(location)"
check 'login_admin_a' "$([ "$code" = 302 ] && echo yes || echo no)"

# H2: pagina admin: 200, form di Scan, titolo malevolo escapato.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab")"
NONCE_SCAN_A="$(nonce_n 1)"
check 'pagina_admin_200_con_scan' "$([ "$code" = 200 ] && has 'Scan' && [ -n "$NONCE_SCAN_A" ] && echo yes || echo no)"
echo "info titolo ctx=$(body | grep -o '.\{0,40\}alert(1).\{0,20\}' | head -n 3 | tr '\n' '|')"
check 'titolo_malevolo_escapato' "$(has '&lt;script&gt;alert' && ! has '<script>alert' && echo yes || echo no)"

# H3: GET verso admin-post: rifiutato prima di qualunque effetto.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php?action=seo_lab_scan&post_id=$PA")"
check 'get_rifiutato_405' "$([ "$code" = 405 ] && has 'accepts only POST' && echo yes || echo no)"

# H4: nonce errato o assente: 403 senza effetti.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode post_id="$PA" --data-urlencode seo_lab_nonce=errato)"
check 'nonce_errato_403' "$([ "$code" = 403 ] && echo yes || echo no)"

# H5: input anomali: 400 senza effetti.
for raw in '2abc' ' 2' '0' '-2' '1.5' '9999999999999999999999'; do
    code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
        --data-urlencode "post_id=$raw" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_A")"
    check "post_id_anomalo_400_${raw// /_}" "$([ "$code" = 400 ] && echo yes || echo no)"
done
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode "post_id[]=$PA" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_A")"
check 'post_id_array_400' "$([ "$code" = 400 ] && echo yes || echo no)"

# H6: contenuti non idonei: 400 e nessuna cattura.
for id in "$DA" "$PRV" "$PWD_ID"; do
    code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
        --data-urlencode "post_id=$id" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_A")"
    check "non_idoneo_400_id_$id" "$([ "$code" = 400 ] && echo yes || echo no)"
done
echo "info righe ROWS_A0=$ROWS_A0 adesso=$(rows_a)"
check 'nessuna_riga_dopo_input_anomali' "$([ "$(rows_a)" = "$ROWS_A0" ] && echo yes || echo no)"

# H7: scansione valida. Un URL estraneo aggiunto non diventa la destinazione.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode "post_id=$PA" --data-urlencode "url=https://evil.example/" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_A")"
LOC1="$(location)"
UUID1="$(uuid_in "$LOC1")"
CODE7="$code"
# H8 subito dopo H7: il cooldown e' di 10 s e le chiamate di controllo successive lo superano.
# H8: secondo invio immediato: rate limit, 429, nessuna riga.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode "post_id=$PA" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_A")"
echo "info cooldown http=$code location=$(location) righe=$(rows_a) atteso=$((ROWS_A0 + 1))"
echo "info cooldown_opt=$(sql_scalar "$BASE_A" "SELECT CONCAT(option_value,'|',autoload) FROM wp_options WHERE option_name='seolab_scan_last'") ora_client=$(cl date +%s)"
check 'cooldown_429_senza_riga' "$([ "$code" = 429 ] && [ "$(rows_a)" = "$((ROWS_A0 + 1))" ] && echo yes || echo no)"

check 'scan_valido_redirect_a_report' "$([ "$CODE7" = 302 ] && [ -n "$UUID1" ] && echo yes || echo no)"
check 'scan_una_riga_in_piu' "$([ "$(rows_a)" = "$((ROWS_A0 + 1))" ] && echo yes || echo no)"
J1="$(report_json "$UUID1")"
check 'url_estraneo_ignorato' "$(grep -q 'evil.example' <<<"$J1" && echo no || echo yes)"
check 'senza_yoast_provider_none' "$(grep -q '"provider_version":"none"' <<<"$J1" && echo yes || echo no)"
echo "info capture_reason=$(printf '%s' "$J1" | grep -o '"capture_reason":"[^"]*"')"
DNS_EXAMPLE_RC="$(cl sh -c 'getent hosts example.com >/dev/null 2>&1; echo $?')"
echo "info getent_example_com_rc=$DNS_EXAMPLE_RC"
check 'cattura_bloccata_dal_capturer' "$(grep -q '"capture_status":"blocked"' <<<"$J1" && echo yes || echo no)"

# H9: report, Verify ed Export con nonce legati all'UUID; poi Verify riuscito dopo la finestra.
sleep 11
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$UUID1")"
NONCE_VERIFY_1="$(nonce_n 1)"
NONCE_EXPORT_1="$(nonce_n 2)"
check 'pagina_report_200' "$([ "$code" = 200 ] && has 'Verify again' && echo yes || echo no)"
MD5_1_BEFORE="$(sql_scalar "$BASE_A" "SELECT MD5(report) FROM wp_seolab_reports WHERE scan_id = '$UUID1'")"
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_verify \
    --data-urlencode "report=$UUID1" --data-urlencode "seo_lab_nonce=$NONCE_VERIFY_1")"
LOC2="$(location)"
UUID2="$(uuid_in "$LOC2")"
check 'verify_redirect_con_base' "$([ "$code" = 302 ] && [ -n "$UUID2" ] && [ "$UUID2" != "$UUID1" ] && grep -q "base=$UUID1" <<<"$LOC2" && echo yes || echo no)"
echo "info verify http=$code loc=$LOC2 righe=$(rows_a)"
check 'verify_due_righe_base_invariata' "$([ "$(rows_a)" = "$((ROWS_A0 + 2))" ] && [ "$(sql_scalar "$BASE_A" "SELECT MD5(report) FROM wp_seolab_reports WHERE scan_id = '$UUID1'")" = "$MD5_1_BEFORE" ] && echo yes || echo no)"

# H10: nonce legato a un altro UUID: 403, con UUID esistente e inesistente indistinguibili.
GHOST='00000000-0000-4000-8000-00000000dead'
code_ghost="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_verify \
    --data-urlencode "report=$GHOST" --data-urlencode "seo_lab_nonce=$NONCE_VERIFY_1")"
code_other="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_verify \
    --data-urlencode "report=$UUID2" --data-urlencode "seo_lab_nonce=$NONCE_VERIFY_1")"
check 'nonce_di_altro_uuid_403' "$([ "$code_ghost" = 403 ] && [ "$code_other" = 403 ] && echo yes || echo no)"

# H11: export POST: 200, JSON con allowlist, filename UUID, senza userinfo.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_export \
    --data-urlencode "report=$UUID1" --data-urlencode "seo_lab_nonce=$NONCE_EXPORT_1")"
check 'export_200_download' "$([ "$code" = 200 ] && cl grep -qi "attachment; filename=\"seo-lab-report-$UUID1.json\"" /tmp/s4-hdr && echo yes || echo no)"
EXPORT_OK="$(cl cat /tmp/s4-body | php -r '$d = json_decode(stream_get_contents(STDIN), true); $keys = ["report_id","schema_version","post_id","source","captured_at_utc","capture_status","requested_url","observed_url","http","rule_outcomes","findings"]; $ok = is_array($d) && array_keys($d) === $keys && $d["report_id"] === $argv[1] && preg_match("~https?://[^/\s]*@~", (string) json_encode($d)) !== 1 && strpos((string) json_encode($d), "<script") === false; echo $ok ? "yes" : "no";' "$UUID1")"
check 'export_json_allowlist' "$([ "$EXPORT_OK" = yes ] && echo yes || echo no)"
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php?action=seo_lab_export&report=$UUID1")"
check 'export_get_405' "$([ "$code" = 405 ] && echo yes || echo no)"

# H12: contenuto dell'altro sito: admin A non vede il report di B (stesso post ID) e non ha accesso alla pagina B.
code="$(fetch "$JAR_A" "$BASE_B/wp-admin/tools.php?page=seo-lab")"
echo "info admin_a_su_B http=$code location=$(location)"
check 'admin_a_nessun_accesso_a_B' "$([ "$code" != 200 ] && ! has '>Scan<' && echo yes || echo no)"

# H13: frontend pubblico senza marcatori del plugin.
code="$(fetch /tmp/s4-jar-anon "$BASE_A/?p=$PA" -L)"
echo "info frontend http=$code marcatori=$(body | grep -io 'seo-lab\|SEO Lab' | sort | uniq -c | tr '\n' ' ')"
echo "info frontend_match=$(body | grep -io 'seo-lab\|seo_lab' | sort | uniq -c | tr '\n' ' ')"
check 'frontend_senza_marcatori' "$([ "$code" = 200 ] && ! has_i 'seo-lab\|seo_lab' && echo yes || echo no)"

# H14: admin B: lingua it_IT, scansione del proprio sito, nessun accesso dal sito A.
fetch "$JAR_B" "$BASE_B/wp-login.php" >/dev/null
code="$(fetch "$JAR_B" "$BASE_B/wp-login.php" --data-urlencode "log=adminb" --data-urlencode "pwd=$PASS_B" \
    --data-urlencode "wp-submit=Log In" --data-urlencode "redirect_to=$BASE_B/wp-admin/" --data-urlencode testcookie=1)"
check 'login_admin_b' "$([ "$code" = 302 ] && echo yes || echo no)"
code="$(fetch "$JAR_B" "$BASE_B/wp-admin/tools.php?page=seo-lab")"
NONCE_SCAN_B="$(nonce_n 1)"
echo "info admin_b http=$code scan=$(body | grep -o '>[A-Za-zàèé ]*<' | head -n 12 | tr '\n' ' ')"
echo "info admin_b head=$(body | head -c 160 | tr '\n' ' ') scan_it=$(body | grep -c 'Scansiona') scan_en=$(body | grep -c '>Scan<')"
check 'admin_b_pagina_in_italiano' "$([ "$code" = 200 ] && has 'Scansiona' && echo yes || echo no)"
code="$(fetch "$JAR_B" "$BASE_B/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode "post_id=$PB" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_B")"
LOC_B="$(location)"
UUID_B="$(uuid_in "$LOC_B")"
check 'scan_b_redirect_su_b' "$([ "$code" = 302 ] && [ -n "$UUID_B" ] && grep -q 'sitio-b' <<<"$LOC_B" && echo yes || echo no)"
check 'scan_b_riga_solo_in_b' "$([ "$(rows_b)" = "$((ROWS_B0 + 1))" ] && [ "$(rows_a)" = "$((ROWS_A0 + 2))" ] && echo yes || echo no)"
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$UUID_B")"
check 'report_b_non_visibile_a_admin_a' "$([ "$code" = 200 ] && has 'Report not found' && echo yes || echo no)"

# H15: Yoast installato sul sito A: la versione effettiva entra nel report.
wpcli --url="$BASE_A" plugin install /yoast/wordpress-seo.zip --activate >/dev/null || FAILED=1
sleep 11
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_scan \
    --data-urlencode "post_id=$PA" --data-urlencode "seo_lab_nonce=$NONCE_SCAN_A")"
UUID3="$(uuid_in "$(location)")"
J3="$(report_json "$UUID3")"
check 'con_yoast_provider_version_28_9' "$(grep -q '"provider_version":"28.9"' <<<"$J3" && echo yes || echo no)"

# H16: export di un report eliminato per retention: con nonce valido, 404 Report not found.
S4_SQL="DELETE FROM wp_seolab_reports WHERE scan_id = '$UUID1'" wpcli --url="$BASE_A" eval 'global $wpdb; $wpdb->query(getenv("S4_SQL")); echo "ok";' >/dev/null 2>&1 || FAILED=1
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/admin-post.php" --data-urlencode action=seo_lab_export \
    --data-urlencode "report=$UUID1" --data-urlencode "seo_lab_nonce=$NONCE_EXPORT_1")"
echo "info export_404 http=$code corpo=$(body | sed 's/<[^>]*>//g' | tr -s ' \n' ' ' | head -c 200)"
check 'export_report_assente_404' "$([ "$code" = 404 ] && has 'Report not found' && echo yes || echo no)"
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$UUID2&base=$UUID1")"
check 'base_eliminata_confronto_non_disponibile' "$([ "$code" = 200 ] && has 'Comparison not available' && echo yes || echo no)"
# H16b (D-2): confronto di un report con se stesso: non disponibile, nessuna tabella di differenze.
code="$(fetch "$JAR_A" "$BASE_A/wp-admin/tools.php?page=seo-lab&report=$UUID2&base=$UUID2")"
check 'confronto_con_se_stesso_non_disponibile' "$([ "$code" = 200 ] && has 'Comparison not available' && ! has 'Persists' && echo yes || echo no)"

# --- Finestra sulle scritture: Scan e Verify in-process con Yoast attivo e indexable assente ---
S4_SQL="DELETE FROM wp_yoast_indexable WHERE object_type = 'post' AND object_id = $PA" wpcli --url="$BASE_A" eval 'global $wpdb; $wpdb->query(getenv("S4_SQL")); echo "ok";' >/dev/null 2>&1
S4_POST_ID="$PA" S4_ADMIN_LOGIN=admina wpcli --context=admin --url="$BASE_A" eval-file /seolab/tests/s4-wp-window.php > "$SANDBOX/s4-wp-window-a.txt" 2>&1
WINDOW_RC=$?
sed -n 's/^S4WP //p' "$SANDBOX/s4-wp-window-a.txt" > "$SANDBOX/s4-wp-window-a.jsonl"
WIN_FAILS="$(grep -c '"ok":false' "$SANDBOX/s4-wp-window-a.jsonl")"
WIN_TOTAL="$(grep -c '"check"' "$SANDBOX/s4-wp-window-a.jsonl")"
grep '"ok":false' "$SANDBOX/s4-wp-window-a.jsonl"
check "finestra_scritture_${WIN_TOTAL}_controlli" "$([ "$WINDOW_RC" -eq 0 ] && [ "$WIN_TOTAL" -gt 0 ] && [ "$WIN_FAILS" -eq 0 ] && echo yes || echo no)"

# --- Verifiche su WordPress: provisioning, retention, errori di scrittura, isolamento ---
S4_POST_ID="$PA" S4_OTHER_BLOG="$SITE_B" wpcli --url="$BASE_A" eval-file /seolab/tests/s4-wp-check.php > "$SANDBOX/s4-wp-check-a.txt" 2>&1
PROBE_RC=$?
sed -n 's/^S4WP //p' "$SANDBOX/s4-wp-check-a.txt" > "$SANDBOX/s4-wp-check-a.jsonl"
PROBE_FAILS="$(grep -c '"ok":false' "$SANDBOX/s4-wp-check-a.jsonl")"
PROBE_TOTAL="$(grep -c '"check"' "$SANDBOX/s4-wp-check-a.jsonl")"
grep '"ok":false' "$SANDBOX/s4-wp-check-a.jsonl"
check "sonda_wp_blog_a_${PROBE_TOTAL}_controlli" "$([ "$PROBE_RC" -eq 0 ] && [ "$PROBE_TOTAL" -gt 0 ] && [ "$PROBE_FAILS" -eq 0 ] && echo yes || echo no)"

fi

# --- P-005 (modalita' ZIP o --p1-ai): disattivazione conserva i dati, uninstall di WordPress elimina solo i dati SEO Lab ---
if [ -n "$RELEASE_ZIP" ] || [ "$P1_AI" = 1 ]; then
    SITE_C="$(wpcli site create --slug=sitio-c --title='Sito C sintetico' --email=s5-c@example.invalid --porcelain)" || exit 1
    export S5_TABLE_BLOGS="1 $SITE_B"
    S5_RUNS=0
    S5_LAST_RC=0
    # s5_run <passo>: un passo in un processo wp separato, con righe S5WP in $SANDBOX/s5-p005.jsonl.
    s5_run() {
        local step="$1"
        S5_RUNS=$((S5_RUNS + 1))
        S5_STEP="$step" wpcli --url="$BASE_A" eval-file /seolab/tests/s5-uninstall-wp.php > "$SANDBOX/s5-$S5_RUNS-$step.txt" 2>&1
        S5_LAST_RC=$?
        echo "info p005 passo=$S5_RUNS:$step rc=$S5_LAST_RC"
        sed -n 's/^S5WP //p' "$SANDBOX/s5-$S5_RUNS-$step.txt" >> "$SANDBOX/s5-p005.jsonl"
    }
    : > "$SANDBOX/s5-p005.jsonl"
    s5_run seed
    wpcli --url="$BASE_A" plugin deactivate seo-lab --network >/dev/null || FAILED=1
    s5_run kept
    s5_run uninstall
    s5_run removed
    s5_run uninstall
    s5_run removed
    s5_run refuse_seed
    s5_run uninstall_refused
    REFUSE_RC=$S5_LAST_RC
    check 'p005_rifiuto_drop_segnalato_senza_esito_ok' "$([ "$REFUSE_RC" -ne 0 ] || grep -q 'could not remove' "$SANDBOX/s5-$S5_RUNS-uninstall_refused.txt" && echo yes || echo no)"
    s5_run kept
    s5_run uninstall
    s5_run removed
    P5_TOTAL="$(grep -c '"check"' "$SANDBOX/s5-p005.jsonl")"
    P5_FAILS="$(grep -c '"ok":false' "$SANDBOX/s5-p005.jsonl")"
    grep '"ok":false' "$SANDBOX/s5-p005.jsonl"
    check "p005_uninstall_wp_${P5_TOTAL}_controlli" "$([ "$P5_TOTAL" -gt 0 ] && [ "$P5_FAILS" -eq 0 ] && echo yes || echo no)"
    if [ "$P1_AI" = 1 ]; then
        mkdir -p "$ROOT/.ai/20_execution/p1-2"
        cp "$SANDBOX/s5-p005.jsonl" "$ROOT/.ai/20_execution/p1-2/s5-p005-wp.jsonl"
    else
        cp "$SANDBOX/s5-p005.jsonl" "$ROOT/.ai/20_execution/s5-p005-wp.jsonl"
    fi
fi

echo "Esito: passati=$PASSED, falliti=$FAILS"
EVIDENCE="$ROOT/.ai/20_execution"
mkdir -p "$EVIDENCE"
if [ "$P1_AI" != 1 ]; then
    cp "$SANDBOX/s4-wp-check-a.jsonl" "$EVIDENCE/s4-wp-check-a.jsonl"
    cp "$SANDBOX/s4-wp-window-a.jsonl" "$EVIDENCE/s4-wp-window-a.jsonl"
fi
DEBUG_LINES="$(docker exec "$WEB_NAME" sh -c 'cat /var/www/html/wp-content/debug.log 2>/dev/null' | grep -c 'plugins/seo-lab/' || true)"
DEBUG_SEEN="$(docker exec "$WEB_NAME" sh -c 'test -f /var/www/html/wp-content/debug.log && echo si || echo no')"
echo "info debug_log_presente=$DEBUG_SEEN righe_plugin=$DEBUG_LINES"
check 'debug_log_senza_righe_del_plugin' "$([ "$DEBUG_SEEN" = si ] && [ "$DEBUG_LINES" = 0 ] && echo yes || echo no)"

exit "$FAILED"
