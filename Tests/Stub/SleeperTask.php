<?php
namespace gerkirill\ParallelProcessing\Tests\Stub;

use gerkirill\ParallelProcessing\TaskInterface;
class SleeperTask implements TaskInterface
{
	private $sleepTime;
	public function __construct($sleepTime)
	{
		$this->sleepTime = $sleepTime;
	}

	public function run()
	{
		sleep($this->sleepTime);
	}

	public function syncWith(TaskInterface $task)
	{
		
	}
}