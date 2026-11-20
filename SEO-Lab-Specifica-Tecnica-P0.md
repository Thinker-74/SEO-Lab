# SEO Lab — Specifica tecnica P0

**Stato:** bozza architetturale per discussione, non implementazione né documentazione di funzionalità già disponibili  
**Data:** 20 novembre 2026  
**Progetto:** SEO Lab (nome di sviluppo); brand commerciale da scegliere  
**Posizionamento congelato:** *Stop guessing. Start tracing.* / *The SEO Output Debugger for WordPress*  
**Meccanismo:** *Configuration-to-Output Trace*  
**Sviluppo:** indipendente dall'incontro con SEOlution del 24 novembre

## 1. Obiettivo e criteri di accettazione

L'MVP deve individuare differenze dimostrabili fra dati SEO attesi e output HTTP/HTML pubblico per singole pagine WordPress, indicando quando l'origine di una differenza non è nota. **Non deve modificare** contenuti, impostazioni SEO, tema, plugin o file pubblicati.

**Perimetro P0:** tre famiglie diagnostiche (`canonical`, `robots`, `jsonld`), integrazione di lettura Yoast, interfaccia italiano/inglese, rapporto con prove, ipotesi e procedimento di verifica, nuova scansione confrontabile. Deve funzionare senza API AI e su hosting condivisi compatibili con i requisiti minimi ancora da confermare.

**Fuori P0:** generazione/modifica automatica contenuti, API di modelli AI (P1 immediata), agent skills/MCP, crawling di interi siti, punteggi GEO, monitoring continuativo, connettori GSC, JavaScript-rendering, supporto a più SEO plugin, attribuzione universale della causa, servizi cloud obbligatori.

**Metriche sperimentali:** 10/10 fixture iniziali con risultato atteso, 100% finding accompagnati da evidenza e istruzione di nuova verifica; riduzione ≥50% del tempo mediano su casi semplici rispetto al controllo manuale, da misurare empiricamente. I 10 test sono smoke/regression test, non garanzia statistica.

## 2. Architettura logica

```text
[WordPress + Yoast] -> Expected Provider -> Expected Snapshot
                                           |
[URL pubblico] -> HTTP Capturer -> HTML/Header Parser -> Observed Snapshot
                                           |
                    Rule Engine (regole versionate)
                                           |
                Finding + Evidence + Hypothesis
                                           |
                 Report IT/EN -> Re-scan -> Diff
```

**Moduli PHP proposti** (nomi di cartelle provvisori):

- `src/Contracts/`: interfacce di provider, capture, regola, archivio; DTO immutabili.
- `src/Expected/`: `YoastExpectedProvider`, risoluzione pagina e gestione indisponibilità.
- `src/Capture/`: `SafeHttpCapturer`, parser HTML DOM, header/JSON-LD parser.
- `src/Rules/`: regole `canonical`, `robots`, `jsonld` con ID e versione.
- `src/Diagnosis/`: findings, ipotesi, classificazione della certezza, confronto tra scan.
- `src/Storage/`: conservazione limitata delle evidenze e dei risultati.
- `src/Admin/`: pagina e REST API privata, export del singolo rapporto.
- `languages/`, `tests/fixtures/`, `tests/unit/`, `tests/integration/`.

Il plugin deve essere **read-only rispetto al contenuto del sito**: per la propria funzione memorizza solo report e impostazioni minime. Nessun tag SEO, sitemap, `robots.txt`, JSON-LD o canonical è emesso dal plugin nell'HTML pubblico. Nessuna dipendenza runtime da Python o da server di sviluppo.

## 3. Provider `Expected`: distinzione delle fonti

Per il test servono **livelli distinti**, non un unico campo chiamato "configurazione":

