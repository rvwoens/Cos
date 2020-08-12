<?php

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Cosninix\Cos\Cos;

/**
 * Class SimpleSinTest
 * @version 1.0
 * @Author Ronald vanWoensel <rvw@cosninix.com>
 */
class LetsTest extends TestCase {

	/**
	 * test the base class
	 */
	public function testIfset() {
		$v='value';
		$result=Cos::ifset($v, "default");
		$this->assertEquals('value', $result);

		$result=Cos::ifset($notdefined, "default");
		$this->assertEquals('default', $result);

	}
}
