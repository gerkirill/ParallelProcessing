<?php
namespace gerkirill\ParallelProcessing;
use \Symfony\Component\Process\PhpExecutableFinder;

class Process
{
	private $task;
	private $processHandle;
	private $taskFile;
	private $outFile;
	private $errFile;
	private $exitFile;
	private $synced = false;
	private $output = '';
	private $error = '';
	private $exitCode;
	private $pipes;
	private $started = false;
	
	protected $phpPath = '/usr/bin/php';
	protected $bootstrapPath;

	public function __construct($bootstrapPath)
	{
		if (!file_exists($bootstrapPath))
		{
			throw new \InvalidArgumentException("File $bootstrapPath does not exist");
		}
		$this->bootstrapPath = $bootstrapPath;
	}

	public function setTask($task)
	{
		$this->task = $task;
	}

	public function setPhpPath($pathToPhpExecutable)
	{
		$this->phpPath = $pathToPhpExecutable;
	}

	public function getTask()
	{
		return $this->task;
	}

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

	private function generateOutputFiles()
	{
		$tmpDir = sys_get_temp_dir();
		$prefix = 'php_subprocess_';
		$this->taskFile = tempnam($tmpDir, $prefix.'task_');
		$this->outFile = tempnam($tmpDir, $prefix.'out_');
		$this->errFile = tempnam($tmpDir, $prefix.'error_');
		$this->exitFile = tempnam($tmpDir, $prefix.'exit_');
	}

	public function start()
	{
		if ($this->wasStarted())
		{
			throw new \RuntimeException('Process object can be started only once, but second call to start() detected.');
		}
		$this->synced = false;
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

	public function sync()
	{
		if ($this->synced) return false;
		if (!$this->isFinished()) return false;

		$task = unserialize(file_get_contents($this->taskFile));
		$this->task->syncWith($task);
		unlink($this->taskFile);

		fclose($this->pipes[0]);
		@fclose($this->pipes[1]);
		@fclose($this->pipes[2]);

		$this->output = file_get_contents($this->outFile);
		unlink($this->outFile);

		$this->error = file_get_contents($this->errFile);
		unlink($this->errFile);

		$this->exitCode = file_get_contents($this->exitFile);
		unlink($this->exitFile);

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

	public function terminate()
	{
		if (!$this->isFinished())
		{
			proc_terminate($this->processHandle);
		}
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
}