1. **Configured**: eventuale valore configurato esplicitamente dall'utente (se recuperabile con API documentata e contesto sufficiente); `unavailable` se non affidabile.
2. **Resolved**: valore che il motore Yoast restituisce per quella pagina tramite `YoastSEO()->meta->for_post( $id )` (canonical, robots, schema, secondo le API e le versioni disponibili). La funzione può restituire `false` se mancano gli indexable: in tal caso `expected_unavailable` e **non** `conflict`.
3. **Observed**: documento/headers HTTP effettivamente ricevuti dal punto di osservazione del plugin, non un'affermazione su ciò che Google o un browser con JavaScript vedono.

`Expected` è la previsione del provider nelle condizioni dell'accesso API; `Observed` è la risposta di rete. Alcuni filtri Yoast, plugin, tema, CDN, cache, varianti per User-Agent e codice runtime possono far divergere i due livelli. **Non attribuire l'origine al tema o a un plugin senza prova isolante.**

La Yoast Surfaces API supporta `for_post( ID )` per i metadati, fra cui `canonical`, `robots`, `schema`; l'API REST Yoast può fornire un confronto diagnostico aggiuntivo, ma non è necessaria come seconda chiamata HTTP nella P0. Richiede test specifici per Yoast installato, versioni e archivi. Il provider è opzionale: se Yoast non è attivo si può mostrare l'output osservato ma non un confronto configured-to-output significativo.

**Limitazione P0:** pagine `post`/`page` pubbliche e pubblicate, identificabili con un ID WordPress. Non includere bozze, preview, home dinamiche, archivi e URL arbitrari. Compatibilità Yoast iniziale: definire una matrice di versioni supportate attraverso test, non soltanto un controllo di presenza della classe.

## 4. Acquisizione HTTP e sicurezza

Una scansione viene avviata da un amministratore autenticato selezionando un contenuto pubblicato. L'URL è ricavato dal permalink dell'oggetto WordPress e **non** accettato liberamente dall'utente.

- Solo `http`/`https`; `wp_safe_remote_get()` con validazione SSRF di WordPress; verificare la **stessa origine attesa a ogni redirect**, massimo 3 hop, e non seguire automaticamente redirect verso domini esterni. Non accettare IP, porte o host arbitrari attraverso l'interfaccia di scansione.
- SSL verification attiva, nessuna forzatura di `sslverify=false`, nessun invio di cookie di sessione, token amministrativi, Authorization o credenziali al frontend pubblico.
- Timeout iniziale indicativo 5 s; dimensione massima della risposta (per esempio 1 MiB, configurabile/testabile); limite a una pagina per richiesta e rate limit per utente/sito. Se il corpo è troncato, non inferire l'assenza di tag.
- Registrare HTTP status, URL richiesto/finale, redirect, `Content-Type`, eventuali `X-Robots-Tag`, hash SHA-256 del body, timestamp UTC, informazioni essenziali di cache (`Age`, `ETag`, `Vary`, se presenti). Non registrare indiscriminatamente i cookie ricevuti.
- Accettare solo risposte HTML valide per i confronti su HTML. Distinguere errori di rete, blocchi WAF, redirect fuori origine, risposta privata, HTML troncato o malformato come **impossibilità di test**, non come problemi SEO confermati.
- Un self-request dell'hosting può essere bloccato da DNS/firewall/hairpin; **non** aggirare i controlli SSRF. Mostrare `capture_unavailable`; l'audit esterno dal server di sviluppo è un percorso di test separato, non un requisito o fallback silenzioso.
- La risposta è HTML di terzi e va trattata come dato non attendibile. DOM e JSON parsati senza eseguire JavaScript; escaping in UI; evitare XXE e fetch di risorse esterne; verificare `Content-Type` e memoria massima.

**Importante:** una risposta HTTP vista dal server WordPress è una singola osservazione; non equivale automaticamente a ciò che vedono Googlebot, ChatGPT, un utente esterno o un browser con rendering JavaScript. Il rapporto deve indicare il punto di osservazione e i limiti.

## 5. Modello dati: contratti minimi

