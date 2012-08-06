<?php
namespace gerkirill\ParallelProcessing;
use Symfony\Component\EventDispatcher\Event;

class ProcessEvent extends ProcessManagerEvent
{
	protected $process;

	function __construct($process, $processManager)
	{
		$this->process = $process;
		parent::__construct($processManager);
	}

	public function getProcess()
	{
		return $this->process;
	}
}