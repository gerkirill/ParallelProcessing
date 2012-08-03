<?php
namespace gerkirill\ParallelProcessing;

interface TaskInterface
{
	public function run();

	public function syncWith(TaskInterface $task);
}