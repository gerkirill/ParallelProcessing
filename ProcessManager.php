<?php
namespace gerkirill\ParallelProcessing;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProcessManager
{
	private $eventDispatcher;
	private $processes = array();
	protected $relaxTime = 100;

	public function setEventDispatcher(EventDispatcherInterface $dispatcher)
	{
		$this->eventDispatcher = $dispatcher;
	}

	public function addProcess($process)
	{
		$this->processes[] = $process;
	}

	public function startAll()
	{
		foreach($this->processes as $process)
		{
			$process->start();
		}
	}

	public function terminateProcess($process)
	{
		$process->terminate();
		while(!$process->isFinished())
		{
			usleep($this->relaxTime);
		}
		$process->sync();
		$this->triggerEvent('process.finished', $process);
	}

	public function removeProcess($process)
	{
		foreach($this->processes as $k=>$someProcess)
		{
			if ($someProcess === $process)
			{
				unset($this->processes[$k]);
				$this->processes = array_values($this->processes);
				break;
			}
		}
	}

	public function getProcesses()
	{
		return $this->processes;
	}

	public function startAllAndWait()
	{
		$this->startAll();
		// run untill all the processes which were started become finished
		// and syncronized
		while(!$this->allProcessesSyncronized())
		{
			foreach($this->processes as $process)
			{
				// autostart unstarted propcesses
				if (!$process->wasStarted())
				{
					$process->start();
				}
				elseif ($process->isFinished())
				{
					if($process->sync())
					{
						$this->triggerEvent('process.finished', $process);
					}
				}
			}
			usleep($this->relaxTime);
		}
	}

	private function allProcessesSyncronized()
	{
		foreach($this->processes as $process)
		{
			if (!$process->wasSyncronized())
			{
				return false;
			}
		}
		return true;
	}

	protected function triggerEvent($eventName, $process)
	{
		if (!$this->eventDispatcher) return;
		$event = new ProcessEvent($process);
		$this->eventDispatcher->dispatch($eventName, $event);
	}
}