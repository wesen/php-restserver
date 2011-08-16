<?php

/*
 * Rest Server
 *
 * (c) August 2011 
 *
 * Author: Manuel Odendahl - wesen@ruinwesen.com
 * Author: Jacob Wright
 */

namespace REST;

require_once(dirname(__FILE__)."/helpers.php");
require_once(dirname(__FILE__)."/UrlHandler.php");
require_once(dirname(__FILE__)."/StaticUrlHandler.php");

/***************************************************************************
 *
 * Rest Server
 *
 ***************************************************************************/

/**
 * The REST server itself.
 **/
class Server {
  public $url;
  public $method;
  public $params;
  public $cacheDir;
  public $realm;
  public $mode;

  /* hash from HTTP method -> list of url objects */
  var $map = array();
  var $cached;
  
  /**
   * The constructor.
   *
   * @param string $mode The mode, either debug or production
   **/
  public function __construct($options = array()) {
    $defaults = array('mode' => 'debug',
                      'realm' => 'Rest Server',
                      'cacheDir' => dirname(__FILE__)."/cache",
                      'isCLI' => false,
                      'handlers' => array());
    $options = array_merge($defaults, $options);
    object_set_options($this, $options, array_keys($defaults));

    $this->loadCache();
    
    $handlers = $this->handlers; // copy because addHandler modified $this->handlers
    foreach ($handlers as $handler) {
      if (is_array($handler)) {
        $this->addHandler($handler[0], $handler[1]);
      } else {
        $this->addHandler($handler);
      }
    }
  }

  public function __destruct() {
    $this->writeCache();
  }

  /**
   * Stores the url map in cache if the object is not cached.
   **/
  protected function writeCache() {
    if ($this->mode == 'production' && !$this->cached) {
      if (function_exists('apc_store')) {
        apc_store('urlMap', $this->map);
      } else {
        file_put_contents($this->cacheDir . '/urlMap.cache', serialize($this->map));
      }
    }
  }

  /**
   * Load the cache from file.
   *
   * In debug mode, remove the cache.
   **/
  protected function loadCache() {
    if ($this->cached !== null) {
      return;
    }

    $this->cached = false;

    if ($this->mode == 'production') {
      if (function_exists('apc_fetch')) {
        $map = apc_fetch('urlMap');
      } elseif (file_exists($this->cacheDir . '/urlMap.cache')) {
        $map = unserialize(file_get_contents($this->cacheDir . '/urlMap.cache'));
      }
      if ($map && is_array($map)) {
        $this->map = $map;
        $this->cached = true;
      }
    } else {
      if (function_exists('apc_delete')) {
        apc_delete('urlMap');
      } else {
        @unlink($this->cacheDir . '/urlMap.cache');
      }
    }
  }

  /**
   * Handle an incoming HTTP request.
   *
   * Looks for the method by using findUrl(). Calls `init' on the
   * Returned object or class, `authorize' if authorization is
   * required, calls the actual method and returns the result.
   **/
  public function handle($path, $options = array()) {
    $defaults = array('throwException' => false);
    $options = array_merge($defaults, $options);
    
    if (isset($options["method"])) {
      $httpMethod = $options["method"];
    } else {
      $httpMethod = $this->getMethod();
    }

    if (isset($options["data"])) {
      $data = $options["data"];
    } else if (($httpMethod == 'PUT') || ($httpMethod == 'POST')) {
      $data = $this->getData();
    } else {
      $data = null;
    }

    try {
      if (isset($this->map[$httpMethod])) {
        $res = null;

        foreach ($this->map[$httpMethod] as $url => $handler) {
          $matches = $handler->matchPath($path, $data);
          if ($matches !== null) {
            $shouldCache = false;

            /* check caching */
            if ($handler->cache &&
                ($httpMethod == "GET") &&
                ($data == null) &&
                function_exists('apc_fetch')) {
              $shouldCache = true;
              $success = false;
              $res = apc_fetch("REST/path $path", $success);
              if ($success) {
                $info = apc_key_info("REST/path $path");
                if ($info && !$this->isCLI) {
                  header('Cache-Control: max-age='.$info["ttl"]);
                  header('Last-Modified: '.gmdate('D, d M Y H:i:s', $info["creation_time"]));
                  header('Expires: '.gmdate('"D, d M Y H:i:s', $info["expires"]));
                }
                
                return $res;
              }
            }

            /* normal handling */
            $params = $handler->genParams($matches);
            $res = $handler->call($params);
            if ($shouldCache && function_exists('apc_store')) {
              apc_store("REST/path $path", $res, $handler->cache);
              header('Cache-Control: max-age='.$handler->cache);
              header('Last-Modified: '.gmdate('D, d M Y H:i:s', time()));
              header('Expires: '.gmdate('"D, d M Y H:i:s', time() + $handler->cache));
            } else {
              if (!$this->isCLI) {
                header("Cache-Control: no-cache, must-revalidate");
                header("Expires: 0");
              }
            }
            return $res;
          }
        }
      }

      // not found, throw a 404
      throw new Exception('404');
    } catch (Exception $e) {
      if ($options["throwException"]) {
        throw $e;
      } else {
        $message = $this->codes[$e->getCode()]. ($e->getMessage() && $this->mode == 'debug' ? ': ' . $e->getMessage() : '');

        return array("status" => $e->getCode(),
                     "error" => true,
                     "data" => $message);
      }
    }
  }

  public function get($path) {
    $result = $this->handle($path, array("method" => "GET"));
    return $result["data"];
  }

