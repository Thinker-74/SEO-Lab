# SEO Lab

Plugin WordPress per farsi trovare da Google e dalle intelligenze artificiali: SEO tecnica completa più GEO (Generative Engine Optimization), con schede di conoscenza scritte da Claude.

> Stato del progetto esistente: 0.1.2, in uso su un sito in produzione. Il repository pubblico contiene per ora documentazione e licenza, non ancora una release installabile. La futura base modulare è in progettazione.

## Cosa fa

**SEO tecnica**
- Titoli, descrizioni, canonical e robots completi (`max-snippet`, `max-image-preview`, `max-video-preview`), con esclusione automatica di archivi per data, ricerca e 404.
- Dati strutturati a grafo: WebSite, Organization/LocalBusiness, WebPage, CollectionPage, ProfilePage, Article/BlogPosting, Person, ImageObject, BreadcrumbList, FAQPage, Service, HowTo.
- Open Graph e Twitter con l'immagine reale della pagina, date e autore degli articoli, tempo di lettura.
- Sitemap XML con date, immagini e autori; `/sitemap.xml` e `/wp-sitemap.xml` rimandano all'indice.
- Redirect, monitor dei 404, editor di robots.txt, FAQ.
- Assistente di scrittura in italiano: parola chiave, lunghezze, leggibilità con indice GULPEASE.

**GEO e AI**
- `llms.txt` e `llms-full.txt` secondo la specifica di llmstxt.org.
- Schede di conoscenza per pagina, generate con Claude (API Anthropic) a richiesta e pubblicate solo dopo revisione: entità, riassunto, domande tipiche, glossario, relazioni, guide.
- Statistiche delle visite dei crawler AI (GPTBot, ClaudeBot, PerplexityBot, Google-Extended) e del traffico in arrivo dagli assistenti, senza cookie e con IP troncato.
- Dati strutturati in forma ASCII: restano corretti anche con plugin che riscrivono l'HTML in uscita (per esempio i banner dei cookie).

## Requisiti

- WordPress 5.6 o successivo, PHP 7.4 o successivo (provato su PHP 8.4).
- Per le schede AI: una chiave API Anthropic. Viene salvata cifrata nel database e usata solo quando si preme «Genera».

## Installazione

1. Scarica lo zip della release, oppure costruiscilo con `bash build.sh`.
2. In WordPress: Plugin › Aggiungi nuovo › Carica plugin.
3. Se arrivi da Yoast, Rank Math o SEOPress, usa lo strumento di migrazione prima di disattivare il vecchio plugin.

## Privacy

Con i moduli attivi di default il plugin non contatta server di terzi, salvo l'API Anthropic quando generi una scheda: in quel caso parte solo il testo pubblico della pagina. I moduli opzionali che parlano con servizi esterni (IndexNow, Google Search Console, recensioni Google) sono spenti finché non li attivi. Le statistiche AI non usano cookie e non conservano l'IP completo.

## Licenza e riconoscimenti

Il nuovo codice pubblicato in questo repository sarà distribuito con licenza **GPL-3.0-or-later** (testo GPLv3 in `LICENSE`). La versione esistente 0.1.2 deriva invece da un progetto GPL-2.0-or-later; eventuali porzioni riutilizzate mantengono i dovuti avvisi di copyright e provenienza.

- Derivato da SEOlution Suite 7.1.8 (GPL-2.0-or-later). Le modifiche sono elencate in `CHANGELOG.md`.
- Indice di leggibilità GULPEASE: Pietro Lucisano e Maria Emanuela Piemontese (GULP, Università di Roma La Sapienza, 1988).
- Specifica `llms.txt`: llmstxt.org (Answer.AI).

Sviluppato da Inferentia — inferentia.eu
