<?php

namespace App\TradingService;


use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\CustomException;
use Carbon\Carbon;

class TradingHandler
{
    

    public function IsOrderExist($istanceKey, $timeframe, $symbol, $cmd, $lot, $side, $tp, $sl, $comment, $magnum)
    {
    
            // Concatena comment e symbol
            $fullComment = $comment . " " . $symbol;

            // Controlla se il comando esiste già nel database
            $existingCommand = DB::table('command_queues')
                ->where('istance_key', $istanceKey)
                ->where('cmd_name', $cmd)
                ->where('side', $side)
                ->where('lot', $lot)
                ->where('tp', $tp)
                ->where('sl', $sl)
                ->where('magnum', $magnum)
                ->where('comment', $fullComment)
                ->first();
        
            // Se il comando non esiste, inseriscilo
            if (!$existingCommand) {
                DB::table('command_queues')->insert([
                    'istance_key' => $istanceKey,
                    'cmd_name' => $cmd,
                    'side' => $side,
                    'lot' => $lot,
                    'tp' => $tp,
                    'sl' => $sl,
                    'magnum' => $magnum,
                    'comment' => $fullComment,
                    'created_at' => Carbon::now("Europe/Rome")
                ]);

                return false;

            } else {
                throw new CustomException("Il comando esiste già nel database.", $istanceKey);
                return true;
            }
    }

    public function placeOrder($istanceKey, $lot, $side, $tp, $sl, $comment, $magnum)
    {
        DB::table('command_queues')->insert([
            'istance_key' => $istanceKey,
            'cmd_name' => 'open',
            'side' => $side,
            'lot' => $lot,
            'tp' => $tp,
            'sl' => $sl,
            'comment' => $comment,
            'magnum' => $magnum,
            'created_at' => Carbon::now('Europe/Rome')
        ]);

    }

    public function closeOrder($istanceKey, $ticket, $lot)
    {
        DB::table('command_queues')->insert([
            'istance_key' => $istanceKey,
            'cmd_name' => 'close',
            'ticket' => $ticket,
            'lot' => $lot,
            'created_at' => Carbon::now('Europe/Rome')
        ]);
    }

