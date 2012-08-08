<?php
namespace gerkirill\ParallelProcessing;
use Symfony\Component\EventDispatcher\Event;

class ProcessManagerEvent extends Event
{
	protected $processManager;

	function __construct($processManager)
	{
		$this->processManager = $processManager;
	}

	public function getProcessManager()
	{
		return $this->processManager;
	}
}