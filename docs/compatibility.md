# Compatibilità · SEO Lab 0.2.0

Prove del 2026-11-21, ambiente di sviluppo locale (Docker). Ogni riga indica il contesto e il tipo di prova: un esito non si estende ad altri contesti.

## Matrice

| Contesto | Versioni | Prova | Esito |
| --- | --- | --- | --- |
| PHP CLI | 7.4.33 | Lint di tutti i file PHP, guardia di uninstall, suite S0, S1, S2, S3, S4 offline; fixture 01-10 | 72 + 656 + 656 + 311 + 54 controlli superati, 0 falliti. Fixture 01-10 coperte |
| PHP CLI | 8.3.6 | Stessa suite | Superata, stessi conteggi |
| WordPress e PHP web (immagine `wordpress:php8.4-apache`) | WordPress 7.1.5, PHP 8.4.28, Yoast SEO 28.9 | Pagina admin, Scan, Verify, export, nonce, permessi, IT/EN, multisite a due siti; finestra senza scritture Yoast | 19 controlli superati (S2), 45 superati (S4 con checkout), 50 superati (S4 con pacchetto ZIP, incluso uninstall) |
| WordPress senza Yoast | WordPress 7.1.5, PHP 8.4.28 | Confronto disattivato con motivo dedicato, nessun errore | Superata nella fase iniziale della prova S4 |
| WordPress multisite | due siti, admin di un solo sito | Permessi, tabella e report isolati; uninstall su due siti e su un sito senza tabella | Superata |
| Cattura da permalink pubblico | ambiente locale isolato | Il capturer blocca l'host privato dell'ambiente di prova | Esito `blocked` come atteso. Il caso `ok` è verificato offline con trasporto di test, non in rete |
| WordPress 7.1.5 e PHP 8.4.28, sonda da STDIN (`wp eval-file -`, senza plugin né tema) | Ambiente locale isolato | Pagina pubblicata, bozza, ID inesistente, ID non valido, ID assente | Cinque casi `INCONCLUSIVE` con motivo atteso, zero richieste web. Il caso `GO` è verificato offline con trasporto di test (22 controlli) |
| Hosting condiviso | PHP 8.4 (CLI) | Sonda da STDIN su una pagina pubblicata | GO |
| Revisione AI OpenAI `gpt-6-luna`, connettore OpenAI 1.3.0 | WordPress 7.1.5, PHP 8.4.28, rete Docker `none` | Offline con trasporto simulato sul Core reale e sul percorso `WordPressReviewProvider::review()`: `reasoning.effort=none`, cap 768, modello esatto, nessuno strumento; risposta `completed` valida; `incomplete` per `max_output_tokens` → `output_truncated`; terminazione `content_filter` → `invalid_response` | Offline con trasporto simulato. Non è una prova live |
| Revisione AI OpenAI, prova live storica D2 (2026-11-22) | Connettore OpenAI 1.3.0 | Il POST di prova portava `reasoning.effort=none` iniettato dal probe, non dal prodotto | Riscontro di trasporto con quel payload. Non qualifica il comportamento di prodotto, che il profilo ora imposta in modo esplicito |
| Revisione AI Anthropic `claude-haiku-5-5`, prova live storica A-004 | Connettore Anthropic 1.0.5, fixture sintetica 07, cap 768 | Tre item con `stop_reason=end_turn`; 658 di 768 token di output | Riscontro storico su quella sola fixture. Non dimostra sufficienza del cap per altre fixture, qualità del ritocco o costo atteso |

## Limiti dichiarati

- Versioni minime di WordPress e PHP non dichiarate: le versioni sopra sono quelle provate, non un requisito garantito.
- Yoast SEO: il confronto è attivo solo per la versione 28.9. Altre versioni disattivano il confronto e il report lo dichiara.
- Il caso di cattura `ok` su rete reale non è verificato nell'ambiente di prova: la risoluzione DNS esterna non è disponibile lì.
- Gli esiti offline non sostituiscono le prove sul sito reale.
- Revisione AI OpenAI: il perimetro qualificato offline è solo `gpt-6-luna` con connettore 1.3.0 e WordPress 7.1.5. Altri ID OpenAI e altre versioni del connettore non sono qualificati: per loro l'output a 768 token non è verificato, e l'anteprima lo dichiara.
- Revisione AI OpenAI: per `gpt-6-luna` la richiesta usa `reasoning.effort=none`, che secondo la scheda ufficiale OpenAI disattiva il ragionamento (verifica non ripetuta sul provider). Per gli altri ID i token di ragionamento, se presenti, rientrano nel cap di output.
- Revisione AI OpenAI: una risposta incompleta è riconosciuta solo tramite il codice `prompt_token_limit_reached` del Core. Ogni terminazione diversa da `stop` scarta la risposta come non valida.
- Revisione AI, entrambi i provider: la conferma può generare addebiti secondo la tariffa del provider anche quando la revisione non restituisce risultati. Le cifre di costo non sono mostrate nell'anteprima.
- Revisione AI Anthropic, troncamento (P-001): il segnale dedicato è solo `stop_reason=max_tokens` in una risposta HTTP 200 con messaggio strutturato `type=message`, su una sola POST ammessa, con corpo entro il limite di risposta. Esito `output_truncated`, con avviso dedicato e scarto integrale del testo. Gli altri stop reason, incluse `model_context_window_exceeded` e `refusal`, non hanno codice dedicato e seguono il percorso generico (`provider_error` o `invalid_response`, a seconda di come il connettore li riporta). Questo comportamento non è verificato per quelle terminazioni.
- Revisione AI, limite di output (P-002): il cap di output è 768 token. Lo schema ammette fino a otto item. Il prompt chiede due o tre item concisi e prioritizzati, ma è una preferenza e non un vincolo: il validatore resta a otto. Il budget può esaurirsi anche su una risposta che lo schema ammette: in quel caso il risultato può avere meno item del previsto, o nessuno, e il costo resta addebitabile.
- Revisione AI, prove di questo ciclo: offline e simulate, con trasporto doppio sul Core WordPress reale, rete Docker `none` e connettore ufficiale fissato. Le prove live storiche A-004 (Anthropic) e D2 (OpenAI) restano circoscritte alle loro condizioni e non estendono il perimetro.
- Revisione AI OpenAI, condizioni della prova nativa: WordPress 7.1.5, PHP 8.4.28, connettore OpenAI 1.3.0, `gpt-6-luna`, `reasoning.effort=none`, cap 768, nessuno strumento, risposta simulata sulla POST `/v1/responses`. La prova non riproduce chiamate al provider reale né altri ID o versioni.
