<?php

namespace App\Services;

use App\Libs\MongoDBLib;

/**
 * Class VoiceService
 * @package App\Services
 */
class VoiceService
{
    protected $mongo;
    public function __construct()
    {
        $this->mongo = new MongoDBLib;
    }

    public function storeReport($res, $actionLog, $collection)
    {
        //
    }
}
