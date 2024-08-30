<?php

namespace App\TradingService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\TradingService\TradingHandler;
use App\Exceptions\CustomException;

class Beta extends TradingHandler
{
    private $period_ema = 200;
    private $period_rsi = 20;
    private $level_rsi = 50;
    private $stop_loss_points = 50;
    private $min_tp_distance = 30;

    private $lastCandle = null;  // Variabile per tracciare l'ultima candela
    private $break_ema_check = 0;
    private $stopEntry = false;
    private $side = null;

    public function __construct($istanceKey)
    {
        $cacheKey = 'beta_instance_' . $istanceKey . '_lastCandle';
    
        if (Cache::has($cacheKey)) {
            $cacheData = Cache::get($cacheKey);
            $this->lastCandle = $cacheData['lastCandle'] ?? null;
            $this->break_ema_check = $cacheData['break_ema_check'] ?? 0; 
        }
    }
    

    public function execute($symbol, $istanceKey, $timeframe)
    {

        // Definisci la chiave di cache specifica per l'istanza
        $cacheKey = 'beta_instance_' . $istanceKey . '_lastCandle';

        // Preleva dati da DB
        $last_closed_candle = DB::table('simble_datas')
            ->where('istance_key', $istanceKey)
            ->where('first', true)
            ->where('simble_name', $symbol)
            ->where('time_frame', $timeframe)
            ->orderBy('id', 'desc')
            ->first();

        if (!$last_closed_candle) {
            throw new CustomException('Nessun record trovato in simble_datas.', $istanceKey);
        }

        // Recupero della nuova candela dal DB
        $newCandle = $this->getLatestCandle($symbol, $timeframe, $istanceKey);

        $past_candle_json = $last_closed_candle->past_candle_json;
        $past_candles = json_decode($past_candle_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CustomException('Errore nella decodifica del JSON: ' . json_last_error_msg(), $istanceKey);
        }

        // Verifica se ci sono abbastanza candele
        $required_candles = max($this->period_ema, $this->period_rsi);
        if (count($past_candles) < $required_candles) {
            throw new CustomException('Non ci sono abbastanza candele per calcolare l\'EMA o l\'RSI.', $istanceKey);
        }

        // Estrai i valori di open e close
        $close_values = [];
        $open_values = [];
        $new_last_candle = null;

        foreach ($past_candles as $index => $candle) {
            if (isset($candle['close']) && isset($candle['open'])) {
                if ($index === 0) {
                    $new_last_candle = $candle; // Nuova ultima candela
                }
                $close_values[] = $candle['close'];
                $open_values[] = $candle['open'];
            }
        }

        $reverse_close_values = array_reverse($close_values);

        //Log::info('Numero di valori di chiusura disponibili: ' . count($reverse_close_values));
        //Log::info('CLOSE ARRAY: ' . print_r($reverse_close_values, true));
        // Calcola EMA & RSI
        $ema = $this->calculateEMA($reverse_close_values, $this->period_ema );
        //$sma = $this->calculateSMA($reverse_close_values, $this->period_ema);
        $rsi = $this->calculateRSI($reverse_close_values, $this->period_rsi);

        $open_last = $new_last_candle['open'];
        $close_last = $new_last_candle['close'];

        // Recupera la durata della cache basata sul time_frame
        $cacheDuration = $timeframe; // Imposta la durata della cache, ad esempio, in minuti

        Log::info('Dati dell\'ultima candela: ' . json_encode($new_last_candle));        
        Log::info('EMA' . $this->period_ema . ': ' . $ema);
        //Log::info('SMA' . $this->period_ema . ': ' . $sma);
        Log::info('RSI' . $this->period_rsi . ': ' . $rsi);
        //Log::info('lastCandle ID memory: ' . $this->lastCandle);

        // Recupera lastCandle dalla cache o inizializza
        Cache::get($cacheKey, [
            'break_ema_check' => $this->break_ema_check 
        ], now()->addMinutes($cacheDuration));

        // Verifica se c'è stato un cambio di candela
        if ($this->lastCandle === null || $this->hasCandleChanged($newCandle, $this->lastCandle)) {
            Log::info('Cambio candela rilevato');
            
            if ($this->lastCandle !== null) {
                // Aggiorna l'ultima candela
                $this->lastCandle = $newCandle;
    
                // Salva l'oggetto $lastCandle nella cache
                Cache::put($cacheKey, [
                    'open' => $newCandle->open,
                    'current_high' => $newCandle->current_high,
                    'current_low' => $newCandle->current_low,
                    'current_ask' => $newCandle->current_ask,
                    'current_bid' => $newCandle->current_bid,
                    'current_spread' => $newCandle->current_spread,
                    'simble_name' => $newCandle->simble_name,
                    'time_frame' => $newCandle->time_frame,
                    'lastCandle' => $lastCandle,
                    'break_ema_check' => $this->break_ema_check, 
                ], now()->addMinutes($cacheDuration));

                Log::info('break_ema_check PRE: ' . $this->break_ema_check);


                // Gestisci i passi per la strategia
                if ($this->break_ema_check === 0) {

                    // Verifica se c'è stata una rottura e determina la direzione
                    $break_direction = $this->checkBreak($ema, $open_last, $close_last);

                    Cache::get($cacheKey, [
                        'break_ema_check' => $this->break_ema_check, 
                    ], now()->addMinutes($cacheDuration));
                    
                }

                if ($break_direction === "LONG" && !$this->stopEntry) {
                    $this->stopEntry = true;
                    //$this->break_ema_check = 1;
                    $this->side = "BUY";
                    Log::warning('Break found: LONG');

                } elseif ($break_direction === "SHORT" && !$this->stopEntry) {
                    $this->stopEntry = true;
                    //$this->break_ema_check = 1;
                    $this->side = "SELL";
                    Log::warning('Break found: SHORT');
                }
                
                // Cache::get(

                Log::info('break_ema_check POST: ' . $this->break_ema_check);
                // );

                // Gestisci i passi per la strategia
                switch ($this->break_ema_check) {
                    case 1:
                        $this->handleCheckStep($cacheKey, $cacheDuration, $istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, $this->break_ema_check);
                        break;
                    case 2:
                        $this->handleCheckStep($cacheKey, $cacheDuration, $istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, $this->break_ema_check);
                        break;
                    case 3:
                        $this->handleCheckStep($cacheKey, $cacheDuration, $istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, $this->break_ema_check);
                        break;
                }
            } else {
    
                // Aggiorna la variabile statica con il nuovo valore di candela
                $this->lastCandle = $newCandle;
    
                // Salva il nuovo oggetto $lastCandle nella cache
                Cache::put($cacheKey, [
                    'open' => $newCandle->open,
                    'current_high' => $newCandle->current_high,
                    'current_low' => $newCandle->current_low,
                    'current_ask' => $newCandle->current_ask,
                    'current_bid' => $newCandle->current_bid,
                    'current_spread' => $newCandle->current_spread,
                    'simble_name' => $newCandle->simble_name,
                    'time_frame' => $newCandle->time_frame,
                    'lastCandle' => $newCandle,  // Aggiungi lastCandle
                    'break_ema_check' => $this->break_ema_check,  
                ], now()->addMinutes($cacheDuration));
                
            }

            
        } else {
            Log::info('Nessun cambio di candela rilevato.');
        }
    }
    private function checkBreak($ema, $open_last, $close_last) {
        if ($close_last > $ema && $open_last > $ema) {
            return "LONG";
        } elseif ($close_last < $ema && $open_last < $ema) {
            return "SHORT";
        }
        return "NONE";
    }

