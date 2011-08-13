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

class Server {
  public $url;
  public $method;
  public $params;
  public $cacheDir;
  public $realm;
  public $mode;

  /* hash from HTTP method -> list of url objects */
  protected $map = array();
  protected $errorClasses = array();
  protected $cached;
  
  /**
   * The constructor.
   *
   * @param string $mode The mode, either debug or production
   **/
  public function __construct($options = array()) {
    $defaults = array('mode' => 'debug',
                      'realm' => 'Rest Server',
                      'cacheDir' => dirname(__FILE__)."/cache");
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
    if ($ask) {
      header("WWW-Authenticate: Basic realm=\"$this->realm\"");
    }
    throw new RestException(401, "You are not authorized to access this resource.");
  }

  /**
   * Handle an incoming HTTP request.
   *
   * Looks for the method by using findUrl(). Calls `init' on the
   * Returned object or class, `authorize' if authorization is
   * required, calls the actual method and returns the result.
   **/
  public function handle($path) {
    $this->url = $path;
    $this->method = $this->getMethod();
    
    if (($this->method == 'PUT') || ($this->method == 'POST')) {
      $this->data = $this->getData();
    }
    
    list ($obj, $method, $params, $this->params, $noAuth, $isStatic) = $this->findUrl();
    
    if ($obj) {
      try {
        /* static method */
        if ($isStatic) {
          if (!$noAuth && method_exists($obj, 'authorize')) {
            if (!$obj::authorize()) {
              $this->sendData($this->unauthorized(false));
              exit();
            }
          }
        } else {
        
          if (is_string($obj)) {
            if (class_exists($obj)) {
              $obj = new $obj();
            } else {
              throw new \Exception("Class $obj does not exist");
            }
          }
        
          $obj->server = $this;

          if (method_exists($obj, 'init')) {
            $obj->init();
          }
          
          if (!$noAuth && method_exists($obj, 'authorize')) {
            if (!$obj->authorize()) {
              $this->sendData($this->unauthorized(false));
              exit();
            }
          }
        }
      
        $result = call_user_func_array(array($obj, $method), $params);
      } catch (RestException $e) {
        $this->handleError($e->getCode(), $e->getMessage());
        return;
      }
    
      if ($result != null) {
        $this->sendData($result);
      }
    } else {
      $this->handleError(404);
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
   * Add an error class.
   **/
  public function addErrorClass($class) {
    $this->errorClasses[] = $class;
  }

  /**
   * Handle a HTTP error by looking up the correct class and deferring to it.
   **/
  public function handleError($statusCode, $errorMessage = null) {
    $method = "handle$statusCode";
    foreach ($this->errorClasses as $class) {
      if (is_Object($class)) {
        $reflection = new ReflectionObject($class);
      } elseif (class_exists($class)) {
        $reflection = new ReflectionClass($class);
      }

      if ($reflection->hasMethod($method)) {
        $obj = is_string($class) ? new $class() : $class;
        $obj->$method();
        return;
      }
    }


    $message = $this->codes[$statusCode]. ($errorMessage && $this->mode == 'debug' ? ': ' . $errorMessage : '');

    $this->setStatus($statusCode);
    $this->sendData(array('error' => array('code' => $statusCode,
                                           'message' => $message)));
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
  protected function findUrl() {
    $urls = $this->map[$this->method];
    if (!$urls) {
      return null;
    }

    foreach ($urls as $url => $call) {
      $args = $call[2];

      if (!strstr($url, '$')) {
        /* no variable in url, no regexp needed */
        if ($url == $this->url) {
          if (isset($args['params'])) {
            $params[$args['params']] = $_GET;
          }
          if (isset($args['data'])) {
            $params = array_fill(0, $args['data'] + 1, null);
            $params[$args['data']] = $this->data;
            $call[2] = $params;
          }
          return $call;
        }
      } else {
        $regex = preg_replace('/\\\\\$([\w\d]+)\.\.\./', '(?P<$1>.+)', str_replace('\.\.\.', '...', preg_quote($url)));
        $regex = preg_replace('/\\\\\$([\w\d]+)/', '(?P<$1>[^\/]+)', $regex);
        if (preg_match(":^$regex$:", urldecode($this->url), $matches)) {
          $params = array();
          $paramMap = array();
          if (isset($args['params'])) {
            $params[$args['params']] = $_GET;
          }

          if (isset($args['data'])) {
            $params[$args['data']] = $this->data;
          }

          foreach ($matches as $arg => $match) {
            if (is_numeric($arg)) {
              continue;
            }
            $paramMap[$arg] = $match;

            if (isset($args[$arg])) {
              $params[$args[$arg]] = $match;
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
          ksort($params);
          $call[2] = $params;
          $call[3] = $paramMap;
          return $call;
        }
      }
    }
  }

  /**
   * Generate the url map for a specific class.
   **/
  protected function generateMap($class, $basePath) {
    if (is_object($class)) {
      $reflection = new ReflectionObject($class);
    } elseif (class_exists($class)) {
      $reflection = new ReflectionClass($class);
    }

    $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

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
            $Url = substr($Url, 0, -1);
          }
          $call = array($class, $method->getName());
          $args = array();
          foreach ($params as $param) {
            $args[$param->getName()] = $param->getPosition();
          }
          $call[] = $args;
          $call[] = null;
          $call[] = $noAuth;
          $call[] = $method->isStatic();

          $this->map[$httpMethod][$url] = $call;
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
    header("Cache-Control: no-cache, must-revalidate");
    header("Expires: 0");
    header("Content-Type: application/json");

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
    header("{$_SERVER['SERVER_PROTOCOL']} $code");
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

class RestException extends \Exception {
  public function __construct($code, $message = null) {
    parent::__construct($message, $code);
  }
}

?>