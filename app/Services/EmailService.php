<?php

namespace App\Services;

use App\Libs\MongoDBLib;
use Carbon\Carbon;

/**
 * Class EmailService
 * @package App\Services
 */
class EmailService
{
    protected $mongo;
    public function __construct()
    {
        $this->mongo = new MongoDBLib;
    }

    public function makeEmailBody($data)
    {

        $mappedData = collect($data)->map(function ($item) {

            return array(
                "name" => $item->name,
                "email" => $item->email
            );
        })->toArray();
        return $mappedData;
    }

    public function storeReport($res, $actionLog, $collection)
    {
        $obj = new \stdClass();
        $obj->queued = 0;
        $obj->delivered = 0;
        $obj->failed = 0;

        if (!$res->hasError) {
            collect($res->data)->map(function ($item) use ($obj) {
                if ($item->event->title == 'Queued' || $item->event->title == 'Accepted') {
                    $obj->queued++;
                } else if ($item->event->title == 'Delivered' || $item->event->title == 'Opened' || $item->event->title == 'Unsubscribed' || $item->event->title == 'Clicked') {
                    $obj->delivered++;
                } else if ($item->event->title == 'Rejected' || $item->event->title == 'Bounced' || $item->event->title == 'Failed' || $item->event->title == 'Complaints') {
                    $obj->failed++;
                }
            });
        }

        $obj->total = $obj->queued + $obj->delivered + $obj->failed;

        $actionLogReportData = [
            'total' => $obj->total,
            'delivered' => $obj->delivered,
            'failed' => $obj->failed,
            'pending' => $obj->queued,
            'additional_fields' => []
        ];
        if (empty($actionLog->actionLogReports()->get()->toArray()))
            $actionLog->actionLogReports()->create($actionLogReportData);
        else
            $actionLog->actionLogReports()->update($actionLogReportData);

        if ($obj->queued == 0) {
            $actionLog->report_status = 'done';
        }

        if ($actionLog->report_mongo == null) {
            // generating random key with time stamp for mongo requestId
            $reqId = preg_replace('/\s+/', '_',  Carbon::now()->timestamp) . '_' . md5(uniqid(rand(), true));
            $reportData = [
                "requestId" => $reqId,
                "reportData" => $res
            ];
            $this->mongo->collection($collection)->insertOne($reportData);
            $actionLog->report_mongo = $reqId;
        } else {
            $this->mongo->collection($collection)->update(["requestId" => $actionLog->report_mongo], ["reportData" => $res]);
        }

        $actionLog->save();
    }
}
