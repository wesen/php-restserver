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
   * @url GET /test2/$id
   * @noAuth
   **/
  function simpletest($id) {
    return $id;
  }
};

class SimpleHandler3 {
  /**
   * @url GET /test3/$par1/$par2
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

class StaticHandler {
  /**
   * @url GET /statictest
   * @noAuth
   **/
  public static function simpletest() {
    return 'static';
  }
};

class MultipleHandler {
  /**
   * @url GET /m1/$par1/$par2
   * @noAuth
   **/
  function m1($par1, $par2) {
    return array($par1, $par2);
  }

  /**
   * @url GET /m1/$par1
   * @noAuth
   **/
  function m1_2($par1) {
    return $par1;
  }
}

class OrderHandler {
  /**
   * @url GET /m2/$par1,$par2
   * @noAuth
   **/
  function m2($par1, $par2) {
    return array($par1, $par2);
  }

  /**
   * @url GET /m2/$par1
   * @noAuth
   **/
  function m2_2($par1) {
    return $par1;
  }

  /**
   * @url GET /m2/$par1,$par2,$par3
   * @noAuth
   **/
  function m2_3($par1, $par2, $par3) {
    return array($par1, $par2, $par3);
  }
};

class GetParamsHandler {
  /**
   * @url GET /get
   * @noAuth
   **/
  function get_test($__GET) {
    return $__GET;
  }
}

class UrlMatchesHandler {
   /**
   * @url GET /foo/$p1/$p2/$p3
   * @noAuth
   **/
  function get_path() {
    return "path";
  }

  /**
   * @url GET /matches/$p1/$p2/$p3
   * @noAuth
   **/
  function get_matches($__requestPath, $__handler, $__urlMatches) {
    return array("path" => $__requestPath,
                 "handler" => $__handler,
                 "matches" => $__urlMatches);
  }
}


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
    $this->server->addHandler('SimpleHandler');
    $result = $this->server->handle('simpletest');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 1);
  }

  public function testHandleStatic() {
    $this->server->addHandler('StaticHandler');
    $result = $this->server->handle('statictest');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 'static');
  }
  
  public function testHandleSimpleId() {
    $this->server->addHandler('SimpleHandler2');
    $result = $this->server->handle('test2/1');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 1);
    $result = $this->server->handle('test2/2');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 2);
  }

  public function testHandleSimpleTwoParams() {
    $this->server->addHandler('SimpleHandler3');
    $result = $this->server->handle('test3/1/2');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], array(1, 2));
    $result = $this->server->handle('test3/2/3');
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

  public function testMultipleHandlerInitialization() {
    $this->server = new Rest\Server(array('mode' => 'debug',
                                          'isCLI' => true,
                                          'handlers' => array('SimpleHandler3',
                                                              'SimpleHandler2',
                                                              'StaticHandler',
                                                              'SimpleHandler')));
    $result = $this->server->handle('simpletest');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 1);

    $result = $this->server->handle('statictest');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], 'static');

    $result = $this->server->handle('test3/1/2');
    $this->assertEqual($result["status"], "200");
    $this->assertFalse($result["error"]);
    $this->assertEqual($result["data"], array(1, 2));
    $result = $this->server->handle('test3/2/3');
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

  public function testMultipleHandlers() {
    $this->server->addHandler('MultipleHandler');
    $result = $this->server->handle('m1/foo');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result["data"], "foo");

    $result = $this->server->handle('m1/foo/bar');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result["data"], array("foo", "bar"));
  }

  public function testDefinitionOrder() {
    $this->server->addHandler('OrderHandler');
    
    $result = $this->server->handle('m2/foo');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result["data"], "foo");

    $result = $this->server->handle('m2/foo,bar');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result["data"], array("foo", "bar"));

    $result = $this->server->handle('m2/foo,bar,baz');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result["data"], array("foo,bar", "baz"));
  }

  public function testGetParams() {
    $this->server->addHandler('GetParamsHandler');

    $_GET = array("foo" => "bla");
    $result = $this->server->handle('get');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result['data'], array("foo" => "bla"));
  }

  public function testUnboundParams() {
    $this->server->addHandler('UrlMatchesHandler');

    $result = $this->server->handle('foo/bla/blo/bli');
    $this->assertEqual($result["status"], "200");
    $this->assertEqual($result['data'], 'path');

    $result = $this->server->handle('matches/bla/blo/bli');
    $this->assertEqual($result["status"], "200");
    //    var_dump($result);
    // XXX
  }
}

?>