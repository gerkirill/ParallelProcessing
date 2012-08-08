<?php
namespace gerkirill\ParallelProcessing;
use \Symfony\Component\Process\PhpExecutableFinder;

/**
* Represents a process for running single finite task, so:
* start() method can be successfully called only once,
* start() method will throw exception if you don't specify the task before.
* Supposed to run php scripts only.
* Class does not check underlying process status itself, but has public function
* isFinished() for that. Info about process execution can only be retrieved after
* it is finished and sync() function called.
*/
class Process
{
	private $task;
	protected $bootstrapPath;
	protected $phpPath = '/usr/bin/php';

	private $processHandle;
	private $pipes;

	private $taskFile;
	private $outFile;
	private $errFile;
	private $exitFile;

	private $output = '';
	private $error = '';
	private $exitCode;

	private $synced = false;
	private $started = false;

	/**
	* @param string $boostrapPath path to the php script which will be started in the separate process.
	* Script will be called with php enterpretter executable in front of it, so do not have to be 
	* executable itself. Path to serialized task object will be passed to the script as a first parameter.
	*/
	public function __construct($bootstrapPath)
	{
		if (!file_exists($bootstrapPath))
		{
			throw new \InvalidArgumentException("File $bootstrapPath does not exist");
		}
		$this->bootstrapPath = $bootstrapPath;
	}

	/**
	* Allows to specify task for the process. Task object will be passed to the separate script process
	* in a file, serialized. And then passed back the same way.
	*/
	public function setTask(TaskInterface $task)
	{
		$this->task = $task;
	}

	/**
	* Allows to specify path to the php executable directly.
	* @param string $pathToPhpExecutable absolute path to the php executable
	*/
	public function setPhpPath($pathToPhpExecutable)
	{
		$this->phpPath = $pathToPhpExecutable;
	}

	/**
	* Checks if process can be started, and if so - forms command and runs it with proc_open.
	*/
	public function start()
	{
		if ($this->wasStarted())
		{
			throw new \RuntimeException('Process can be started only once, but second call to start() detected.');
		}
		if (!$this->task)
		{
			throw new \RuntimeException('Process can not be started without task.');	
		}
		$this->started = true;
		$this->generateOutputFiles();
		file_put_contents($this->taskFile, serialize($this->task));

		$command = strtr('nohup @phpPath @scriptPath @taskFile ; echo $? > @exitFile', array(
			'@phpPath' => $this->getPhpPath(),
			'@scriptPath' => $this->bootstrapPath,
			'@taskFile' => $this->taskFile,
			'@exitFile' => $this->exitFile
		));

		$descriptorSpec = array(
			0 => array('pipe', 'r'),
			1 => array('file', $this->outFile, 'a'),
			2 => array('file', $this->errFile, 'a')
	    );
	    $this->processHandle = proc_open($command, $descriptorSpec, $this->pipes);
	}

	/**
	* Updates the process object state  (output, error, exit code) from the underlying php process.
	* The process state successfully syncronized only after process is finished (you can use 
	* isFinished() to check that), otherwise just returns false.
	* Only the first successfull call performs some usefull job (and return boolean true), all the 
	* subsequent calls will just return boolean false.
	* Note: you can use this method to check if the process has finished and sync it if so at once.
	* @return boolean - as described above.
	*/
	public function sync()
	{
		if ($this->synced) return false;
		if (!$this->isFinished()) return false;

		$this->closePipes();

		$task = unserialize(file_get_contents($this->taskFile));
		$this->task->syncWith($task);

		$this->output = file_get_contents($this->outFile);
		$this->error = file_get_contents($this->errFile);
		$this->exitCode = file_get_contents($this->exitFile);
		$this->removeOutputFiles();

		proc_close($this->processHandle);
		$this->processHandle = null;

		$this->synced = true;
		return true;
	}


	public function wasSyncronized()
	{
		return $this->synced;
	}

	public function wasStarted()
	{
		return $this->started;
	}

	public function isRunning()
	{
		return $this->wasStarted() && ! $this->isFinished();
	}

	public function isFinished()
	{
		if (!$this->started) return false;
		if (null === $this->processHandle) return true;
		$status = proc_get_status($this->processHandle);
		return !$status['running'];
	}

	// todo: maybe some flag to distinguish between normally finished and terminated ?
	public function terminate()
	{
		if (!$this->isFinished())
		{
			proc_terminate($this->processHandle);
			$this->processHandle = null;
		}
	}

	public function getTask()
	{
		return $this->task;
	}

	public function getOutput()
	{
		return $this->output;
	}

	public function getError()
	{
		return $this->error;
	}

	public function getExitCode()
	{
		return $this->exitCode;
	}

	public static function getTaskFromProcess()
	{
		$taskFile = $GLOBALS['argv'][1];
		$task = unserialize(file_get_contents($taskFile));
		return $task;
	}

	public static function updateTaskFromProcess($task)
	{
		$taskFile = $GLOBALS['argv'][1];
		file_put_contents($taskFile, serialize($task));
	}

	/**
	* Tries to find out path to the php executable in a few ways
	* @return string path to php executable  
	*/
	private function getPhpPath()
	{
		if (!is_executable($this->phpPath))
		{
			if (class_exists('Symfony\\Component\\Process\\PhpExecutableFinder', true))
			{
				$finder = new PhpExecutableFinder;
				$foundPath = $finder->find();
				if (null !== $foundPath)
				{
					$this->phpPath = $foundPath;
				}
				else
				{
					throw new \RuntimeException('Symfony PhpExecutableFinder can not find php executable. Try to set path to it directly with setPhpPath() method.');
				}
			}
			else
			{
				throw new \RuntimeException('Php executable was not found. Please try to set path to it with setPhpPath() method or consider using PhpExecutableFinder class which can be found in symfony/process package.');
			}
		}
		
		return $this->phpPath;
	}

	/**
	* Generates files required to communicate with started process (catch its stdout, stderr and return code)
	*/
	private function generateOutputFiles()
	{
		$tmpDir = sys_get_temp_dir();
		$prefix = 'php_subprocess_';
		$this->taskFile = tempnam($tmpDir, $prefix.'task_');
		$this->outFile = tempnam($tmpDir, $prefix.'out_');
		$this->errFile = tempnam($tmpDir, $prefix.'error_');
		$this->exitFile = tempnam($tmpDir, $prefix.'exit_');
	}

	/**
	* Removes temporary files generated with generateOutputFiles()
	*/
	private function removeOutputFiles()
	{
		unlink($this->taskFile);
		unlink($this->outFile);
		unlink($this->errFile);
		unlink($this->exitFile);
	}

	/**
	* Closes pipes / file handles used by proc_open to communicate with process
	*/
	private function closePipes()
	{
		foreach($this->pipes as $pipe)
		{
			fclose($pipe);
		}
	}
}