Lo storage P0 può usare **due tabelle versionate** per report e findings, con evidenze ridotte in JSON validato; valutare opzione alternativa con tabella dedicata solo se i test di dimensione/prestazioni lo richiedono. Indici su `post_id`, `created_at`, `scan_id`. Non salvare l'intero HTML per impostazione predefinita. Configurare retention, inizialmente 30 giorni o gli ultimi 10 scan per pagina; pulizia anche senza WP-Cron affidabile.

```json
{
  "scan_id": "uuid",
  "schema_version": "1",
  "post_id": 123,
  "source": {"provider": "yoast", "provider_version": "observed-version"},
  "requested_url": "https://example.test/pagina/",
  "observed_url": "https://example.test/pagina/",
  "captured_at_utc": "2026-11-20T17:00:00Z",
  "http": {"status": 200, "content_type": "text/html", "body_sha256": "..."},
  "capture_status": "ok",
  "findings": [
    {
      "rule_id": "CANONICAL_MULTIPLE_DISTINCT",
      "rule_version": "1.0.0",
      "state": "confirmed_anomaly",
      "severity": "warning",
      "expected": {"value": "https://example.test/pagina/", "source": "yoast:resolved"},
      "observed": {"values": ["https://example.test/pagina/", "https://example.test/vecchia/"]},
      "evidence": [{"kind": "html", "locator": "//head/link[@rel='canonical']", "excerpt": "sanitized excerpt"}],
      "cause": {"state": "unknown", "candidates": [], "proof": []},
      "next_test": "Isolare in staging il componente che emette il tag aggiuntivo",
      "verification_method": "Ripetere la scansione e confrontare i canonical distinti"
    }
  ]
}
```

**Stati separati:**

- Anomalia: `confirmed_anomaly | no_anomaly | inconclusive | not_tested`.
- Causa: `confirmed | likely | possible | unknown` — **mai percentuali arbitrarie**.
- Scansione: `ok | partial | blocked | failed`.
- Severità: `info | warning | error` (indipendente dalla certezza).

Una ipotesi può essere `likely` solo con regole esplicite documentate; l'identificazione del plugin responsabile è **confermata** solo da un test isolante affidabile e ripetuto su staging o prova tecnica equivalente. Elencare filtri registrati o plugin attivi è al massimo un indizio, non una prova causale.

## 6. Regole versionate P0

### 6.1 Canonical

- `CANONICAL_MULTIPLE_DISTINCT`: due o più dichiarazioni canoniche HTML (o eventualmente HTTP Link header) con target realmente differenti, dopo normalizzazione **conservativa** di entità HTML, URI assolute/relative e parti lecite. Non eliminare automaticamente distinzioni importanti su path, query, case o trailing slash.
- `CANONICAL_EXPECTED_MISMATCH`: target Yoast risolto differente dall'unico canonical osservato; valutare redirect, lingua, pagina 404, `noindex` e altre condizioni contestuali; se non confrontabile -> `inconclusive`.
- `CANONICAL_MISSING_WHEN_EXPECTED`: assenza dimostrabile solo se HTTP completo, pagina HTML valida e il provider prevedeva quel tag.
- Test negativo: una sola dichiarazione corretta, due dichiarazioni identiche (report duplicazione non necessariamente contraddizione), redirect legittimo, provider indisponibile.

### 6.2 Meta robots

- Estrarre e normalizzare tutti i `meta name="robots"`, `googlebot` pertinenti e header `X-Robots-Tag`; tener conto dello scope del bot e delle restrizioni cumulative.
- `ROBOTS_EFFECTIVE_MISMATCH`: differenza verificabile fra le direttive risolte da Yoast e le restrizioni effettivamente applicabili alla risposta. Per Google le restrizioni prevalgono: la presenza di `index` e `noindex` non crea indeterminatezza, ma produce effetto `noindex`.
- `ROBOTS_UNEXPECTED_NOINDEX`: segnalare restrizione osservata quando il provider si aspetta indicizzabilità, con prova del tag/header e caveat su altri sistemi di controllo dell'indicizzazione.
- Nessun falso positivo per combinazioni legittime di più meta tag, istruzioni per bot diversi, `noindex` intenzionale, header multipli validi.

