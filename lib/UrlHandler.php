<?php

/*
 * Rest Server - Individual Url Handler
 *
 * (c) August 2011 - Goldeneaglecoin
 *
 * Author: Manuel Odendahl - wesen@ruinwesen.com
 */

namespace REST;

require_once(dirname(__FILE__)."/helpers.php");

/**
 * Handler for a single REST method URL
 **/
class UrlHandler {
  public $httpMethod;
  public $class;
  public $methodName;
  public $args;
  public $needsAuthorization;
  public $isStatic;
  public $url;
  public $cache;

  public function __construct($options = array()) {
    $defaults = array("httpMethod" => null,
                      "class" => null,
                      "methodName" => null,
                      "args" => array(),
                      "needsAuthorization" => false,
                      "url" => "",
                      "cache" => false);
    $options = array_merge($defaults, $options);
    object_set_options($this, $options, array_keys($defaults));

    if (strstr($this->url, "$")) {
      $this->regex = preg_replace('/\\\\\$([\w\d]+)\.\.\./', '(?P<$1>.+)', str_replace('\.\.\.', '...', preg_quote($this->url)));
      $this->regex = preg_replace('/\\\\\$([\w\d]+)/', '(?P<$1>[^\/]+)', $this->regex);
      $this->regex = ":^".$this->regex."\$:";
    } else {
      $this->regex = null;
    }
  }

  /**
   * Generate the parameter for the method call, according to matches.
   **/
  public function genParams($matches) {
    $params = array();

    foreach ($this->args as $arg => $idx) {
      if (isset($matches[$arg])) {
        $params[$idx] = $matches[$arg];
      } else {
        $params[$idx] = null;
      }
    }
    ksort($params);
    end($params);

    return $params;
  }

  /**
   * Check if the url handlers matches the given path.
   *
   * Returns null if it doesn't match, or the bound variables.
   **/
  public function matchPath($path, $data, $params = array()) {
    $matches = array();

    if ($this->regex) {
      if (!preg_match($this->regex, urldecode($path), $matches)) {
        return null;
      }
    } else {
      if ($path != $this->url) {
        return null;
      }
    }

    return array_merge(array('__params' => $params,
                             '__data' => $data,
                             '__requestPath' => $path,
                             '__handler' => $this,
                             '__urlMatches' => $matches),
                       $matches);
  }

  public function call($params) {
    /* static method */
    if (is_string($className = $this->class)) {
      $obj = new $className();
    } else {
      $obj = $this->class;
    }

    if (method_exists($obj, 'init')) {
      $obj->init();
    }

    if ($this->needsAuthorization && method_exists($obj, 'authorize')) {
      if (!$obj->authorize()) {
        throw new Exception('401');
      }
    }

    $result = call_user_func_array(array($obj, $this->methodName), $params);

    if (method_exists($obj, 'destroy')) {
      $obj->destroy();
    }

    return array("status" => '200',
                 "error" => false,
                 "data" => $result);
  }
};

?>
