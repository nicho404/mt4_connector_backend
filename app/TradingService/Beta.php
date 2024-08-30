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

    private $lastClosedCandle = null;  // Ultima candela chiusa salvata in cache
    private $break_ema_check = 0;
    private $stopEntry = false;
    private $side = null;

    public function __construct($istanceKey)
    {
        $cacheKey = 'beta_instance_' . $istanceKey . '_lastClosedCandle';

        if (Cache::has($cacheKey)) {
            $cacheData = Cache::get($cacheKey);
            $this->lastClosedCandle = $cacheData['lastClosedCandle'] ?? null;
            $this->break_ema_check = $cacheData['break_ema_check'] ?? 0;
        }
    }

    public function execute($symbol, $istanceKey, $timeframe)
    {
        // Definisci la chiave di cache specifica per l'istanza
        $cacheKey = 'beta_instance_' . $istanceKey . '_lastClosedCandle';

        // Recupera la nuova candela chiusa dal DB
        $new_last_candle = DB::table('simble_datas')
            ->where('istance_key', $istanceKey)
            ->where('first', true)
            ->where('simble_name', $symbol)
            ->where('time_frame', $timeframe)
            ->orderBy('id', 'desc')
            ->first();

        if (!$new_last_candle) {
            throw new CustomException('Nessun record trovato in simble_datas.', $istanceKey);
        }

        // Recupera l'ultima candela chiusa dalla cache
        if (Cache::has($cacheKey)) {
            $cacheData = Cache::get($cacheKey);
            $this->lastClosedCandle = $cacheData['lastClosedCandle'] ?? null;
            $this->break_ema_check = $cacheData['break_ema_check'] ?? 0;
        }


        // Verifica se c'è stato un cambio di candela
        if ($this->lastClosedCandle === null || $this->hasCandleChanged($this->lastClosedCandle, $new_last_candle)) {
            Log::info('Cambio candela rilevato');

            // Aggiorna lastClosedCandle con new_last_candle
            $this->lastClosedCandle = $new_last_candle;

            // Salva l'oggetto $lastClosedCandle nella cache
            Cache::put($cacheKey, [
                'open' => $new_last_candle->open,
                'current_high' => $new_last_candle->current_high,
                'current_low' => $new_last_candle->current_low,
                'current_ask' => $new_last_candle->current_ask,
                'current_bid' => $new_last_candle->current_bid,
                'current_spread' => $new_last_candle->current_spread,
                'simble_name' => $new_last_candle->simble_name,
                'time_frame' => $new_last_candle->time_frame,
                'lastClosedCandle' => $new_last_candle,
                'break_ema_check' => $this->break_ema_check,
            ], now()->addMinutes($timeframe));


            $past_candle_json = $new_last_candle->past_candle_json;
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
            $new_candle = null;
    
            foreach ($past_candles as $index => $candle) {
                if (isset($candle['close']) && isset($candle['open'])) {
                    if ($index === 0) {
                        $new_candle = $candle; // Nuova ultima candela
                    }
                    $close_values[] = $candle['close'];
                    $open_values[] = $candle['open'];
                }
            }

            $reverse_close_values = array_reverse($close_values);
            $ema = $this->calculateEMA($reverse_close_values, $this->period_ema, $istanceKey);
            $rsi = $this->calculateRSI($reverse_close_values, $this->period_rsi, $istanceKey);

            $open_last = $open_values[0];
            $close_last = $close_values[0];
            Log::info('Last open: ' . $open_last . ' e lats close: ' . $close_last);

            // Verifica se c'è stata una rottura e determina la direzione
            $break_direction = $this->checkBreak($ema, $open_last, $close_last);

            if ($break_direction === "LONG" && !$this->stopEntry) {
                $this->stopEntry = true;
                $this->side = "BUY";
                Log::warning('Break found: LONG');

            } elseif ($break_direction === "SHORT" && !$this->stopEntry) {
                $this->stopEntry = true;
                $this->side = "SELL";
                Log::warning('Break found: SHORT');
            }

            // Gestisci i passi per la strategia
            switch ($this->break_ema_check) {
                case 1:
                    $this->handleCheckStep($cacheKey, $timeframe, $istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, $this->break_ema_check);
                    break;
                case 2:
                    $this->handleCheckStep($cacheKey, $timeframe, $istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, $this->break_ema_check);
                    break;
                case 3:
                    $this->handleCheckStep($cacheKey, $timeframe, $istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, $this->break_ema_check);
                    break;
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

        return false;
    }

    private function handleCheckStep($cacheKey, $cacheDuration, $istanceKey, $ema, $close_values, $side, $close_last, $open_last, $step) {
        if ($this->checkEntry($istanceKey, $ema, $close_values, $side, $close_last, $open_last)) {
            $this->stopEntry = false;
            Log::warning("Entry valida - $side - RSI < {$this->level_rsi}");
            if(IsOrderExist($istanceKey, $timeframe, $symbol, $cmd, $lot, $side, $tp, $sl, $comment, $magnum)) {
                $this->placeOrder($istanceKey, 0.1, $side, $tp, $sl, "Beta PHP Baby", 1);
            }
        } else {
            $this->break_ema_check = $step + 1;
            Cache::put($cacheKey, [
                'break_ema_check' => $this->break_ema_check, 
            ], now()->addMinutes($cacheDuration));
            $this->stopEntry = false;
            Log::warning("Controllo non valido N°$step");
        }
    }
}
