<?php

namespace App\Services;

use App\Libs\MongoDBLib;

/**
 * Class WhatsappService
 * @package App\Services
 */
class WhatsappService
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