<?php

namespace App\Libs;

use Illuminate\Support\Facades\Cache;
use PhpAmqpLib\Connection\AMQPLazySSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


class RabbitMQLib
{

	protected $channel;
	protected $connection;

	private static $instance;

	public function __construct()
	{
		//define('AMQP_DEBUG', true);

		printLog("In Rabbitmq lib to initiate rabbitmq connection.", 2);
		if (env('APP_ENV') == 'local') {
			$this->connection = new AMQPStreamConnection(config('services.rabbitmq.host'), config('services.rabbitmq.port'), config('services.rabbitmq.username'), config('services.rabbitmq.password'));
		} else {
			// $timeout = 24 * 60 * 60;
			$this->connection = new AMQPLazySSLConnection(
				config('services.rabbitmq.host'),
				config('services.rabbitmq.port'),
				config('services.rabbitmq.username'),
				config('services.rabbitmq.password'),
				'/',
				['verify_peer_name' => false],
				[],
				'ssl'
			);
		}
		$this->channel = $this->connection->channel();
	}

	public static function getInstance()
	{
		// RabbitMQLib::$instance = Cache::get('rabbitmqInstance');

		// if (empty(RabbitMQLib::$instance)) {
		// 	RabbitMQLib::$instance = new RabbitMQLib();
		// 	Cache::put('rabbitmqInstance', RabbitMQLib::$instance);
		// }

		if (empty(RabbitMQLib::$instance)) {
			RabbitMQLib::$instance = new RabbitMQLib();
		}

		return RabbitMQLib::$instance;
	}

	public function enqueue($queue, $data)
	{
		$this->channel->queue_declare($queue, false, false, false, false);
		$msg = new AMQPMessage(json_encode($data));
		$this->channel->basic_publish($msg, '', $queue);
	}


	public function dequeue($queue, $callback)
	{
		$this->channel->queue_declare($queue, false, false, false, false);

		$this->channel->basic_consume($queue, '', false, false, false, false, $callback);

		while ($this->channel->is_open()) {
			$this->channel->wait();
		}
	}

	public function putInFailedQueue($queue, $data)
	{
		$this->enqueue($queue, $data);
	}

	function __destruct()
	{
		$this->channel->close();
		$this->connection->close();
	}
}
