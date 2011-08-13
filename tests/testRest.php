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
    $this->server = new REST\Server(array('mode' => 'production'));
    $_SERVER['REQUEST_METHOD'] = 'GET';
  }

  public function testSetup() {
    $this->assertEqual($this->server->mode, "production");
  }
  
  public function testGetMethod() {
    $this->assertEqual($this->server->getMethod(), 'GET');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $this->assertEqual($this->server->getMethod(), 'POST');
  }

  public function testNoRoute() {
    
  }
}

?>