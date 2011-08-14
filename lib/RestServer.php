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

/**
 * Object that describes a single REST method url.
 **/
class UrlHandler {
  public $httpMethod;
  public $class;
  public $methodName;
  public $args;
  public $needsAuthorization;
  public $isStatic;
  public $url;

  public function __construct($options = array()) {
    $defaults = array("httpMethod" => null,
                      "class" => null,
                      "methodName" => null,
                      "args" => array(),
                      "needsAuthorization" => false,
                      "isStatic" => false,
                      "url" => "");
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
    
    foreach ($matches as $arg => $match) {
      if (is_numeric($arg)) {
        continue;
      }
      
      if (isset($this->args[$arg])) {
        $params[$this->args[$arg]] = $match;
      }
    }
    ksort($params);
    end($params);
    
    $max = key($params);
    for ($i = 0; $i < $max; $i++) {
      if (!key_exists($i, $params)) {
        $params[$i] = null;
      }
    }
  
    return $params;
  }

  /**
   * Check if the url handlers matches the given path.
   *
   * Returns null if it doesn't match, or an array of bound parameters for the method call.
   **/
  public function matchPath($path, $data) {
    $params = array();
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

    return $this->genParams(array_merge(array('params' => $_GET,
                                              'data' => $data),
                                        $matches));
  }
  
  public function handleCall($params) {
    /* static method */
    if ($this->isStatic) {
      if ($this->needsAuthorization && method_exists($this->class, 'authorize')) {
        $class = $this->class;
        if (!$class::authorize()) {
          $this->unauthorized(false);
          return;
        }
      }

      $obj = $this->class;
    } else {
      if (is_string($this->class)) {
        $className = $this->class;
        if (class_exists($className)) {
          $obj = new $className();
        } else {
          throw new \Exception("Class $className does not exist");
        }
      } else {
        $obj = $this->class;
      }
        
      if (method_exists($obj, 'init')) {
        $obj->init();
      }
          
      if ($this->needsAuthorization && method_exists($obj, 'authorize')) {
        if (!$obj->authorize()) {
          $this->unauthorized(false);
          return;
        }
      }
    }
      
    $result = call_user_func_array(array($obj, $this->methodName), $params);
    if ($result != null) {
      return array("status" => '200',
                   "error" => false,
                   "data" => $result);
    }
  }    
};

class Server {
  public $url;
  public $method;
  public $params;
  public $cacheDir;
  public $realm;
  public $mode;

  /* hash from HTTP method -> list of url objects */
  var $map = array();
  var $map2 = array();
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
                      'isCLI' => false);
    $options = array_merge($defaults, $options);
    object_set_options($this, $options, array_keys($defaults));
  }

  /**
   * The destructor. Stores the url map in cache if the object is not cached.
   **/
  public function __destruct() {
    if ($this->mode == 'production' && !$this->cached) {
      if (function_exists('apc_store')) {
        apc_store('urlMap', $this->map);
      } else {
        file_put_contents($this->cacheDir . '/urlMap.cache', serialize($this->map));
      }
    }
  }

  /**
   * Handle an unauthorized call by asking for HTTP authentication.
   **/
  public function unauthorized($ask = false) {
    if ($ask && !$this->isCLI) {
      header("WWW-Authenticate: Basic realm=\"$this->realm\"");
    }
    throw new Exception(401, "You are not authorized to access this resource.");
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

    $this->data = null;
    if (isset($options["data"])) {
      $this->data = $options["data"];
    } else {
      if (($httpMethod == 'PUT') || ($httpMethod == 'POST')) {
        $this->data = $this->getData();
      }
    }

    list ($obj, $params) = $this->findUrl($httpMethod, $path);

    try {
      if ($obj) {
        return $obj->handleCall($params);
      } else {
        throw new Exception(404);
      }
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

  /**
   * Add a class to the Rest Server.
   **/
  public function addClass($class, $basePath = '') {
    $this->loadCache();

    if (!$this->cached) {
      if (is_string($class) && !class_exists($class)) {
        throw new \Exception('Invalid method or class');
      } elseif (!is_string($class) && !is_object($class)) {
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

      $this->generateMap($class, $basePath);
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
   * Find the url in the registered classes.
   **/
  protected function findUrl($httpMethod, $path) {
    if (!isset($this->map[$httpMethod])) {
      return null;
    }
    
    $urls = $this->map[$httpMethod];

    foreach ($urls as $url => $handler) {
      $params = $handler->matchPath($path, $this->data);
      if ($params !== null) {
        return array($handler, $params);
      } else {
        continue;
      }
    }
  }

  /**
   * Generate the url map for a specific class.
   **/
  protected function generateMap($class, $basePath) {
    if (is_object($class)) {
      $reflection = new \ReflectionObject($class);
    } elseif (class_exists($class)) {
      $reflection = new \ReflectionClass($class);
    }

    $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

    foreach ($methods as $method) {
      $doc = $method->getDocComment();
      $noAuth = strpos($doc, '@noAuth') !== false;

      if (preg_match_all('/@url[ \t]+(GET|POST|PUT|DELETE|HEAD|OPTIONS)[ \t]+\/?(\S*)/s',
                         $doc, $matches, PREG_SET_ORDER)) {
        $params = $method->getParameters();

        foreach($matches as $match) {
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

          $handler = new UrlHandler(array("httpMethod" => $httpMethod,
                                          "url" => $url,
                                          "class" => $class,
                                          "methodName" => $method->getName(),
                                          "args" => $args,
                                          "needsAuthorization" => !$noAuth,
                                          "isStatic" => $method->isStatic()));
          $this->map[$httpMethod][$url] = $handler;
        }
      }
    }
  }

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
   *
   **/
  public function sendData($data) {
    if (!$this->isCLI) {
      header("Cache-Control: no-cache, must-revalidate");
      header("Expires: 0");
      header("Content-Type: application/json");
    }

    if (is_object($data) && method_exists($data, '__keepOut')) {
      /* remove data that shouldn't be serialized */
      $data = clone $data;
      foreach ($data->__keepOut() as $prop) {
        unset($data->$prop);
      }
    }

    $data = json_encode($data);

    echo $data;
  }

  /**
   * Set the HTTP code for this request.
   **/
  public function setStatus($code) {
    $code .= ' ' . $this->codes[strval($code)];
    if (!$this->isCLI) {
      header("{$_SERVER['SERVER_PROTOCOL']} $code");
    }
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