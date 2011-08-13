<?php

/*
 * Test Rest Server
 *
 * (c) August 2011 - Manuel Odendahl - wesen@ruinwesen.com
 */

require_once(dirname(__FILE__)."/../vendor/simpletest/autorun.php");
require_once(dirname(__FILE__)."/../RestServer.php");

class SimpleHandler {
  /**
   * @url GET /simpletest
   * @noAuth
   **/
  function simpletest() {
    return 1;
  }
};

class SimpleHandler2 {
  /**
   * @url GET /test/$id
   * @noAuth
   **/
  function simpletest($id) {
    return $id;
  }
};

class SimpleHandler3 {
  /**
   * @url GET /test/$par1/$par2
   * @noAuth
   **/
  function simpletest($par1, $par2) {
    return array($par1, $par2);
  }

  /**
   * @url GET /inverted_test/$par1/$par2
   * @noAuth
   **/
  function simpletest2($par2, $par1) {
    return array($par1, $par2);
  }
};


class TestRest extends UnitTestCase {
  public function setUp() {
    $this->server = new REST\Server(array('mode' => 'debug',
                                          'isCLI' => true));
    $_SERVER['REQUEST_METHOD'] = 'GET';
  }

  public function testSetup() {
    $this->assertEqual($this->server->mode, "debug");
  }
  
  public function testGetMethod() {
    $this->assertEqual($this->server->getMethod(), 'GET');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $this->assertEqual($this->server->getMethod(), 'POST');
  }

  public function testNoRoute() {
    try {
      $this->server->handle('/foo', array('throwException' => true));
    } catch (REST\Exception $e) {
      $this->assertEqual($e->getCode(), 404);
    }

    $result = $this->server->handle('/foo');
    $this->assertEqual($result["status"], '404');
    $this->assertTrue($result["error"]);
    $this->assertEqual($result["data"], "Not Found");
  }

  public function testHandleSimple() {
    $this->server->addClass('SimpleHandler');
    $result = $this->server->handle('simpletest');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 1);
  }

  public function testHandleSimpleId() {
    $this->server->addClass('SimpleHandler2');
    $result = $this->server->handle('test/1');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 1);
    $result = $this->server->handle('test/2');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 2);
  }

  public function testHandleSimpleTwoParams() {
    $this->server->addClass('SimpleHandler3');
    $result = $this->server->handle('test/1/2');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], array(1, 2));
    $result = $this->server->handle('test/2/3');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], array(2, 3));

      $result = $this->server->handle('inverted_test/1/2');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], array(1, 2));
    $result = $this->server->handle('inverted_test/2/3');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], array(2, 3));
}
}

/* httpMethod, class, name, authorization, static */

/*
 *   public $httpMethod;
  public $class;
  public $methodName;
  public $args;
  public $needsAuthorization;
  public $isStatic;
*/
?>