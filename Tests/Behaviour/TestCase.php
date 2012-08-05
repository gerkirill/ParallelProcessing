<?
namespace gerkirill\ParallelProcessing\Tests\Behaviour;

class  TestCase extends  \PHPUnit_Extensions_Story_TestCase
{
	public function runGiven(&$world, $action, $arguments)
	{
		return $this->runSmth($world, $action, $arguments);
	}

	public function runWhen(&$world, $action, $arguments)
	{
		return $this->runSmth($world, $action, $arguments);
	}

	public function runThen(&$world, $action, $arguments)
	{
		return $this->runSmth($world, $action, $arguments);
	}

	private function runSmth(&$world, $action, $arguments)
	{
		$method = ucfirst($action);
		$method = preg_replace_callback('/\s\w/i', function($m){
			return trim(strtoupper($m[0]));
		}, $method);
		$method = 'step'.$method;
		if (method_exists($this, $method))
		{
			$this->$method($world, $arguments);
		}
		else
		{
			echo "\nNot implemented step '$action' ($method)\n";
			return $this->notImplemented($action);
		}
	}
}