    public function logTradingSignal($symbol, $timeframe, $istanceKey, $close_values, $ema, $rsi, $breakout_detected, $breakout_direction, $entry_signal, $confirmation_candle, $tp, $sl, $entry_price) {

        Log::info('Segnale di trading:', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'istance_key' => $istanceKey,
            'close_values' => $close_values,
            'ema' => $ema,
            'rsi' => $rsi,
            'breakout_detected' => $breakout_detected,
            'breakout_direction' => $breakout_direction,
            'entry_signal' => $entry_signal,
            'confirmation_candle' => $confirmation_candle,
            'tp' => $tp,
            'sl' => $sl,
            'entry_price' => $entry_price,
        ]);
    }

    public function getDecimalPrecision($symbol) {
        // Funzione per ottenere la precisione dei decimali per un dato simbolo
        $symbol_precision = [
            'USDJPY' => 3, // Precisione per USDJPY (1 point = 0.001)
            'EURUSD' => 5, // Precisione per EURUSD (1 point = 0.00001)
            'BTCUSD' => 2, // Esempio per le criptovalute (1 point = 0.01)
            // Aggiungi altri simboli e precisioni come necessario
        ];

        return $symbol_precision[$symbol] ?? 4; // Default a 4 se non trovato
    }

    public function hasCandleChanged($newCandle, $lastCandle) {
        // Controlla se esiste una candela precedente
        if (!$lastCandle) {
            // Se non esiste una candela precedente, considera la nuova come diversa
            return true;
        }
    
        // Confronta l'ID della nuova candela con quello dell'ultima candela salvata
        if ($newCandle->id !== $lastCandle->id) {
            return true;
        }
    
        // Confronta i valori significativi delle candele
        if ($newCandle->open !== $lastCandle->open ||
            $newCandle->close !== $lastCandle->close ||
            $newCandle->high !== $lastCandle->high ||
            $newCandle->low !== $lastCandle->low) {
            return true;
        }
    
        // Se tutti i controlli precedenti falliscono, significa che la candela non è cambiata
        return false;
    }
    

    public function getLatestCandle($symbol, $timeframe, $istanceKey) {
        // Recupera l'ultima candela disponibile dal database
        $latestCandle = DB::table('simble_datas')
            ->where('simble_name', $symbol)
            ->where('time_frame', $timeframe)
            ->where('istance_key', $istanceKey)
            ->where('first', true)
            ->orderBy('id', 'desc')
            ->first();
    
        if (!$latestCandle) {
            throw new CustomException('Nessuna candela trovata per il simbolo e timeframe specificati.', $istanceKey);
        }
    
        return $latestCandle;
    }
    
    public function calculateEMA($prices, $period) {

        $emaBuffer = [];

        // Assicurati che ci siano almeno $period valori disponibili
        if (count($prices) < $period) {
            throw new InvalidArgumentException("Non ci sono abbastanza dati per calcolare l'EMA.");
        }
    
        // Calcolo del fattore di lisciatura (Smooth Factor)
        $smoothFactor = 2.0 / ($period + 1);
    
        // Inizializzazione
        $currentIndex = 0;
        $startIndex = count($prices) - 1;
    
        // Calcola il primo valore dell'EMA
        if (count($prices) > 0) {
            $emaBuffer[0] = End($prices); // Imposta il primo valore come inizializzazione
        }
    
        // Calcolo dell'EMA per i valori successivi
        while ($currentIndex < count($prices) - 1) {
            $nextIndex = $currentIndex + 1;
            
            // Calcola l'EMA corrente
            $emaBuffer[$nextIndex] = $prices[$nextIndex] * $smoothFactor + $emaBuffer[$currentIndex] * (1 - $smoothFactor);
            
            $currentIndex++;
        }
    
        // Logga tutti i valori dell'EMA
//        $emaValuesString = implode(", ", $emaBuffer);
//        Log::info("EMA values: " . $emaValuesString);
    
        // Ritorna il buffer dell'EMA
        return end($emaBuffer);
    }
    
    public function calculateSMA($prices, $period) {
        $smaBuffer = [];
    
        // Assicurati che ci siano almeno $period valori disponibili
        if (count($prices) < $period) {
            throw new InvalidArgumentException("Non ci sono abbastanza dati per calcolare la SMA.");
        }
    
        // Calcola il primo valore della SMA
        $firstSMA = array_sum(array_slice($prices, 0, $period)) / $period;
        $smaBuffer[] = $firstSMA;
    
        // Calcolo delle SMA successive
        for ($i = $period; $i < count($prices); $i++) {
            $nextSMA = $smaBuffer[$i - $period] + ($prices[$i] - $prices[$i - $period]) / $period;
            $smaBuffer[] = $nextSMA;
        }
    
        // Ritorna l'ultimo valore calcolato della SMA
        return end($smaBuffer);
    }
        
    
    public function calculateRSI($prices, $period) {
        if (count($prices) < $period) {
            throw new InvalidArgumentException("Non ci sono abbastanza dati per calcolare l'RSI.");
        }
    
        // Inizializzazione
        $gains = [];
        $losses = [];
        $rsiValues = [];
    
        // Calcola i guadagni e le perdite iniziali
        for ($i = 1; $i <= $period; $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            if ($change > 0) {
                $gains[] = $change;
                $losses[] = 0;
            } else {
                $gains[] = 0;
                $losses[] = abs($change);
            }
        }
    
        // Calcola la media iniziale dei guadagni e delle perdite
        $averageGain = array_sum($gains) / $period;
        $averageLoss = array_sum($losses) / $period;
    
        // Calcola il primo RSI
        if ($averageLoss == 0) {
            $rsiValues[] = 100;
        } else {
            $rs = $averageGain / $averageLoss;
            $rsiValues[] = 100 - (100 / (1 + $rs));
        }
    
        // Calcolo dei successivi RSI
        for ($i = $period + 1; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            $gain = $change > 0 ? $change : 0;
            $loss = $change < 0 ? abs($change) : 0;
    
            // Calcola le medie mobili esponenziali
            $averageGain = ($averageGain * ($period - 1) + $gain) / $period;
            $averageLoss = ($averageLoss * ($period - 1) + $loss) / $period;
    
            // Calcola l'RS e il RSI
            if ($averageLoss == 0) {
                $rsiValues[] = 100;
            } else {
                $rs = $averageGain / $averageLoss;
                $rsiValues[] = 100 - (100 / (1 + $rs));
            }
        }
    
        // Restituisci l'ultimo valore RSI calcolato
        return round(end($rsiValues), 2);
    }
            
    public function WaveTrendLB($istanceKey, $symbol, $Clenght, $Alenght, $ObLevel1, $ObLevel2, $OsLevel1, $OsLevel2){

        // Recupera la data o il timestamp della candela con `first` a `true`
        $firstCandle = DB::table('simble_datas')
        ->where('simble_name', $symble)
        ->where('istance_key', $istanceKey)
        ->where('first', true)
        ->orderBy('created_at', 'desc') // Ordina per data, più recente prima
        ->first(['created_at']); // Recupera solo la colonna `created_at`
        
        if ($firstCandle) {
           // Usa la data della candela con `first` a `true` per trovare la candela successiva
            $nextCandle = DB::table('simble_datas')
                ->where('simble_name', $symble)
                ->where('istance_key', $istanceKey)
                ->where('first', false)
                ->where('created_at', '>', $firstCandle->created_at) // Trova la candela successiva
                ->orderBy('created_at', 'asc') // Ordina per data, più recente prima
                ->first();
            
            if ($nextCandle) {
            
                $avaragePrice = ($lastCandle->current_high + $lastCandle->current_low + $nextCandle->open) / 3;

                
            }
        }
    }

    public function cmd($istanceKey, $cmd, $side, $lot, $magnum, $comment, $symbol, $timeframe) // $tp, $sl,
    {

        // Recupera i dati di mercato più recenti
        $marketData = DB::table('simble_datas')
            ->where('istance_key', $istanceKey)
            ->where('first', true)
            ->where('simble_name', $symbol)
            ->where('time_frame', $timeframe)
            ->orderBy('id', 'desc') // Ordina per ID in ordine decrescente per ottenere l'ultimo record
            ->first();

        if (!$marketData) {
            throw new CustomException("Dati di mercato non trovati per il simbolo: $symbol.", $istanceKey);
        }

        // Recupera current_ask e current_bid
        $currentAsk = $marketData->current_ask;
        $currentBid = $marketData->current_bid;

        if ($side == 0) { // BUY
            $priceMarket = $currentAsk;
            $tp = $priceMarket * 1.10; // TP al 10% superiore
            $sl = $priceMarket * 0.95; // SL al 5% inferiore
        } else if ($side == 1) { // SELL
            $priceMarket = $currentBid;
            $tp = $priceMarket * 0.90; // TP al 10% inferiore
            $sl = $priceMarket * 1.05; // SL al 5% superiore
        } else {
            throw new InvalidArgumentException("Lato dell'operazione sconosciuto: $side");
        }

        // Concatena comment e symbol
        $fullComment = $comment . $symbol;

        // Controlla se il comando esiste già nel database
        $existingCommand = DB::table('command_queues')
            ->where('istance_key', $istanceKey)
            ->where('cmd_name', $cmd)
            ->where('side', $side)
            ->where('lot', $lot)
            ->where('tp', $tp)
            ->where('sl', $sl)
            ->where('magnum', $magnum)
            ->where('comment', $fullComment)
            ->first();
    
        // Se il comando non esiste, inseriscilo
        if (!$existingCommand) {
            DB::table('command_queues')->insert([
                'istance_key' => $istanceKey,
                'cmd_name' => $cmd,
                'side' => $side,
                'lot' => $lot,
                'tp' => $tp,
                'sl' => $sl,
                'magnum' => $magnum,
                'comment' => $fullComment,
                'created_at' => Carbon::now("Europe/Rome")
            ]);
        } else {
            throw new CustomException("Il comando esiste già nel database.", $istanceKey);
        }
    }

}

