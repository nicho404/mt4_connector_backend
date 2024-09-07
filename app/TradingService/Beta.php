<?php

namespace App\TradingService;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\TradingService\TradingHandler;
use App\Exceptions\CustomException;

class Beta extends TradingHandler
{
    private $period_ema = 150;
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

        // Recupera l'ultima candela chiusa dalla cache
        if (Cache::has($cacheKey)) {
            $cacheData = Cache::get($cacheKey);
            $this->lastClosedCandle = $cacheData['lastClosedCandle'] ?? null;
            $this->break_ema_check = $cacheData['break_ema_check'] ?? 0;
            $this->stopEntry = $cacheData['stopEntry'] ?? false;
            $this->side = $cacheData['side'] ?? null;
        }
    }

    public function execute($symbol, $istanceKey, $timeframe)
    {
        // Definisci la chiave di cache specifica per l'istanza
        $cacheKey = 'beta_instance_' . $istanceKey . '_lastClosedCandle';



        // Recupera l'ultima candela chiusa dalla cache
        if (Cache::has($cacheKey)) {
            $cacheData = Cache::get($cacheKey);
            $this->lastClosedCandle = $cacheData['lastClosedCandle'] ?? null;
            $this->break_ema_check = $cacheData['break_ema_check'] ?? 0;
            $this->stopEntry = $cacheData['stopEntry'] ?? false;
            $this->side = $cacheData['side'] ?? null;
        }

        // recupera info su account
        $accountData = DB::Table('account_datas')
                ->where('istance_key', $istanceKey)
                #->where('account_number', $accountNumber)
                ->orderBy('id', 'desc')
                ->first();

        // ottieni il Balance aggiornato
        $balance = (float) $accountData->balance;

        // Recupera la nuova candela chiusa dal DB
        $new_last_candle = $this->getLatestCandle($symbol, $timeframe, $istanceKey);

        // Verifica se c'è stato un cambio di candela
        if ($this->lastClosedCandle === null || $this->hasCandleChanged($this->lastClosedCandle, $new_last_candle)) {
            Log::info('Cambio candela rilevato');

            // Log delle candele old e new
            if ($this->lastClosedCandle !== null) {
            Log::info('Old Candle: ' . $this->formatCandleData($this->lastClosedCandle));
            }
            Log::info('New Candle: ' . $this->formatCandleData($new_last_candle));

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
                //'break_ema_check' => $this->break_ema_check,
            ], now()->addMinutes($timeframe*2));


            // Recupera Past Candle JSON e decod.
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

            // Estrai i valori di chiusura e apertura
            $close_values = $this->getCandleClosePrices($past_candles);
            $open_values = $this->getCandleOpenPrices($past_candles);

            $reverse_close_values = array_reverse($close_values); //invertiamo ordine array per indicatori

            // calcola i valori di EMA e RSI
            $ema = $this->calculateEMA($reverse_close_values, $this->period_ema, $istanceKey);
            $rsi = $this->calculateRSI($reverse_close_values, $this->period_rsi, $istanceKey);

            $open_last = $open_values[0];
            $close_last = $close_values[0];
            Log::info('ema ' . $this->period_ema . ': ' . $ema . ' e rsi: ' . $rsi . ' - Last open: ' . $open_last . ' e last close: ' . $close_last);

            // Verifica se c'è stata una rottura e determina la direzione
            $this->break_direction = $this->checkBreak($ema, $open_last, $close_last);
            // Cache::put($cacheKey, [

            //     'break_direction' => $this->break_direction

            // ])

            // Rilevato rimbaldo invalidante
            if(($this->break_direction ==="LONG" && $this->stopEntry === true) or ($this->break_direction ==="SHORT" && $this->stopEntry === true)) {

                $this->stopEntry = false;

                Log::debug("Invalid Break, rimbalzo dopo Valid Break " . $this->break_direction);

                $this->break_ema_check = 0;
                Cache::put($cacheKey, [
                    'stopEntry' => $this->stopEntry,
                    'break_ema_check' => $this->break_ema_check,
                ], now()->addMinutes($timeframe*2));

            }

            
            // se si verifica la condizione di rottura EMA x LONG
            elseif ($this->break_direction === "LONG" && $this->stopEntry === false) {
                $this->stopEntry = true;

                $this->break_ema_check = 1;
                $this->side = "BUY";

                Cache::put($cacheKey, [
                    'stopEntry' => $this->stopEntry,
                    'break_ema_check' => $this->break_ema_check,
                    'side' => $this->side,                                  ####################
                ], now()->addMinutes($timeframe*2));


                
                Log::debug('Break found: LONG');
            } 

            // se si verifica la condizione di rottura EMA x SHORT
            elseif ($this->break_direction === "SHORT" && $this->stopEntry === false) {
                $this->stopEntry = true;

                $this->break_ema_check = 1;
                $this->side = "SELL";

                Cache::put($cacheKey, [
                    'stopEntry' => $this->stopEntry,
                    'break_ema_check' => $this->break_ema_check,
                    'side' => $this->side,                                  ########################
                ], now()->addMinutes($timeframe*2));


                
                Log::debug('Break found: SHORT');
            }


            // Recupera n° step dalla cache
            if (Cache::has($cacheKey)) {
                $cacheData = Cache::get($cacheKey);
                $this->break_ema_check = $cacheData['break_ema_check'] ?? 0;
                $this->side = $cacheData['side'] ?? null;

                Log::info('n° step ottenuto da cache: ' . $this->break_ema_check . ' e side: ' . $this->side);
            }

            #calculateLotsize($balance, $RiskPercent, $tickValue, $stop_loss_points, $symbol) --> calcola lottaggio operazione, manca RiskPErcent, TickValue e Balance

            // 1° CHECK BUY
            else if ($this->break_ema_check === 1 && $this->side === "BUY") {

                if (($rsi < $level_rsi) && (CheckEntry($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last))) {
                    $this->stopEntry = false;
                    $this->break_ema_check = 0;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Entry valid - Long - RSI < " . $level_rsi);
                    //EFFETUA ORDINE
                    Log::debug("TRADE APERTO"); 
                    //Trade(side);}

                }else{  // passiamo al controllo n°2
                    $this->stopEntry = false;
                    $this->break_ema_check = 2;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Invalid check N°1");
                }
            }

            // 1° CHECK SELL
            else if ($this->break_ema_check === 1 && $this->side === "SELL") {
                if (($rsi > $level_rsi) && (CheckEntry($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last))) {

                    $this->stopEntry = false;
                    $this->break_ema_check = 0;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Entry valid - Short - RSI > " . $level_rsi);
                    //EFFETUA ORDINE
                    Log::debug("TRADE APERTO"); 
                    //Trade(side);}

                }else{  // passiamo al controllo n°2
                    $this->stopEntry = false;
                    $this->break_ema_check = 2;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Invalid check N°1");
                }
            }    

            // 2° CHECK BUY
            else if ($this->break_ema_check === 2 && $this->side === "BUY") {

                if (($rsi < $level_rsi) && (CheckEntry($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last))) {
                    $this->stopEntry = false;
                    $this->break_ema_check = 3;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Entry valid - Long - RSI < " . $level_rsi);
                    //EFFETUA ORDINE
                    Log::debug("TRADE APERTO"); 
                    //Trade(side);}

                }else{  // passiamo al controllo n°3
                    $this->stopEntry = false;
                    $this->break_ema_check = 3;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Invalid check N°2");
                    }
            }

            // 2° CHECK SELL
            else if ($this->break_ema_check === 2 && $this->side === "SELL") {

                if (($rsi > $level_rsi) && (CheckEntry($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last))) {
                    $this->stopEntry = false;
                    $this->break_ema_check = 3;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Entry valid - Short - RSI > " . $level_rsi);
                    //EFFETUA ORDINE
                    Log::debug("TRADE APERTO"); 
                    //Trade(side);}

                }else{  // passiamo al controllo n°3
                    $this->stopEntry = false;
                    $this->break_ema_check = 3;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Invalid check N°2");
                    }
            }

            // 3° CHECK BUY
            else if ($this->break_ema_check === 3 && $this->side === "BUY") {

                if (($rsi < $level_rsi) && (CheckEntry($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last))) {
                    $this->stopEntry = false;
                    $this->break_ema_check = 0;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Entry valid - Long - RSI < " . $level_rsi);
                    //EFFETUA ORDINE
                    Log::debug("TRADE APERTO"); 
                    //Trade(side);}

                }else{  // Entry invalida
                    $this->stopEntry = false;
                    $this->break_ema_check = 0;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Invalid check N°3 - no valid Entry found :(");
                }
            }

            // 3° CHECK SELL
            else if ($this->break_ema_check === 3 && $this->side === "SELL") {

                if (($rsi < $level_rsi) && (CheckEntry($istanceKey, $ema, $close_values, $this->side, $close_last, $open_last))) {
                    $this->stopEntry = false;
                    $this->break_ema_check = 0;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Entry valid - Short - RSI > " . $level_rsi);
                    //EFFETUA ORDINE
                    Log::debug("TRADE APERTO"); 
                    //Trade(side);}

                }else{  // Entry invalida
                    $this->stopEntry = false;
                    $this->break_ema_check = 0;
                    $this->side = null;

                    Cache::put($cacheKey, [
                        'stopEntry' => $this->stopEntry,
                        'break_ema_check' => $this->break_ema_check,
                        'side' => $this->side,
                    ], now()->addMinutes($timeframe*2));

                    Log::debug("Invalid check N°3 - no valid Entry found :(");
                }
            }


        } else {
            Log::info('Nessun cambio di candela rilevato.');
        }
    }               

    // determina se c'è stata una rottura dell'EMA per decidere la direzione del trade
    private function checkBreak($ema, $open_last, $close_last) {
        if ($close_last < $ema && $open_last > $ema) {
            
            // Cache::put($cacheKey, [
            //     'break_direction' => "LONG"
            // ]);

            return "LONG";
        } elseif ($close_last > $ema && $open_last < $ema) {

            // Cache::put($cacheKey, [
            //     'break_direction' => "SHORT"
            // ]);

            return "SHORT";
        }

        // Cache::put($cacheKey, [
        //     'break_direction' => "NONE"
        // ]);

        return "NONE";
    }

    // verifica se le condizioni del prezzo e degli indicatori tecnici sono favorevoli
    // private function checkEntry($istanceKey, $ema, $close_values, $side, $close_last, $open_last) {
        
    //     // $rsi = $this->calculateRSI($close_values, $this->period_rsi);
    //     // $tp_distance = abs($ema - $close_last);

    //     if ($side === "BUY" && ($open_last > $close_last )) {
    //         // if ($rsi < $this->level_rsi && $tp_distance > $this->min_tp_distance) {
    //         //     Log::debug('Entry valid LONG: RSI < ' . $this->level_rsi . ' e distanza TP > ' . $this->min_tp_distance);
    //             return true;
    //         //}
    //     } elseif ($side === "SELL" && ($open_last < $close_last )) {
    //         // if ($rsi > $this->level_rsi && $tp_distance > $this->min_tp_distance) {
    //         //     Log::debug('Entry valid SHORT: RSI > ' . $this->level_rsi . ' e distanza TP > ' . $this->min_tp_distance);
    //             return true;
    //         //}
    //     }

    //     return false;
    // }

}

