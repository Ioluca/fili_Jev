# Fili

Plugin WordPress che trova **i link interni che mancano** e **gli articoli che raccontano due volte la stessa notizia**.

Propone, non scrive. Ogni link lo approvi tu, e si annulla con un clic.

Il giudizio lo dà [Jev](https://typesafe.ai) di TypeSafe AI, un modello che non scrive testo: risponde solo sì, no o scegli fra queste opzioni. Per questo è veloce e costa pochissimo. Sul sito dove Fili è nato, 777 articoli: **12.404 decisioni in 16 secondi, 26 centesimi di dollaro**.

> Stato: versione 0.1, in prova su un sito vero. Non ancora pronto per la produzione di altri.

## Cosa fa

1. **Legge** gli articoli pubblicati e costruisce un indice in tabelle sue.
2. **Propone** i link: per ogni articolo trova i più affini e chiede a Jev se il legame regge e quale frase, *già scritta nell'articolo*, può portarlo.
3. **Tu rivedi**: ogni proposta è mostrata dentro la frase in cui vivrebbe. Tieni o butta.
4. **Applica** solo ciò che hai approvato. Ogni modifica diventa una revisione di WordPress.
5. **Segnala i doppioni**: gruppi di articoli sulla stessa notizia. Li mostra soltanto, non unisce mai niente.

## Le regole

**Sul contenuto**
- Non scrive mai senza approvazione. Appena installato ha la *sicura inserita*: può solo proporre.
- L'ancora è testo che esiste già, parola per parola. Mai dentro un link, un titolo, una citazione, una didascalia, del codice, uno shortcode.
- Al massimo 3 link nuovi per articolo (si cambia), mai verso sé stesso, mai se il link c'è già, mai fra doppioni.
- Ogni link si annulla, e l'articolo torna identico al byte.

**Sulla qualità**
- Al codice le regole che una macchina verifica meglio (un verbo nell'ancora, un frammento troncato, il boilerplate del sito). A Jev solo la lettura.
- **La soglia si misura sul tuo sito**: dopo 20 proposte giudicate, Fili calcola la soglia sulle tue decisioni. Non esiste un numero buono per tutti.

**Sulla riservatezza**
- Escono solo titolo e testo di contenuti **già pubblici**. Bozze e privati non vengono mai letti.
- Fili parla con un solo servizio: quello che scegli (TypeSafe o OpenRouter), con la tua chiave. **Nessuna telemetria.** Si verifica in un file solo: `includes/class-fili-jev.php`.
- La chiave si può definire in `wp-config.php` (`define( 'FILI_API_KEY', '...' );`) oppure nelle impostazioni, dove non viene mai rimostrata per intero.
- Un tetto di spesa mensile ferma tutto quando lo raggiungi.

## Cosa abbiamo misurato, compresi gli errori

- La prima versione delle domande dava il **64%** di proposte da tenere. Riscrivendo le domande sui casi bocciati: **91%**.
- Usare la frequenza di una frase nel sito come misura di qualità **non funziona**: serve solo a riconoscere il boilerplate.
- Di tre domande scritte per distinguere un doppione da un seguito legittimo, **due erano inutili**: una rispondeva sempre sì, l'altra sempre no. Una domanda si giudica da come si distribuiscono le risposte, non da come è scritta.
- I controlli di forma sono **per lingua**. Quelli italiani sono tarati su un sito vero; quelli inglesi sono una prima stesura non ancora misurata.

## Installazione

Copia la cartella `fili/` in `wp-content/plugins/`, attiva il plugin, inserisci la chiave in *Fili → Impostazioni*, avvia il giro.

Da riga di comando: `wp fili run`, `wp fili status`, `wp fili roundtrip <id>...` (applica, annulla e verifica che l'articolo torni identico).

## Licenza

MIT. Usalo, modificalo, ridistribuiscilo: basta mantenere la nota di copyright.

Fili non è affiliato a TypeSafe AI. Jev è un loro prodotto.
