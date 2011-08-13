<?php

require_once(dirname(__FILE__).'/../vendor/simpletest/autorun.php');
require_once(dirname(__FILE__).'/../RestServer.php');

class RestServerTestSuite extends TestSuite {
  function __construct() {
    $this->TestSuite('All RestServer tests');
    $this->addFile(dirname(__FILE__)."/testRest.php");
  }
};

?>