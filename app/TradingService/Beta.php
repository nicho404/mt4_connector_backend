<?php

namespace App\TradingService;

use App\TradingService\TradingHandler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Exceptions\CustomException;


class Beta extends TradingHandler {         // MANCA BE, FUNZIONI PER LOTTI, FASCE ORARIE e ONE-TRADE-AT-TIME  

    // Parametri configurabili
    private $period_ema         = 200;            // Periodo per EMA
    private $period_rsi         = 20;             // Periodo per RSI
    private $level_rsi          = 50;              // Livello per RSI
    private $stop_loss_points   = 50;        // Stop Loss in punti (points)
    private $min_tp_distance    = 30;         // Distanza minima di TP in punti (points)

    public function Execute($symbol, $istanceKey, $timeframe) {
        // Preleva dati da DB
        $last_closed_candle = DB::table('simble_datas')
            ->where('istance_key', $istanceKey)
            ->where('first', true)
            ->where('simble_name', $symbol)
            ->where('time_frame', $timeframe)
            ->orderBy('id', 'desc') // Ordina per ID in ordine decrescente per ottenere l'ultimo record
            ->first();

        if (!$last_closed_candle) {
            throw new CustomException('Nessun record trovato in simble_datas.', $istanceKey);
        }

        // Estrai la stringa JSON dalla colonna `past_candle_json`
        $past_candle_json = $last_closed_candle->past_candle_json;

        // Decodifica la stringa JSON in un array PHP
        $past_candles = json_decode($past_candle_json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new CustomException('Errore nella decodifica del JSON: ' . json_last_error_msg(), $istanceKey);
        }

        // Accedi ai valori di `open` e `close` di ogni candela
        $close_values = [];
        $open_values = [];
        foreach ($past_candles as $candle) {
            if (isset($candle['close']) && isset($candle['open'])) {
                $close_values[] = $candle['close'];
                $open_values[] = $candle['open'];
            }
        }

        // Calcola EMA e RSI
        $ema = $this->calculateEMA($close_values, $this->period_ema);
        $rsi = $this->calculateRSI($close_values, $this->period_rsi);

        // Identifica il breakout della EMA
        $breakout_detected = false;
        $breakout_direction = null; // 'up' o 'down'
        $confirmation_candle = null;
        $breakout_candle_index = null;

        for ($i = 1; $i <= 3; $i++) {
            if (count($close_values) - $i < 0) break;
            $current_close = $close_values[count($close_values) - $i];
            $previous_close = $close_values[count($close_values) - $i - 1];
            $current_open = $open_values[count($open_values) - $i];
            $previous_open = $open_values[count($open_values) - $i - 1];

            if ($previous_close < $ema && $current_close > $ema && $current_open > $ema) {
                $breakout_detected = true;
                $breakout_direction = 'up';
                $breakout_candle_index = count($past_candles) - $i;
                $confirmation_candle = $past_candles[$breakout_candle_index];
                break;
            } elseif ($previous_close > $ema && $current_close < $ema && $current_open < $ema) {
                $breakout_detected = true;
                $breakout_direction = 'down';
                $breakout_candle_index = count($past_candles) - $i;
                $confirmation_candle = $past_candles[$breakout_candle_index];
                break;
            }
        }

        // Verifica la conferma del breakout
        $confirmation_valid = false;
        if ($breakout_detected && $breakout_candle_index !== null) {
            for ($i = 1; $i <= 3; $i++) {
                if ($breakout_candle_index + $i >= count($past_candles)) break;
                $confirm_candle = $past_candles[$breakout_candle_index + $i];
                if ($confirm_candle['close'] != $ema) {
                    $confirmation_valid = true;
                    break;
                }
            }
        }

        // Ottieni la precisione del simbolo
        $decimal_precision = $this->getDecimalPrecision($symbol);

        // Ottieni prezzi correnti di ask e bid
        $current_ask = $last_closed_candle->current_ask;
        $current_bid = $last_closed_candle->current_bid;

        // Calcola TP e SL
        $tp = $ema;
        $entry_signal = null;  // long o short
        $entry_price = null;
        $sl = null;

        if ($breakout_detected && $confirmation_valid) {
            if ($breakout_direction == 'up' && $rsi < $this->level_rsi) {
                $entry_signal = 'short';
                $entry_price = $current_bid; // Prezzo di ingresso per short
                $sl = $entry_price + ($this->stop_loss_points * pow(10, -$decimal_precision)); // SL a 5 punti sopra
            } elseif ($breakout_direction == 'down' && $rsi > $this->level_rsi) {
                $entry_signal = 'long';
                $entry_price = $current_ask; // Prezzo di ingresso per long
                $sl = $entry_price - ($this->stop_loss_points * pow(10, -$decimal_precision)); // SL a 5 punti sotto
            }

            // Verifica la distanza del TP
            $tp_distance = abs($tp - $entry_price);
            $min_points = $this->min_tp_distance * pow(10, -$decimal_precision); // Distanza minima di TP in base alla precisione
            if ($tp_distance < $min_points) {
                $entry_signal = null; // Invalidazione dell'entrata
            }
        }

        //controllo se il segnale esiste
        $this->IsOrderExist($istanceKey, $timeframe, $lot, $side, $tp, $sl, $comment, $magnum);

        //invio del segnale
        $this->placeOrder($istanceKey, $lot, $side, $tp, $sl, $comment, $magnum);        //$this->cmd($istanceKey, 'open', 0, 0.1, 123456, 'Beta ', $symbol, $timeframe);

        // Log il segnale
        $this->logTradingSignal($symbol, $timeframe, $istanceKey, $close_values, $ema, $rsi, $breakout_detected, $breakout_direction, $entry_signal, $confirmation_candle, $tp, $sl, $entry_price);
    
        // BE e gestione operazioni
        // ...

    }

}   

