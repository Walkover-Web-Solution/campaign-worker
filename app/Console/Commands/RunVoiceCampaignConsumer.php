<?php

namespace App\Console\Commands;

use App\Libs\RabbitMQLib;
use App\Models\Campaign;
use App\Services\ChannelService;
use Illuminate\Console\Command;

class RunVoiceCampaignConsumer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'voice:consume';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Voice Consume command';

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
        $this->rabbitmq->dequeue('run_voice_campaigns', array($this, 'decodedData'));
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


        $str = json_decode(mb_substr($obj, 53, 109));
        $obj = new ChannelService();
        $obj->sendData(
            $str->campaign_id,
            $str->flow_action_id,
            $str->mongo_id->_id,
            $str->action_log_id
        );
    }
}
