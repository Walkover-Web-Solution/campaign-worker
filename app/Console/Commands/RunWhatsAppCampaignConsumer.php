<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Services\ChannelService;
use Illuminate\Console\Command;

class RunWhatsAppCampaignConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'whatsapp:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Whats app consume command';

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
        $this->rabbitmq->dequeue('run_otp_campaigns', array($this, 'decodedData'));
    }

    public function decodedData($msg)
    {

        $message = json_decode($msg->getBody(), true);
        $obj = $message['data']['command'];
        $str = json_decode(mb_substr($obj, 53, 109));

        /**
         * generating the token
         */
        $campaign = Campaign::find($str->campaign_id);
        $input = [];
        $input['company'] = $campaign->company;
        config(['msg91.jwt_token' => createJWTToken($input)]);

        $obj = new ChannelService();
        $obj->sendData(
            $str->campaign_id,
            $str->flow_action_id,
            $str->mongo_id->_id,
            $str->action_log_id
        );
    }
}
