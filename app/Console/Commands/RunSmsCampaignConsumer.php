<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Services\ChannelService;
use Illuminate\Console\Command;

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
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $action_log_id = unserialize($obj)->data->action_log_id;
            $obj = new ChannelService();

            $obj->sendData($action_log_id);
        } catch (\Exception $e) {
            if(empty($this->rabbitmq)){
                $this->rabbitmq = new RabbitMQLib;
            }

            $this->rabbitmq->putInFailedQueue('failed_run_sms_campaigns', $msg->getBody());
        }
        $msg->ack();
    }
}
