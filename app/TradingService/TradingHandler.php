<?php

namespace App\TradingService;


use Illuminate\Support\Facades\DB;

class TradingHandler
{
    

    public function IsOrderExist($istance_key, $timeframe, $lot, $side, $tp, $sl, $comment, $magnum)
    {
    
            // Concatena comment e symbol
            $fullComment = $comment . " " . $symbol;

            // Controlla se il comando esiste già nel database
            $existingCommand = DB::table('command_queues')
                ->where('istance_key', $istance_key)
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
                    'istance_key' => $istance_key,
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
                throw new CustomException("Il comando esiste già nel database.", $istance_key);
                return true;
            }
    }

    public function placeOrder($istance_key, $lot, $side, $tp, $sl, $comment, $magnum)
    {
        DB::table('command_queues')->insert([
            'istance_key' => $istance_key,
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

    public function closeOrder($istance_key, $ticket, $lot)
    {
        DB::table('command_queues')->insert([
            'istance_key' => $istance_key,
            'cmd_name' => 'close',
            'ticket' => $ticket,
            'lot' => $lot,
            'created_at' => Carbon::now('Europe/Rome')
        ]);
    }

    public function logTradingSignal($symbol, $timeframe, $istance_key, $close_values, $ema, $rsi, $breakout_detected, $breakout_direction, $entry_signal, $confirmation_candle, $tp, $sl, $entry_price) {

        Log::info('Segnale di trading:', [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'istance_key' => $istance_key,
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

    public function calculateEMA($prices, $period) {
        // Calcolo del moltiplicatore di smoothing
        $k = 2 / ($period + 1);
        
        // Inizializzazione dell'EMA con il primo prezzo della lista
        $ema = array_shift($prices);

        // Calcolo dell'EMA per il resto dei prezzi
        foreach ($prices as $price) {
            $ema = ($price * $k) + ($ema * (1 - $k));
        }

        // Restituisce l'ultimo valore dell'EMA calcolato
        return round($ema, 5);
    }

    public function calculateRSI($prices, $period) {
        $gains = 0;
        $losses = 0;

        for ($i = 1; $i < count($prices); $i++) {
            $change = $prices[$i] - $prices[$i - 1];
            if ($change > 0) {
                $gains += $change;
            } else {
                $losses -= $change;
            }
        }

        $averageGain = $gains / $period;
        $averageLoss = $losses / $period;
        if ($averageLoss == 0) {
            return 100;
        }

        $rs = $averageGain / $averageLoss;
        $rsi = 100 - (100 / (1 + $rs));

        return $rsi;
    }

    public function WaveTrendLB($istance_key, $symbol, $Clenght, $Alenght, $ObLevel1, $ObLevel2, $OsLevel1, $OsLevel2){

        // Recupera la data o il timestamp della candela con `first` a `true`
        $firstCandle = DB::table('simble_datas')
        ->where('simble_name', $symble)
        ->where('istance_key', $istance_key)
        ->where('first', true)
        ->orderBy('created_at', 'desc') // Ordina per data, più recente prima
        ->first(['created_at']); // Recupera solo la colonna `created_at`
        
        if ($firstCandle) {
           // Usa la data della candela con `first` a `true` per trovare la candela successiva
            $nextCandle = DB::table('simble_datas')
                ->where('simble_name', $symble)
                ->where('istance_key', $istance_key)
                ->where('first', false)
                ->where('created_at', '>', $firstCandle->created_at) // Trova la candela successiva
                ->orderBy('created_at', 'asc') // Ordina per data, più recente prima
                ->first();
            
            if ($nextCandle) {
            
                $avaragePrice = ($lastCandle->current_high + $lastCandle->current_low + $nextCandle->open) / 3;

                
            }
        }
    }

    public function cmd($istance_key, $cmd, $side, $lot, $magnum, $comment, $symbol, $timeframe) // $tp, $sl,
    {

        // Recupera i dati di mercato più recenti
        $marketData = DB::table('simble_datas')
            ->where('istance_key', $istance_key)
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
            ->where('istance_key', $istance_key)
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
                'istance_key' => $istance_key,
                'cmd_name' => $cmd,
                'side' => $side,
                'lot' => $lot,
                'tp' => $tp,
                'sl' => $sl,
                'magnum' => $magnum,
                'comment' => $fullComment
                'created_at' => Carbon::now('Europe/Rome')
            ]);
        } else {
            throw new CustomException("Il comando esiste già nel database.", $istanceKey);
        }
    }

}
