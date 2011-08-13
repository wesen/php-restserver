<?php

/*
 * Test Rest Server
 *
 * (c) August 2011 - Manuel Odendahl - wesen@ruinwesen.com
 */

require_once(dirname(__FILE__)."/../vendor/simpletest/autorun.php");
require_once(dirname(__FILE__)."/../RestServer.php");

class TestRest extends UnitTestCase {
  public function setUp() {
    $this->rest = new REST\Server('production');
    $_SERVER['REQUEST_METHOD'] = 'GET';
  }
  
  public function testGetMethod() {
    $this->assertEqual($this->rest->getMethod(), 'GET');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $this->assertEqual($this->rest->getMethod(), 'POST');
  }

  public function testNoRoute() {
    
  }
}

?>