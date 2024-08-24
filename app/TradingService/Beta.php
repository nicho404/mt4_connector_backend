<?php

namespace App\TradingService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

    public function execute($symbol, $istanceKey, $timeframe)
    {
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
        $sma = $this->calculateSMA($reverse_close_values, $this->period_ema);
        $rsi = $this->calculateRSI($reverse_close_values, $this->period_rsi);

        $open_last = $new_last_candle['open'];
        $close_last = $new_last_candle['close'];

        Log::info('Dati dell\'ultima candela: ' . json_encode($new_last_candle));        
        Log::info('EMA' . $this->period_ema . ': ' . $ema);
        Log::info('SMA' . $this->period_ema . ': ' . $sma);
        Log::info('RSI' . $this->period_rsi . ': ' . $rsi);

        // Verifica se c'è stato un cambio di candela
        if ($this->lastCandle === null || $this->hasCandleChanged($newCandle, $this->lastCandle)) {

            if ($this->lastCandle !== null) {

                Log::info('Cambio candela rilevato');

                // Aggiorna l'ultima candela
                $this->lastCandle = $newCandle;

                // Verifica se c'è stata una rottura e determina la direzione
                $break_direction = $this->checkBreak($ema, $open_last, $close_last);

                if ($break_direction === "LONG" && !$this->stopEntry) {
                    $this->stopEntry = true;
                    $this->break_ema_check = 1;
                    $this->side = "BUY";
                    Log::warning('Direzione del breakout: LONG');

                } elseif ($break_direction === "SHORT" && !$this->stopEntry) {
                    $this->stopEntry = true;
                    $this->break_ema_check = 1;
                    $this->side = "SELL";
                    Log::warning('Direzione del breakout: SHORT');
                }

                // Gestisci i passi per la strategia
                switch ($this->break_ema_check) {
                    case 1:
                        $this->handleCheckStep($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, 1);
                        break;
                    case 2:
                        $this->handleCheckStep($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, 2);
                        break;
                    case 3:
                        $this->handleCheckStep($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last, 3);
                        break;
                }
            } else {
                // Aggiorna la variabile statica con il nuovo valore di candela
                $this->lastCandle = $newCandle;

                Log::info('Inizializzazione dell\'ultima candela: ', [
                    'id' => $this->lastCandle->id,
                    'symbol' => $this->lastCandle->simble_name,
                    'timeframe' => $this->lastCandle->time_frame,
                    'open' => $this->lastCandle->open,
                    'high' => $this->lastCandle->current_high,
                    'low' => $this->lastCandle->current_low
                ]);
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
                Log::warning('Condizioni per BUY soddisfatte: RSI < ' . $this->level_rsi . ' e distanza TP > ' . $this->min_tp_distance);
                return true;
            }
        } elseif ($side === "SELL") {
            if ($rsi > $this->level_rsi && $tp_distance > $this->min_tp_distance) {
                Log::warning('Condizioni per SELL soddisfatte: RSI > ' . $this->level_rsi . ' e distanza TP > ' . $this->min_tp_distance);
                return true;
            }
        }

        Log::info('Condizioni per entry non soddisfatte');
        return false;
    }

    private function handleCheckStep($istanceKey, $ema, $close_values, $side, $close_last, $open_last, $step) {
        if ($this->checkEntry($istanceKey, $ema, $close_values, $side, $close_last, $open_last)) {
            $this->stopEntry = false;
            $this->break_ema_check = 0;
            Log::warning("Entry valida - $side - RSI < {$this->level_rsi}");
            if(IsOrderExist($istanceKey, $timeframe, $symbol, $cmd, $lot, $side, $tp, $sl, $comment, $magnum))
            {
                $this->placeOrder($istanceKey, 0.1, $side, $tp, $sl, "Beta PHP Baby", 1);
            }
            // $this->trade($side);
        } else {
            $this->break_ema_check = $step + 1;
            $this->stopEntry = false;
            Log::warning("Controllo non valido N°$step");
        }
    }
}
