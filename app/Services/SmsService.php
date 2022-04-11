<?php

namespace App\Services;

use App\Libs\MongoDBLib;

/**
 * Class SmsService
 * @package App\Services
 */
class SmsService
{
    protected $mongo;
    public function __construct()
    {
        $this->mongo = new MongoDBLib;
    }

    public function storeReport($res, $actionLog, $collection)
    {
        $obj = new \stdClass();
        $obj->queued = 0;
        $obj->delivered = 0;
        $obj->failed = 0;

        collect($res->reports)->map(function ($item) use ($obj) {
            if ($item->event->title == 'Queued' || $item->event->title == 'Accepted') {
                $obj->queued++;
            } else if ($item->event->title == 'Delivered' || $item->event->title == 'Opened' || $item->event->title == 'Unsubscribed' || $item->event->title == 'Clicked') {
                $obj->delivered++;
            } else if ($item->event->title == 'Rejected' || $item->event->title == 'Bounced' || $item->event->title == 'Failed' || $item->event->title == 'Complaints') {
                $obj->failed++;
            }
        });

        if ($obj->queued == 0) {
            $actionLog->status = 'done';
        }

        if ($actionLog->report_mongo == null) {
            // generating random key with time stamp for mongo requestId
            $reqId = preg_replace('/\s+/', '_', now()) . '_' . md5(uniqid(rand(), true));
            $reportData = [
                "requestId" => $reqId,
                "reportData" => $res
            ];
            $this->mongo->collection($collection)->insertOne($reportData);
            $actionLog->report_mongo = $reqId;
        } else {
            $this->mongo->collection($collection)->update(["requestId" => $actionLog->report_mongo], ["reportData" => $res]);
        }

        if ($obj->queued == 0) {
            $actionLog->status = 'done';
        }

        $actionLog->save();
    }
}
