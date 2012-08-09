<?php
declare(ticks = 1);
namespace gerkirill\ParallelProcessing;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ProcessManager
{
	private $eventDispatcher;
	private $processes = array();
	protected $relaxTime = 100;
	private $concurrencyLimit = 50;
	private $maxQueueLength =  500;
	private $exitLoopFlag = false;

	public function __construct()
	{
		$this->registerSigintHandler();
	}

	public function setEventDispatcher(EventDispatcherInterface $dispatcher)
	{
		$this->eventDispatcher = $dispatcher;
	}

	public function getConcurrencyLimit()
	{
		return $this->concurrencyLimit;
	}

	public function setConcurrencyLimit($limit)
	{
		$this->concurrencyLimit = $limit;
	}

	public function getMaxQueueLength()
	{
		return $this->maxQueueLength;
	}

	public function setMaxQueueLength($length)
	{
		$this->maxQueueLength = $length;
	}

	public function addProcess($process)
	{
		$procCount = count($this->processes);
		if ($procCount >= $this->maxQueueLength)
		{
			throw new \OverflowException('Can not add one more process - limit is reached ('.$this->maxQueueLength.')');
		}
		$this->processes[] = $process;
		if (++$procCount == $this->maxQueueLength)
		{
			$this->triggerEvent('process_manager.queue_is_full');
		}
	}

	public function startAll()
	{
		foreach($this->processes as $process)
		{
			$process->start();
			$this->triggerEvent('process.started', $process);
		}
	}

	public function terminateAll()
	{
		foreach($this->processes as $process)
		{
			$this->terminateProcess($process);
		}
	}

	public function terminateProcess($process)
	{
		$process->terminate();
		while(!$process->isFinished())
		{
			usleep($this->relaxTime);
		}
		// cleanup tmp files, etc
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
		$this->waitForAll();
	}

	public function startGraduallyAndWait()
	{
		$this->startWithinConcurrencyLimit();
		$this->waitForAll();
	}

	public function startInfiniteLoop()
	{
		// process_manager.idle +
		// process_manager.free_slots_available +
		// process_manager.queue_is_full +
		// process_manager.iteration +startInfiniteLoop
		$this->exitLoopFlag = false;
		do
		{
			$this->syncFinishedProcesses();
			if ($this->allProcessesSyncronized())
			{
				$this->triggerEvent('process_manager.idle');
			}
			$this->startWithinConcurrencyLimit();
			$this->triggerEvent('process_manager.iteration');
			if ($this->countFreeSlots())
			{
				$this->triggerEvent('process_manager.free_slots_available');
			}
			usleep($this->relaxTime);
		}
		while(!$this->exitLoopFlag);
		$exitLoopFlag = false;
		$this->waitForAll();
	}

	public function stopInfiniteLoop()
	{
		$this->exitLoopFlag = true;
	}

	public function countFreeSlots()
	{
		return max(0, $this->maxQueueLength - count($this->processes));
	}

	public function waitForAll()
	{
		$this->stopInBackground();
		while(!$this->allProcessesSyncronized())
		{
			$this->syncFinishedProcesses();
			$this->startWithinConcurrencyLimit();
			usleep($this->relaxTime);
		}
	}

	public function runInBackground()
	{
		register_tick_function(array($this, 'onTick'));
	}

	public function stopInBackground()
	{
		unregister_tick_function(array($this, 'onTick'));
	}

	public function onTick()
	{
		$this->syncFinishedProcesses();
		// re-counts only processes which _really_ run, so if process finished after syncFinishedProcesses -
		// its process.finished callback may be invoked _after_ new process (which replaces it) started
		$this->startWithinConcurrencyLimit();
	}

	private function syncFinishedProcesses()
	{
		foreach($this->processes as $process)
		{
			if ($process->isFinished() && $process->sync())
			{
				$this->triggerEvent('process.finished', $process);
			}
		}
	}

	public function sigintHandler($signal)
	{
		if (SIGINT === $signal)
		{
			$this->terminateAll();
			exit;
		}
	}

	private function registerSigintHandler()
	{
		if (!function_exists('pcntl_signal')) return;
		pcntl_signal(SIGINT, array($this, 'sigintHandler'));
	}

	private function startWithinConcurrencyLimit()
	{
		$limit = $this->concurrencyLimit;
		$running = $this->countRunningProcesses();
		if ($running >= $limit) return;
		foreach ($this->processes as $process)
		{
			if (!$process->wasStarted())
			{
				$process->start();
				$this->triggerEvent('process.started', $process);
				if (++$running >= $limit) break;
			}
		}
	}

	public function countRunningProcesses()
	{
		$running = 0;
		foreach($this->processes as $process)
		{
			if ($process->isRunning()) $running++;
		}
		return $running;
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

	protected function triggerEvent($eventName, $process=null)
	{
		if (!$this->eventDispatcher) return;
		if (null !== $process)
		{
			$event = new ProcessEvent($process, $this);	
		}
		else
		{
			$event = new ProcessManagerEvent($this);
		}
		
		$this->eventDispatcher->dispatch($eventName, $event);
	}
}