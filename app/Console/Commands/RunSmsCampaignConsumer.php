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
        if(empty($this->rabbitmq)){
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->dequeue('run_sms_campaigns', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {
        try {
            // Log::debug("======== Found job in sms queue ========");
            $message = json_decode($msg->getBody(), true);
            // Log::debug("Decoding data from job ",(array)$message);
            $action_log_id = $message['action_log_id'];
            $channelService = new ChannelService();
            $channelService->sendData($action_log_id);
        } catch (\Exception $e) {
            if(empty($this->rabbitmq)){
                $this->rabbitmq = new RabbitMQLib;
            }
            $logData=[
                "actionLog"=>$action_log_id,
                "exception"=>$e->getMessage(),
                "stack"=>$e->getTrace()
            ];
            logTest("failed job sms",$logData);
            // Log::debug("Found exception in run sms ",$logData);

            $this->rabbitmq->putInFailedQueue('failed_run_sms_campaigns', $msg->getBody());
        }
        $msg->ack();
    }
}