### 6.3 JSON-LD

- Individuare tutti gli `<script type="application/ld+json">`, fare parse JSON con limiti di dimensione/profondità, gestire oggetti, array, `@graph`, nodi annidati e riferimenti `@id`.
- `JSONLD_PARSE_ERROR`: JSON invalido nel documento osservato (con estratto circoscritto e posizione).
- `JSONLD_ENTITY_CONTRADICTION`: **solo** proprietà effettivamente incompatibili per la stessa identità e nello stesso contesto, con regola specifica e prova dei valori (es. due URL principali incompatibili); alcuni tipi/proprietà possono essere array o valori diversi legittimi. Non dedurre conflitto dalla sola presenza di due script, due nodi `Organization`, due `@id` identici o un grafo Yoast più un altro grafo.
- `JSONLD_EXPECTED_NODE_MISSING`: nodo che il provider Yoast risolve e che manca nell'output, soltanto quando identità e confronto sono sufficientemente affidabili.
- In caso di ambiguità semantica, visualizzare `inconclusive` o informazioni, **non** un errore certo.

## 7. Flusso UI e verifica

1. Nella bacheca privata: scegliere **una pagina o un articolo pubblicato**; pulsante `Scan / Analizza`.
2. Visualizzare due colonne `Expected / Atteso` e `Observed / Osservato`, con filtri canonical / robots / JSON-LD.
3. Per ogni risultato: evidenza HTTP/HTML (estratto con URL, timestamp e posizione), regola/versione, stato anomalia, causa separatamente qualificata, eventuale test suggerito.
4. `Verify / Verifica`: eseguire una **nuova** acquisizione senza modificare il sito, mantenendo i due scan come snapshot diversi; visualizzare `resolved | persists | changed | inconclusive` per ciascuna regola.
5. Export minimale JSON o CSV del rapporto con sanitizzazione; HTML completo non esportato di default. Nessuna promessa «errore risolto per tutti i crawler».

La UI e tutte le stringhe di report sono localizzate in **inglese e italiano** secondo WordPress i18n; codici interni e schema JSON rimangono in inglese.

## 8. Accesso, privacy e robustezza

- P0: `manage_options` per eseguire scansioni e leggere report; nel multisite non assumere autorizzazioni globali. Permessi delle REST route con `permission_callback` e `current_user_can()`; nonce WP per CSRF in UI, **non** come sostituto dei permessi.
- Tracciare scansioni e fallimenti senza esporre path server, stack trace, chiavi o configurazioni interne in output pubblico. Log diagnostici opzionali e disattivati di default.
- Query SQL parametrizzate, sanitizzazione degli input, escaping dell'output, dimensioni limitate e cronologia con retention. Rendere esplicita la possibilità che gli estratti HTML contengano dati pubblici che un amministratore non desidera esportare.
- Il motore non deve invocare endpoint AI, inviare dati a servizi commerciali, né esporre endpoint anonimi di scansione.
- Cache/CDN: mostrare eventuali header indicativi e non dichiarare origine certa; scansioni in intervalli diversi possono restituire varianti differenti.

## 9. Test iniziali e CI

**Dieci fixture obbligatorie** con expected snapshot, HTML + headers, finding previsto e stato della causa:

