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

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(RabbitMQLib $rabbitmq)
    {
        parent::__construct();
        $this->rabbitmq = $rabbitmq;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->rabbitmq->dequeue('run_email_campaigns', array($this, 'decodedData'));
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

            $this->rabbitmq->putInFailedQueue('failed_run_email_campaigns', $msg->getBody());
        }
        $msg->ack();
    }
}