  public function post($path, $data = null) {
    $result = $this->handle($path, array("method" => "POST",
                                         "data" => $data));
    return $result["data"];
  }

  public function put($path, $data = null) {
    $result = $this->handle($path, array("method" => "PUT",
                                         "data" => $data));
    return $result["data"];
  }

  public function delete($path) {
    $result = $this->handle($path, array("method" => "DELETE"));
    return $result["data"];
  }
  
  /**
   * Add a handler to the Rest Server.
   **/
  public function addHandler($handler, $basePath = '') {
    if (!$this->cached) {
      array_push($this->handlers, $handler);
      $this->handlers = array_unique($this->handlers);
      
      if (is_string($handler) && !class_exists($handler)) {
        throw new \Exception('Invalid method or class');
      } elseif (!is_string($handler) && !is_object($handler)) {
        throw new \Exception('Invalid method or class; must be a classname or object');
      }

      // remove leading /
      if (substr($basePath, 0, 1) == '/') {
        $basePath = substr($basePath, 1);
      }

      // add trailing /
      if ($basePath && substr($basePath, -1) != '/') {
        $basePath .= '/';
      }

      $this->generateMap($handler, $basePath);
    }
  }


  /**
   * Generate the url map for a specific handler.
   **/
  protected function generateMap($handler, $basePath) {
    if (is_object($handler)) {
      $reflection = new \ReflectionObject($handler);
    } elseif (class_exists($handler)) {
      $reflection = new \ReflectionClass($handler);
    }

    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
      $doc = $method->getDocComment();
      $noAuth = strpos($doc, '@noAuth') !== false;
      if (preg_match('/@cache (\d+)/', $doc, $matches)) {
        $cache = $matches[1];
      } else {
        $cache = false;
      }

      if (preg_match_all('/@url[ \t]+(GET|POST|PUT|DELETE|HEAD|OPTIONS)[ \t]+\/?(\S*)/s',
                         $doc, $matches, PREG_SET_ORDER)) {
        $params = $method->getParameters();

        foreach ($matches as $match) {
          $httpMethod = $match[1];
          $url = $basePath . $match[2];
          // remove trailing slash
          if ($url && $url[strlen($url) - 1] == '/') {
            $url = substr($url, 0, -1);
          }

          $args = array();
          foreach ($params as $param) {
            $args[$param->getName()] = $param->getPosition();
          }

          $options = array("httpMethod" => $httpMethod,
                           "url" => $url,
                           "class" => $handler,
                           "methodName" => $method->getName(),
                           "args" => $args,
                           "needsAuthorization" => !$noAuth,
                           "cache" => $cache);
          if ($method->isStatic()) {
            $urlHandler = new StaticUrlHandler($options);
          } else {
            $urlHandler = new UrlHandler($options);
          }

          $this->map[$httpMethod][$url] = $urlHandler;
        }
      }
    }
  }

  /***************************************************************************
   *
   * HTTP stuff
   *
   ***************************************************************************/

  /**
   * Get the HTTP method for the current HTTP request, taking the
   * X_HTTP_METHOD_OVERRIDE header into account.
   **/
  public function getMethod() {
    $method = $_SERVER['REQUEST_METHOD'];
    $override = isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']) ?
      $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] :
      (isset($_GET['method']) ? $_GET['method'] : '');
    if (($method == 'POST') && (strtoupper($override) == 'PUT')) {
      $method = 'PUT';
    } elseif (($method == 'POST') && (strtoupper($override) == 'DELETE')) {
      $method = 'DELETE';
    }

    return $method;
  }

  /**
   * Get the HTTP POST body data, and decode it as JSON.
   **/
  public function getData() {
    $data = file_get_contents('php://input');
    $data = json_decode($data, true);

    return $data;
  }

  /**
   * Send data and HTTP headers
   **/
  public function sendResult($result) {
    if (!$this->isCLI) {
      header("Content-Type: application/json");
      $status = $result["status"];
      $code = $this->codes[strval($status)];
      header("{$_SERVER['SERVER_PROTOCOL']} $status $code");
    }

    $data = json_encode($result["data"]);
    echo $data;
  }

  private $codes = array(
                         '100' => 'Continue',
                         '200' => 'OK',
                         '201' => 'Created',
                         '202' => 'Accepted',
                         '203' => 'Non-Authoritative Information',
                         '204' => 'No Content',
                         '205' => 'Reset Content',
                         '206' => 'Partial Content',
                         '300' => 'Multiple Choices',
                         '301' => 'Moved Permanently',
                         '302' => 'Found',
                         '303' => 'See Other',
                         '304' => 'Not Modified',
                         '305' => 'Use Proxy',
                         '307' => 'Temporary Redirect',
                         '400' => 'Bad Request',
                         '401' => 'Unauthorized',
                         '402' => 'Payment Required',
                         '403' => 'Forbidden',
                         '404' => 'Not Found',
                         '405' => 'Method Not Allowed',
                         '406' => 'Not Acceptable',
                         '409' => 'Conflict',
                         '410' => 'Gone',
                         '411' => 'Length Required',
                         '412' => 'Precondition Failed',
                         '413' => 'Request Entity Too Large',
                         '414' => 'Request-URI Too Long',
                         '415' => 'Unsupported Media Type',
                         '416' => 'Requested Range Not Satisfiable',
                         '417' => 'Expectation Failed',
                         '500' => 'Internal Server Error',
                         '501' => 'Not Implemented',
                         '503' => 'Service Unavailable'
                         );
}

class Exception extends \Exception {
  public function __construct($code, $message = null) {
    parent::__construct($message, $code);
  }
}

?>