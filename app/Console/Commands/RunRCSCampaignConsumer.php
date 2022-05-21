<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Services\ChannelService;
use Illuminate\Console\Command;

class RunRCSCampaignConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rcs:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'RCS consume command';

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
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->dequeue('run_rcs_campaigns', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        try {
            printLog("======== Found job in sms queue ========", 2);
            $message = json_decode($msg->getBody(), true);
            printLog("Decoding data from job ", 1, (array)$message);
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $action_log_id = unserialize($obj)->data->action_log_id;
            // $action_log_id=$message['action_log_id'];
            $channelService = new ChannelService();
            $channelService->sendData($action_log_id);
        } catch (\Exception $e) {
            if (empty($this->rabbitmq)) {
                $this->rabbitmq = new RabbitMQLib;
            }
            $logData = [
                "actionLog" => $action_log_id,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job rcs", $logData);
            printLog("Found exception in run rcs ", 1,  $logData);

            $this->rabbitmq->putInFailedQueue('failed_run_rcs_campaigns', $msg->getBody());
        }
        $msg->ack();
    }
}
