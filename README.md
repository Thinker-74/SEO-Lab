# SEO Lab

Plugin WordPress in sola lettura che confronta i dati SEO attesi di una pagina (valori risolti da Yoast SEO) con l'output pubblico che il sito serve davvero, e segnala le differenze con evidenza, causa probabile e istruzioni per verificarle.

> Nome di sviluppo: SEO Lab. Il nome commerciale non è ancora stato scelto.
> Stato: P0 in validazione (versione 0.2.0). Non è ancora una release stabile: vedi [docs/compatibility.md](docs/compatibility.md) per le versioni provate e i limiti.

## Cosa controlla

- **Canonical**: dichiarazioni mancanti, multiple o diverse dal valore atteso.
- **Robots**: `noindex` non previsto, dal meta robots o dall'header `X-Robots-Tag`.
- **JSON-LD**: blocchi non validi, nodi attesi assenti, contraddizioni sull'identità della pagina.
- **Diff**: dopo una correzione, il report mostra quali esiti sono risolti, persistono o cambiano.

Ogni esito riporta uno stato separato per anomalia, causa, scansione e severità. Il plugin non propone percentuali di confidenza.

## Cosa non fa

- Non modifica contenuti, impostazioni, tema o plugin del sito. Non emette canonical, robots, JSON-LD, sitemap o `robots.txt`.
- Non chiama API di intelligenza artificiale né servizi cloud. Non richiede Python né servizi esterni.
- Non analizza URL liberi: acquisisce solo il permalink di un post o una pagina pubblicati.
- Non invia cookie o credenziali. Non esegue JavaScript.

## Requisiti

- Versioni minime di WordPress e PHP non ancora dichiarate: le versioni provate sono elencate in [docs/compatibility.md](docs/compatibility.md).
- Yoast SEO: il confronto è disponibile solo per le versioni verificate (oggi 28.9). Senza Yoast o con una versione non verificata, il confronto è disattivato e il report lo dichiara.

## Installazione

1. Usa il pacchetto `seo-lab.zip` prodotto dal workflow CI (artefatto `seo-lab-zip`, con `seo-lab.zip.sha256` accanto) e verifica il checksum con `sha256sum -c seo-lab.zip.sha256`.
2. In WordPress: Plugin › Aggiungi nuovo › Carica plugin, poi attiva SEO Lab.
3. Il pannello è in Strumenti › SEO Lab.

## Uso

- **Scan**: nel pannello, sul post o sulla pagina scelti, avvia una scansione. Il plugin acquisisce la pagina pubblica una volta e confronta l'output con il valore atteso.
- **Verify**: rilancia l'acquisizione per un report esistente e mostra il diff `resolved`, `persists` o `changed`.
- **Export**: scarica il report in JSON, con un insieme di campi fisso.
- Ogni azione richiede la capability `manage_options` e un nonce legato al sito e al report. Le richieste ripetute sullo stesso sito entro 10 secondi vengono rifiutate.

## Limiti di osservazione

- Solo HTTPS o HTTP sulla stessa origine del sito, con risoluzione soltanto pubblica e verifica TLS.
- Massimo tre redirect, sempre sulla stessa origine. Timeout complessivo di 5 secondi e limite di 1 MiB sul corpo.
- Una sola acquisizione per scansione. Ogni host, IP o redirect fuori dall'origine del permalink viene rifiutato prima della richiesta.
- Un esito `inconclusive` indica che la prova non è sufficiente (per esempio per una risposta parziale o un valore non interpretabile): non è una conferma di errore.

## Archivio, retention e disinstallazione

- I report sono salvati nel database del sito. Restano per 30 giorni e al massimo 10 per contenuto; un report base usato da una verifica non viene eliminato dalla potatura.
- La disattivazione conserva i dati.
- La disinstallazione dal pannello dei plugin è un'azione esplicita dell'amministratore: elimina la tabella dei report e le opzioni di SEO Lab su ogni sito della rete. Non tocca altre tabelle o opzioni. Se l'eliminazione della tabella fallisce, o se in multisite non si riesce a leggere l'elenco dei siti, il plugin lo segnala e le opzioni restano: la disinstallazione si può ripetere.
- I testi visualizzati in admin sono in italiano e in inglese. Il catalogo italiano è `languages/seo-lab-it_IT.po`. In 0.2.0 il messaggio di errore della disinstallazione non è ancora nel catalogo: compare solo in inglese.

## Licenza

SEO Lab è distribuito con licenza **GPL-3.0-or-later** (testo in `LICENSE`).
