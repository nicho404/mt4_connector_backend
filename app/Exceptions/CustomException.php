<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CustomException extends Exception
{
    protected $istance_key;
    protected $symbol;
    protected $timeframe;
    protected $market;

    public function __construct($message, $istance_key)
    {
        parent::__construct($message);
        $this->istance_key = $istance_key;

        // Effettua una sola query per ottenere tutte le informazioni necessarie
        $record = $this->getRecordFromDatabase($istance_key);

        // Assegna i valori dalle colonne del record
        $this->symbol = $record->active_simble;
        $this->timeframe = $record->timeframe;
        $this->market = $record->market_refresh_rate;
    }

    /**
     * Recupera il record dal database utilizzando l'istance_key.
     */
    private function getRecordFromDatabase($istance_key)
    {
        // Effettua la query per recuperare il record
        $record = DB::table('istance_settings')
            ->where('istance_key', $istance_key)
            ->first();

        if (!$record) {
            throw new Exception("Nessun record trovato per l'istance_key: $istance_key");
        }

        return $record;
    }

    public function render($request)
    {
        return new JsonResponse([
            'error' => $this->getMessage(),
            'istance_key' => $this->istance_key,
            'symbol' => $this->symbol,
            'timeframe' => $this->timeframe,
            'market' => $this->market
        ], 400); // Puoi cambiare il codice di stato HTTP se necessario
    }
}
