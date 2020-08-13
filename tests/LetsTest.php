<?php

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Cosninix\Cos\Cos;

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

	public function testSanitize() {
		$v='simple string';
		$v2=Cos::sanitizeString($v);
		$this->assertEquals($v, $v2);

		$v="simple valid string \t\r\n";
		$v2=Cos::sanitizeString($v);
		$this->assertEquals($v, $v2);

		$v="A strange string to pass, maybe with some ø, æ, å characters.";
		$v2=Cos::sanitizeString($v);
		$this->assertEquals($v, $v2);

		$v="\xc2\xb8 \xC3\xB8 \xc3\xa6 \xD0\x94 \xe0\xa0\x86 \xe2\x96\x80 \xea\xa8\x86 	\xf0\x90\x80\x80 \xf0\x9f\xa9\xb3";
		$v2=Cos::sanitizeString($v);
		$this->assertEquals($v, $v2, " cedille o-stroke ae cyrillic-D Samaritan-zen block cham-ka LINEAR-B-SYLLABLE-B008-A Shorts");

		$v="illegal einding \xf0";
		$v2=Cos::sanitizeString($v);
		$this->assertEquals("illegal einding ", $v2);

		$v="illegal controls\x00\x01\x02\x1f\x0a\x0d\x09\e";
		$v2=Cos::sanitizeString($v);
		$this->assertEquals("illegal controls\n\r\t\e", $v2);

		$v="illegal utf \xf0!!\x81@@\xc2##\xe0$$\xe0%%\xff^^ in the middle";	// note: x81 and xff are not considered utf8 sequences so the next character remains
		$v2=Cos::sanitizeString($v);
		$this->assertEquals("illegal utf !@@#$%^^ in the middle", $v2);

		$v="illegal utf 2 \xe0\xa0!!\xf0\x9f\xa9## in the middle";
		$v2=Cos::sanitizeString($v);
		$this->assertEquals("illegal utf 2 !# in the middle", $v2);
	}
}
