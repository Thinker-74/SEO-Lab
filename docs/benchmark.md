# Benchmark · SEO Lab 0.2.0

Confronto tra il controllo con SEO Lab e il controllo manuale su tre casi semplici: canonical difforme (fixture 02), robots da header (fixture 05), JSON-LD con nodo contraddittorio (fixture 07).

## Esito del target

Il target del 50% di riduzione riguarda un operatore umano. Le misure di questa release sono state eseguite da un agente e non valgono come tempi umani: **target non valutabile** fino a una prova eseguita da una persona.

## Misura dell'agente (non umana)

Ambiente: sviluppo locale (Docker), stessa macchina per i due bracci, nessuna rete esterna.

- **Braccio plugin** (`operatore agente`): `ScanService` reale su una risposta salvata della fixture, con trasporto e DNS di test. Il tempo include l'avvio del processo PHP.
- **Braccio manuale** (`operatore agente`): controllo con `grep`, `jq` e confronto con il valore atteso sulla stessa pagina salvata. Il tempo non include la lettura umana né la decisione.
- Tre ripetizioni per caso con ordine bilanciato (AB, BA, AB): 9 coppie in totale.

| Misura | Valore |
| --- | --- |
| Mediana braccio plugin | 27 ms |
| Mediana braccio manuale | 12 ms |
| `100 × (1 − mediana_plugin / mediana_manuale)` | −125 (il plugin è più lento in questa misura) |
| Esiti uguali alla fixture, plugin | 9 su 9 |
| Esiti uguali alla fixture, manuale | 6 su 9 per costruzione: lo script manuale fissa `no_anomaly` per la contraddizione del caso 07, quindi il dato non è una misura |

Limiti della misura: il braccio manuale è uno script e non una persona; il braccio plugin non passa dall'admin WordPress; il numero di campioni è basso. Il risultato non indica il tempo di un operatore reale.

## Protocollo per la prova umana

Da eseguire con i casi 02, 05 e 07, a coppie, con ordine bilanciato: alternare il metodo di partenza (manuale poi plugin, plugin poi manuale).

Operatore: preferibilmente un consulente SEO tecnico o uno sviluppatore WordPress esterno a SEO Lab. Chi ha sviluppato SEO Lab può provare internamente la procedura, ma il suo coinvolgimento è una possibile fonte di bias e non va contato come prova indipendente.

Condizioni: stesse condizioni e ambienti equivalenti nei due metodi; stessa finestra di misurazione; nessuna anticipazione all'operatore su anomalie attese.

1. Predisporre per ogni caso, in forma anonima, pagina o header salvati e il valore atteso Yoast. Le risposte, le anomalie previste e i criteri d'oracolo restano riservati al valutatore e non sono visibili all'operatore.
2. Cronometrare dall'inizio della verifica fino alla conclusione: stato, prova e passo di verifica successivo.
3. Metodo manuale: pagina e header, dato atteso, nessun motore.
4. Metodo plugin: Scan e report nell'admin di SEO Lab.
5. Registrare tutti gli esiti, anche errori o tempi incompleti.
6. Registrare per ogni caso: anomalie trovate, falsi positivi, qualità delle evidenze (fonte e posizione, reperibilità) e verifica della correzione.
7. Calcolare la mediana per metodo e applicare la formula sopra.

L'accuratezza precede la velocità: un tempo ridotto con anomalie mancate o falsi positivi non conta come riduzione.

Pilota: tre casi con una persona costituiscono un pilota. La dicitura «-50% nel benchmark pilota» è ammessa solo con prova umana documentata, campione, condizioni e riduzione misurata che raggiunge il target. Non si fanno proiezioni universali. Senza prova umana il target resta «non valutabile». Il pilota è facoltativo e non condiziona il rilascio P0.

Il target è raggiunto solo se la riduzione umana è pari o superiore al 50%.
