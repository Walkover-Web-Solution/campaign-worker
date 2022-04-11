<?php

namespace App\Services;

use App\Libs\MongoDBLib;

/**
 * Class OtpService
 * @package App\Services
 */
class OtpService
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
