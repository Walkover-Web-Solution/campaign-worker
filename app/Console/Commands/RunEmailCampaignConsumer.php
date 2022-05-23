<?php

namespace App\Console\Commands;

use App\Jobs\RabbitMQJob;
use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Services\ChannelService;
use Illuminate\Console\Command;

class RunEmailCampaignConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Email consume command';

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
        if (empty($this->rabbitmq)) {
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->dequeue('run_email_campaigns', array($this, 'decodedData'));
    }
    public function decodedData($msg)
    {
        try {

            printLog("======== Found job in email queue ========", 2);
            $message = json_decode($msg->getBody(), true);
            // printLog("Decoding data from job ", 1, (array)$message);
            // $message = json_decode($msg->getBody(), true);
            // $obj = $message['data']['command'];
            // $action_log_id = unserialize($obj)->data->action_log_id;
            $action_log_id=$message['action_log_id'];
            $channelService = new ChannelService();

            $channelService->sendData($action_log_id);
        } catch (\Exception $e) {
            $logData = [
                "actionLog" => $action_log_id,
                "exception" => $e->getMessage(),
                "stack" => $e->getTrace()
            ];
            logTest("failed job email", $logData);
            printLog("Found exception in run email ", 1,  $logData);
            if (empty($this->rabbitmq)) {
                $this->rabbitmq = new RabbitMQLib;
            }
            $this->rabbitmq->putInFailedQueue('failed_run_email_campaigns', $msg->getBody());
        }
        $msg->ack();
    }
}
