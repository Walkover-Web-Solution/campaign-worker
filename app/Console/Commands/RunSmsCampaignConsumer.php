<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Services\ChannelService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunSmsCampaignConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sms:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sms consume command';

    protected $rabbitmq;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->rabbitmq = RabbitMQLib::getInstance();
        $this->rabbitmq->dequeue('run_sms_campaigns', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        try {
            printLog("======== Found job in sms queue ========", 2);
            $message = json_decode($msg->getBody(), true);
            // printLog("Decoding data from job ", 1, (array)$message);
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $failedCount = unserialize($obj)->data->failedCount;
            $action_log_id = unserialize($obj)->data->action_log_id;
            // $action_log_id = $message['action_log_id'];
            $channelService = new ChannelService();
            $channelService->sendData($action_log_id);
        } catch (\Exception $e) {
            $logData = [
                "actionLog" => "",
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job sms", $logData);
            printLog("Found exception in run sms ", 5,  $logData);

            if ($failedCount > 3) {
                // Feed into database
                storeFailedJob($e->getMessage(), $action_log_id, 'run_sms_campaigns', $message);
                return;
            }

            $this->rabbitmq = RabbitMQLib::getInstance();
            $this->rabbitmq->putInFailedQueue('failed_run_sms_campaigns', $message);
        } finally {
            $msg->ack();
        }
    }
}
