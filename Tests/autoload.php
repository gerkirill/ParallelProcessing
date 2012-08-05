<?php
spl_autoload_register(
	function($class)
	{
		if (0 === strpos($class, 'gerkirill\\'))
		{
			$nsPart = str_replace('gerkirill\\ParallelProcessing', '', $class);
			$path = str_replace('\\', DIRECTORY_SEPARATOR, $nsPart);
			$fullPath = dirname(__DIR__).$path.'.php';
			if (file_exists($fullPath))
			{
				require_once($fullPath);
			}
		}
	}
);