    private function checkEntry($istanceKey, $ema, $close_values, $side, $close_last, $open_last) {
        $rsi = $this->calculateRSI($close_values, $this->period_rsi);
        $tp_distance = abs($ema - $close_last);

        if ($side === "BUY") {
            if ($rsi < $this->level_rsi && $tp_distance > $this->min_tp_distance) {
                Log::warning('Entry valid LONG: RSI < ' . $this->level_rsi . ' e distanza TP > ' . $this->min_tp_distance);
                return true;
            }
        } elseif ($side === "SELL") {
            if ($rsi > $this->level_rsi && $tp_distance > $this->min_tp_distance) {
                Log::warning('Entry valid SHORT: RSI > ' . $this->level_rsi . ' e distanza TP > ' . $this->min_tp_distance);
                return true;
            }
        }

        //Log::info('Condizioni per entry non soddisfatte');
        return false;
    }

    private function handleCheckStep($cacheKey, $cacheDuration, $istanceKey, $ema, $close_values, $side, $close_last, $open_last, $step) {
        if ($this->checkEntry($istanceKey, $ema, $close_values, $side, $close_last, $open_last)) {
            $this->stopEntry = false;
            //$this->break_ema_check = 0;
            Log::warning("Entry valida - $side - RSI < {$this->level_rsi}");
            if(IsOrderExist($istanceKey, $timeframe, $symbol, $cmd, $lot, $side, $tp, $sl, $comment, $magnum))
            {
                $this->placeOrder($istanceKey, 0.1, $side, $tp, $sl, "Beta PHP Baby", 1);
            }
            // $this->trade($side);
        } else {

            $this->step = $step + 1;

            Cache::put($cacheKey, [
                'break_ema_check' => $this->step, 
            ], now()->addMinutes($cacheDuration));

            $this->stopEntry = false;
            Log::warning("Controllo non valido N°$step");
        }
    }
}
