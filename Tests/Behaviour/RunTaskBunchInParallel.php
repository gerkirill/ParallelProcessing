<?php
namespace gerkirill\ParallelProcessing\Tests\Behaviour;

use gerkirill\ParallelProcessing\Tests\Behaviour\TestCase;
use gerkirill\ParallelProcessing\Tests\Stub\SleeperTask;
use gerkirill\ParallelProcessing\ProcessManager;
use gerkirill\ParallelProcessing\Process;

require_once(dirname(__DIR__).'/autoload.php');
class RunTaskBunchInParallel extends TestCase
{
	function testTaskBunchRunningInParallel()
	{
		$this->given('I have a bunch of tasks to run')
		->and('I create the process manager')
		->and('I create a process for each task')
		->and('I add processes to the manager')
		->and('I note the time')
		->when('I start all processes and wait until they all finish')
		->and('I measure the run time')
		->then('I can see the time equals to the longest task');
	}

	function stepIHaveABunchOfTasksToRun(&$world)
	{
		$world['tasks'] = array(
			new SleeperTask(5),
			new SleeperTask(7),
			new SleeperTask(1),
			new SleeperTask(9)
		);
	}

	function stepICreateTheProcessManager(&$world)
	{
		$world['manager'] = new ProcessManager;
	}

	function stepICreateAProcessForEachTask(&$world)
	{
		$world['processes'] = array();
		foreach($world['tasks'] as $task)
		{
			$process = new Process(dirname(__DIR__).'/Stub/process_bootstrap.php');
			$process->setTask($task);
			$world['processes'][] = $process;
		}
	}

	function stepIAddProcessesToTheManager(&$world)
	{
		foreach($world['processes'] as $process)
		{
			$world['manager']->addProcess($process);
		}
	}

	function stepINoteTheTime(&$world)
	{
		$world['startTime'] = time();
	}

	function stepIStartAllProcessesAndWaitUntilTheyAllFinish(&$world)
	{
		$world['manager']->startAllAndWait();
	}

	function stepIMeasureTheRunTime(&$world)
	{
		$world['runTime'] = time() - $world['startTime'];
	}

	function stepICanSeeTheTimeEqualsToTheLongestTask(&$world)
	{
		$realAndExpectedDiff = $world['runTime'] - 9;
		$this->assertGreaterThanOrEqual(0, $realAndExpectedDiff);
		$this->assertLessThanOrEqual(1, $realAndExpectedDiff);
	}
}
/*
$ phpunit --printer PHPUnit_Extensions_Story_ResultPrinter_Text RunTaskBunchInParallel.php 
PHPUnit 3.6.10 by Sebastian Bergmann.

gerkirill\ParallelProcessing\Tests\Behaviour\RunTaskBunchInParallel
 [x] Task bunch running in parallel

   Given I have a bunch of tasks to run 
     and I create the process manager 
     and I create a process for each task 
     and I add processes to the manager 
     and I note the time 
    When I start all processes and wait until they all finish 
     and I measure the run time 
    Then I can see the time equals to the longest task 

Scenarios: 1, Failed: 0, Skipped: 0, Incomplete: 0.
*/