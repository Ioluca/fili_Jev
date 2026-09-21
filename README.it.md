# Fili

Plugin WordPress che trova **i link interni che mancano** e **gli articoli che raccontano due volte la stessa notizia**.

Propone, non scrive. Ogni link lo approvi tu, e si annulla con un clic.

Il giudizio lo dà [Jev](https://typesafe.ai) di TypeSafe AI, un modello che non scrive testo: risponde solo sì, no o scegli fra queste opzioni. Per questo costa pochissimo.

Sul sito dove Fili è nato, 777 articoli, il giro completo dentro WordPress ha fatto **13.705 decisioni per circa 29 centesimi di dollaro, usando 53 MB di memoria**.

*[Read me in English](README.md)*

> **Stato: 0.1.5, provato su un sito solo.** Non ancora pronto per i siti di produzione di altri. I controlli nel codice sono tarati sull'italiano; le liste inglesi sono una prima stesura non misurata.

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
- Un tetto di spesa mensile ferma tutto quando lo raggiungi, e la spesa è in vista su ogni schermata.
- Niente caratteri o script caricati da fuori: l'interfaccia usa solo ciò che c'è già in WordPress.

## Cosa abbiamo misurato, compresi gli errori

Tutti i numeri vengono da un sito vero di 777 articoli, su un banco di prova con PHP limitato a 128 MB come un hosting condiviso.

**I tempi, detti per quello che sono.** Il tempo non è di Jev, che risponde in una frazione di secondo: è l'attesa della rete.

| Come | Tempo per i link di 777 articoli |
|---|---|
| Prototipo esterno, 24 richieste insieme, via OpenRouter | 16 secondi |
| Prototipo esterno, 24 richieste insieme, API ufficiale TypeSafe | 145 secondi |
| Fili dentro WordPress, 1 richiesta alla volta | circa 12 minuti |
| Fili dentro WordPress, 4 richieste insieme (il valore di partenza) | circa 4 minuti, estrapolato da 60 articoli misurati |

Il costo è lo stesso in tutti i casi: si pagano i caratteri letti, non il tempo. Dopo il primo giro Fili lavora solo sugli articoli nuovi.

**La qualità.** La prima versione delle domande dava il **64%** di proposte da tenere. Riscrivendo le domande sui casi bocciati: **91%** (20 su 22 in un campione riletto a mano).

**La retromarcia.** Su sei articoli, tre Gutenberg e tre classici: link applicati, poi annullati, e il contenuto è tornato **identico al byte sei volte su sei**, con i blocchi intatti e nessun link dentro un altro link.

**Gli errori.**
- Usare la frequenza di una frase nel sito come misura di qualità **non funziona**: serve solo a riconoscere il boilerplate (una riga di firma presente in 430 articoli su 777).
- Di tre domande scritte per distinguere un doppione da un seguito legittimo, **due erano inutili**: una rispondeva sempre sì, l'altra sempre no. Una domanda si giudica da come si distribuiscono le risposte, non da come è scritta.
- I controlli di forma sono **per lingua**. Quelli italiani sono tarati su un sito vero; quelli inglesi sono una prima stesura non ancora misurata.
- La spesa mostrata è una **stima** calcolata sui caratteri inviati, perché l'API ufficiale non comunica il costo. Sul giro completo ha dato 29 centesimi contro i 26 misurati per altra via.

## Installazione

Copia la cartella `fili/` in `wp-content/plugins/`, attiva il plugin, inserisci la chiave in *Fili → Impostazioni*, avvia il giro.

Da riga di comando: `wp fili run`, `wp fili status`, `wp fili roundtrip <id>...` (applica, annulla e verifica che l'articolo torni identico).

## Lingue

L'interfaccia esce in **italiano** e **inglese**, e segue la lingua del sito. Le traduzioni stanno in `languages/`: per aggiungerne una, copia `fili-it_IT.po` e traducila.

I controlli nel codice (verbi, preposizioni, parole interrogative) sono per lingua, in `lang/`. La lista italiana e' tarata su un sito vero, quella inglese e' una prima stesura non ancora misurata. Su un sito in un'altra lingua quei controlli non prendono niente e la qualita' torna verso il 64%, e Fili lo dice invece di far finta di niente.

## Licenza

MIT. Usalo, modificalo, ridistribuiscilo: basta mantenere la nota di copyright.

Fili non è affiliato a TypeSafe AI. Jev è un loro prodotto.
