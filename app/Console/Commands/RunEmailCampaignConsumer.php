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
        if(empty($this->rabbitmq)){
            $this->rabbitmq = new RabbitMQLib;
        }
        $this->rabbitmq->dequeue('run_email_campaigns', array($this, 'decodedData'));
    }
    public function decodedData($msg)
    {
        try {
            $message = json_decode($msg->getBody(), true);
            $obj = $message['data']['command'];
            $str = json_decode(mb_substr($obj, 53, 109));
            $campaign = Campaign::find($str->campaign_id);
            $input = [];
            $input['company'] = $campaign->company;
            config(['msg91.jwt_token' => createJWTToken($input)]);
            $obj = new ChannelService();
            $obj->sendData(
                $str->campaign_id,
                $str->flow_action_id,
                $str->mongo_id,
                $str->action_log_id
            );
        } catch (\Exception $e) {

            if(empty($this->rabbitmq)){
                $this->rabbitmq = new RabbitMQLib;
            }
            $this->rabbitmq->putInFailedQueue('failed_run_email_campaigns', $msg->getBody());
        }
        $msg->ack();
    }
}
