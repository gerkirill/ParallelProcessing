<?php
namespace gerkirill\ParallelProcessing;
use Symfony\Component\EventDispatcher\Event;

class ProcessEvent extends Event
{
	protected $process;

	function __construct($process)
	{
		$this->process = $process;
	}

	public function getProcess()
	{
		return $this->process;
	}
}