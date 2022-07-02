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
    }

    public function createRequestBody($data)
    {
        unset($data->variables);
        $obj = new \stdClass();
        $obj->arr = [];
        $obj->mob = [];
        collect($data)->map(function ($item) use ($obj) {
            $mob = collect($item)->map(function ($value) use ($obj) {

                $mobile = collect($value)->only('mobiles', 'variables')->toArray();
                if (empty($mobile["variables"])) {
                    array_push($obj->mob, $mobile["mobiles"]);
                } else {
                    return $mobile;
                }
                // // return collect($value)->only('mobiles', 'variables')->toArray();
            })->filter()->toArray();

            $obj->arr = array_merge($obj->arr, $mob);
        });
        array_push($obj->arr, ["mobiles" => $obj->mob]);
        return $obj->arr;
    }

    public function storeReport($res, $actionLog, $collection)
    {
        //
    }
}
