# ::PHP TIMESHEET PARSER::

## A cosa serve?
Si tratta di uno script in PHP che interpreta il file timesheet.txt al fine di ricavare da esso la somma delle ore svolte nei vari progetti; e formattare le attività segnate in ciascuna riga al fine di eseguire un copia-incolla delle informazioni.

## Cosa richiede?
- PHP >= 7.4

## Come funziona?
Lo script analizza il file e da esso ricava alcune informazioni, quali: progetto, ore segnate per ciasuna attività, calcolo di queste ore, mese di riferimento. Al fine di una corretta lettura il file di testo deve seguire un certo formato.

All'interno del file "timesheet.php", a riga 46, è possibile impostare il percorso del file da importare (al momento si aspetta che il file sia nella stessa cartella dello script PHP).

Usando il file timesheet.txt di esempio si notano delle sezioni specifiche per ciascuna riga:
- ################################################## > usato come stacco per dividere i vari mesi (ignorato in fase di elaborazione, è solo di abbellimento);
- [11/24 09:15] > questa è la riga che usa lo script per capire che sta per elaborare una giornata e ne ricava la data (11/24) tramite regex;
- Progetto 1: @smartworking: attività svolta: 3h | #secondaAttività: attivtà svolta: 1h > questa riga da indicazione di tutte le attività svolte, con relative tempistiche, di un progetto ("Progetto 1"). Lo script da questa riga capirà quale è il progetto a cui abbiamo assegnato le ore e le userà per fare un conteggio totale. Inserendo il simbolo del cancelletto (#) lo script riconoscerà che questo è l'identificativo di un task e alla fine dell'elaborazione terrà conto del conteggio di tutte le ore per questo specifico task.

E' possibile aggiunge quanti progetti si vogliono, purché questi siano uno per riga, come riportato nel file timesheet.txt.

In fase di conteggio ore lo script convertirà i minuti come segue:
- 15m = 25
- 30m = 50
- 45m = 75
- 60m = 0

Nel caso il valore dei minuti non dovesse essere tondo come elencato, il valore mostrato sarà con virgola.

Quando si esegue lo script senza parametri questo in automatico mosterà gli orari del mese corrente e userà come file di riferimento quello che si chiama "timesheet.txt".

Da CLI è inoltre possibile passare i seguenti parametri:
- period: filltro per trovare le attività segnate nel mese che ci interessa, definito con un valore a due cifre;
- file: il nome del file da dover aprire in alternativa a timesheet.txt.

### Da eseguire tramite comando
`php timesheet.php (period="NN") (file="file.txt")`

### Da eseguire tramite file CMD

**Linux:**
Si può creare un file .sh per eseguire in automatico il file php, come da esempio:
`````
#!bin/bash
clear
TIMESHEET_FILE_TEXT="/abs/path/to/timesheet/timesheet.txt"
TIMESHEET_FILE_PHP="/abs/path/to/timesheet/timesheet.php"
nano "${TIMESHEET_FILE_TEXT}"
php "${TIMESHEET_FILE_PHP}" #period=09
`````

**Windows:**
Da Windows è possibile fare un file bat che può sfruttare il file .sh tramite WSL2 oppure creare un bat per eseguire direttamente il codice.

Nel primo caso lo script sarebbe fatto così:
`````
@echo off
:timesheet
cls
bash "/abs/path/to/timesheet/timesheet.sh"
set /P restart="Restart (y/*)? "
if "%restart%" == "y" (
  GOTO timesheet
) else (
  exit
)
`````