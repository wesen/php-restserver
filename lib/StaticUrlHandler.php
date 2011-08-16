<?php

/*
 * Rest Server - Individual Static Url Handler
 *
 * (c) August 2011 - Goldeneaglecoin
 *
 * Author: Manuel Odendahl - wesen@ruinwesen.com
 */

namespace REST;

require_once(dirname(__FILE__)."/helpers.php");
require_once(dirname(__FILE__)."/UrlHandler.php");

/**
 * Handler for a single REST method URL on a static method
 **/
class StaticUrlHandler extends UrlHandler {
  public function call($params) {
    if ($this->needsAuthorization && method_exists($this->class, 'authorize')) {
      $class = $this->class;
      if (!$class::authorize()) {
        throw new Exception('401');
      }
    }
    
    $result = call_user_func_array(array($this->class, $this->methodName), $params);
    return array("status" => '200',
                 "error" => false,
                 "data" => $result);
  }
}

?>