<?php

namespace App\Libs;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Connection\AMQPSSLConnection;


class RabbitMQLib{

	protected $channel;
	protected $connection;

	public function __construct(){
		//define('AMQP_DEBUG', true);


		if(env('APP_ENV')=='local'){
			$this->connection = new AMQPStreamConnection(config('services.rabbitmq.host'), config('services.rabbitmq.port'), config('services.rabbitmq.username'), config('services.rabbitmq.password'));
		}else{
			$timeout = 24*60*60;
			$this->connection = new AMQPSSLConnection(config('services.rabbitmq.host'), config('services.rabbitmq.port'), config('services.rabbitmq.username'), config('services.rabbitmq.password'),
				'/',
				['verify_peer_name' => false, "keepalive" => true],
				['heartbeat'=>10,'connection_timeout'=>$timeout,'read_write_timeout'=>$timeout],
				'ssl'
				);
		}
		$this->channel = $this->connection->channel();

	}

	public function enqueue($queue,$data){
		$this->channel->queue_declare($queue, false, false, false, false);
		$msg = new AMQPMessage(json_encode($data));
		$this->channel->basic_publish($msg, '',$queue);

	}


	public function dequeue($queue,$callback){
		$this->channel->queue_declare($queue, false, false, false, false);

		$this->channel->basic_consume($queue, '', false, false, false, false, $callback);

		while ($this->channel->is_open()) {
		    $this->channel->wait();
		}


	}

	function __destruct() {
		$this->channel->close();
		$this->connection->close();
  	}

}