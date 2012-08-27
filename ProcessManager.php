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

	/**
	 * Allows to set the event dispatcher - optional dependency needed if you wish to react upon
	 * different events.
	 * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
	 */
	public function setEventDispatcher(EventDispatcherInterface $dispatcher)
	{
		$this->eventDispatcher = $dispatcher;
	}

	/**
	 * Get number of processes which may run at the same time
	 * @return int
	 */
	public function getConcurrencyLimit()
	{
		return $this->concurrencyLimit;
	}

	/**
	 * Set number of processes which may run at the same time
	 * @param $limit
	 */
	public function setConcurrencyLimit($limit)
	{
		$this->concurrencyLimit = $limit;
	}

	/**
	 * Get number of the processes which can be queued for processing (including running ones)
	 * @return int
	 */
	public function getMaxQueueLength()
	{
		return $this->maxQueueLength;
	}

	/**
	 * Set number of the processes which can be queued for processing (including running ones)
	 * @param int $length
	 */
	public function setMaxQueueLength($length)
	{
		$this->maxQueueLength = $length;
	}

	/**
	 * Adds process to the queue
	 * @param $process
	 * @throws \OverflowException
	 */
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

	/**
	 * Starts all the queued processes, does not take concurrency limit into account
	 */
	public function startAll()
	{
		foreach($this->processes as $process)
		{
			$process->start();
			$this->triggerEvent('process.started', $process);
		}
	}

	/**
	 * Terminates all the running processes. Sends terminate signal to each process, waits until it really
	 * finished as synchronizes it
	 */
	public function terminateAll()
	{
		foreach($this->processes as $process)
		{
			$this->terminateProcess($process);
		}
	}

	/**
	 * Sends terminate signal to process and awaits until it really finishes. After that - cleanups
	 * temporary files and sync.
	 * @param Process $process
	 */
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

	/**
	 * Removes process from process manager
	 * @param Process $process
	 */
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

	/**
	 * Returns all the processes added to process manager
	 * @return array
	 */
	public function getProcesses()
	{
		return $this->processes;
	}

	/**
	 * Starts all processes (despite concurrency limit) and waits until they all finish
	 */
	public function startAllAndWait()
	{
		$this->startAll();
		// run untill all the processes which were started become finished
		// and syncronized
		$this->waitForAll();
	}

	/**
	 * Runs all the processes, but makes sure only number of processes corresponding to the concurrency
	 * limit is running at the same time (new processes are started once some other finished).
	 * Waits until all the processes added to process manager will finish.
	 */
	public function startGraduallyAndWait()
	{
		$this->startWithinConcurrencyLimit();
		$this->waitForAll();
	}

	/**
	 * Runs process manager in "infinite-loop mode". In this mode process manager triggers some
	 * lifetime events, upon which new tasks may be added, finished tasks handled or the infinite loop
	 * stopped with the call to stopInfiniteLoop() method.
	 */
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

	/**
	 * Allows some event handler to stop infinite loop started with startInfiniteLoop() method
	 */
	public function stopInfiniteLoop()
	{
		$this->exitLoopFlag = true;
	}

	/**
	 * Calculates how much tasks may be added to process manager without exceeding maxQueueLength
	 * limitation.
	 * @return int
	 */
	public function countFreeSlots()
	{
		return max(0, $this->maxQueueLength - count($this->processes));
	}

	/**
	 * Waits until all the processes added to process manager will finish.
	 * Starts not started processes automatically, according to the concurrency limit
	 */
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

	/**
	 * Makes process manager do its job in parallel to the main process, using the tick function
	 */
	public function runInBackground()
	{
		register_tick_function(array($this, 'onTick'));
	}

	/**
	 * Unregisters tick function registered by runInBackground() method
	 */
	public function stopInBackground()
	{
		unregister_tick_function(array($this, 'onTick'));
	}

	/**
	 * Performs process manager background job - syncronizez finished processes and starts new ones
	 * according to the concurrency limit
	 */
	public function onTick()
	{
		$this->syncFinishedProcesses();
		// re-counts only processes which _really_ run, so if process finished after syncFinishedProcesses -
		// its process.finished callback may be invoked _after_ new process (which replaces it) started
		$this->startWithinConcurrencyLimit();
	}


	/**
	 * Gathers information about just finished processes, performs temporary files cleanup for them
	 * and triggers process.finished event listeners may react to.
	 */
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

	/**
	 * Callback for the SIGINT handler - terminates all processes and performs needed cleanup
	 * @param $signal
	 */
	public function sigintHandler($signal)
	{
		if (SIGINT === $signal)
		{
			$this->terminateAll();
			exit;
		}
	}

	/**
	 * Registers callback invoked when user hits CTRL+C in console
	 */
	private function registerSigintHandler()
	{
		if (!function_exists('pcntl_signal')) return;
		pcntl_signal(SIGINT, array($this, 'sigintHandler'));
	}

	/**
	 * Counts already running processes and starts as much processes as concurrency limit allows
	 */
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

	/**
	 * Counts running processes
	 * @return int
	 */
	public function countRunningProcesses()
	{
		$running = 0;
		foreach($this->processes as $process)
		{
			if ($process->isRunning()) $running++;
		}
		return $running;
	}

	/**
	 * Checks if all the processes were finished and synchronized
	 * @return bool
	 */
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

	/**
	 * Internal function, centralized way to trigger events
	 * @param $eventName
	 * @param null|Process $process
	 */
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