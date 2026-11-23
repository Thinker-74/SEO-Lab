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

## Limiti dichiarati

- Versioni minime di WordPress e PHP non dichiarate: le versioni sopra sono quelle provate, non un requisito garantito.
- Yoast SEO: il confronto è attivo solo per la versione 28.9. Altre versioni disattivano il confronto e il report lo dichiara.
- Il caso di cattura `ok` su rete reale non è verificato nell'ambiente di prova: la risoluzione DNS esterna non è disponibile lì.
- Gli esiti offline non sostituiscono le prove sul sito reale.