1. Canonical: due URL distinti.
2. Canonical: unico valore differente da Yoast resolved.
3. Canonical: unico valore corretto (negativo).
4. Robots: `index` in un tag e `noindex` in un altro (effetto `noindex`, non indecisione).
5. Robots: noindex via `X-Robots-Tag` non previsto da Yoast.
6. Robots: noindex intenzionale / scopes diversi (negativo).
7. JSON-LD: stesso `@id` con proprietà incompatibili dimostrabili.
8. JSON-LD: due nodi logicamente equivalenti in grafi diversi senza contraddizioni (negativo).
9. JSON-LD: `@id` condiviso come collegamento, multi-script legittimo (negativo).
10. Correzione: prima scansione anomala, seconda corretta, stato `resolved` e prove conservate.

**Test di sicurezza aggiuntivi** (non sostituiscono i dieci): redirect verso `localhost` o IP privati, DNS/host inatteso, HTML molto grande, JSON profondamente annidato, UTF-8 malformato, XXE, utente non autorizzato, nonce mancante, WordPress senza Yoast, WP-Cron indisponibile, loopback bloccato, pagine protette, risposta 403/WAF, siti con cache.

**CI proposta:** lint PHP, WordPress Coding Standards, unit test con fixture, test integrazione su matrix WordPress/PHP/Yoast effettivamente supportata, scansione dipendenze e build ZIP riproducibile. Le versioni minime precise vanno decise dopo test di compatibilità con gli hosting Netsons disponibili; non usare WP 5.6/PHP 7.4 del README legacy come requisito automatico della nuova base.

## 10. Sequenza realizzativa senza sovra-ingegnerizzazione

- **S0 — contract & fixtures:** definire DTO e dieci casi, senza UI.
- **S1 — capture:** acquisizione sicura di singola pagina e parsers con esiti `blocked/partial/failed`.
- **S2 — expected:** adattatore Yoast con casi di errore, confronto canonical e robots.
- **S3 — JSON-LD:** parser a grafo e regole conservative con negativi obbligatori.
- **S4 — report/verify:** UI IT/EN, persistenza, riesecuzione, diff.
- **S5 — release:** test sul server di sviluppo e staging autorizzati, CI, documentazione, licenza, pacchetto GPL installabile su GitHub.

**Subito dopo P0:** P1 `AI Review` multi-provider con API key proprie (OpenAI + Anthropic per primi), prompt versionati, output strutturati, approvazione prima di modifiche; modello AI mai autorizzato a inventare evidenze o alterare gli stati deterministici.

## 11. Questioni da validare in prototipo

- Compatibilità reale dei provider Yoast con archivi/filtri e con la versione installata su staging.
- Quali richieste loopback funzionano su SSD 50 e Cloud Hosting 100, senza allentare le difese SSRF.
- Dimensione corretta di body/evidence per memorizzazione e velocità su hosting condiviso.
- Normalizzazione degli URL per evitare falsi conflitti (multilingua, slash, query, encoding).
- Un insieme chiuso e sicuro di proprietà JSON-LD che costituiscano contraddizione vera: è la parte più delicata di P0.
- Come mantenere le evidenze esportabili senza includere dati non necessari.

## 12. Fonti tecniche primarie (consultate il 20 novembre 2026)

- [WordPress: wp_safe_remote_get](https://developer.wordpress.org/reference/functions/wp_safe_remote_get/)
- [WordPress: REST permission_callback](https://developer.wordpress.org/rest-api/extending-the-rest-api/adding-custom-endpoints/)
- [WordPress: Nonces](https://developer.wordpress.org/apis/security/nonces/)
- [Yoast: Surfaces API](https://developer.yoast.com/customization/apis/surfaces-api/)
- [Yoast: Metadata API](https://developer.yoast.com/customization/apis/metadata-api/)
- [Yoast: REST API](https://developer.yoast.com/customization/apis/rest-api/)
- [Google: Robots meta tag](https://developers.google.com/search/docs/crawling-indexing/robots-meta-tag)
- [Google: Canonical](https://developers.google.com/search/docs/crawling-indexing/consolidate-duplicate-urls)
- [Google: Structured data guidelines](https://developers.google.com/search/docs/appearance/structured-data/sd-policies)
