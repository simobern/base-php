<?php
class BaseController {
  protected
    $app,
    $params,
    $restricted,
    $path,
    $skipParamValidation,
    $jsonForced;

  public final function __construct(
    $route = null,
    $params = [],
    $files = [],
    $can_access_restricted_endpoints = false
  ) {
    $this->restricted = false;
    $this->params = [];
    $this->path = $route;

    $this->skipParamValidation = false;

    try {
      $params = $this->params();
      if (true === $this->skipParamValidation) {
        $this->params = array_merge($_GET, $_POST, $_FILES);
      } elseif (is_array($params)) {
        foreach ($params as $param) {
          $this->params[$param->name()] = $param;
        }
      } else {
        throw new RuntimeException('Invalid params supplied.');
      }

      $this->init();
      $this->genFlow();
      $this->success = true;
      $this->out();

    } catch (Exception $e) {
      $this->success = false;
      $this->outError($e);
      return $this;
    }

    return $this;
  }

  protected function params() {
    return [];
  }

  protected function skipParamValidation() {$this->skipParamValidation = true;}

  // Controllers can override this and use it as a constructor.
  protected function init() {}

  // Controllers need to implement this in order to generate their flow.
  // Yielding a strict false will stop the controller and generate an
  // exception, which is useful to halt the execution flow when an error
  // occurs. An optional $error can be specified if you need to set the
  // exception message.
  protected function genFlow() {return [];}

  protected function isXHR() {
    $http_x_requested_with = idx($_SERVER, 'HTTP_X_REQUESTED_WITH');
    // WebView workaround
    if (strpos($http_x_requested_with, 'com.') !== false) {
      return false;
    }
    return !!$http_x_requested_with == 'XMLHTTPRequest';
  }

  protected function status(int $status) {
    if ($status == 404) {
      header('HTTP/1.0 404 Not Found');
    } else {
      header('HTTP/1.0 ' . $status);
    }
  }

  protected function forceJSON() {
    $this->jsonForced = true;
  }

  private function isJSONForced() {
    return $this->jsonForced;
  }

  private function out() {
    try {
      if ($this instanceof BaseListener) {
        $this->render();
      } elseif ($this->isXHR() || $this->isJSONForced()) {
        $view = $this->renderJSON();
        $view->render();
      } else {
        $layout = $this->render();
        // Pre-render layout in order to trigger widgets' CSSs and JSs
        $layout->__toString();
        if (method_exists($layout, 'hasSection')) {
          if ($layout->hasSection('stylesheets')) {
            foreach (BaseLayoutHelper::stylesheets() as $css) {
              $layout->section('stylesheets')->appendChild($css);
            }
          }

          if ($layout->hasSection('javascripts')) {
            foreach (BaseLayoutHelper::javascripts() as $js) {
              $layout->section('javascripts')->appendChild($js);
            }
          }
        }

        echo $layout;
      }
    } catch (Exception $e) {
      error_log(var_dump($e));
      die;
    }
  }

  private function outError(Exception $e) {
    if ($this instanceof BaseListener) {
      error_log(sprintf(
        '%s %s: %s (%s:%s)',
        get_class($this),
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()));
      return;
    } elseif ($this->isXHR()) {
      if (method_exists($this, 'renderJSONError')) {
        $view = $this->renderJSONError($e);
        $view->render();
      } else {
        $this->status(404);
        echo <h1>Not Found</h1>;
      }

    } else {
      if (method_exists($this, 'renderError')) {
        $layout = $this->renderError($e);
        if (!$layout) {
          return;
        }
        // Pre-render layout in order to trigger widgets' CSSs and JSs
        $layout->__toString();
        if (method_exists($layout, 'hasSection')) {
          if ($layout->hasSection('stylesheets')) {
            foreach (BaseLayoutHelper::stylesheets() as $css) {
              $layout->section('stylesheets')->appendChild($css);
            }
          }

          if ($layout->hasSection('javascripts')) {
            foreach (BaseLayoutHelper::javascripts() as $js) {
              $layout->section('javascripts')->appendChild($js);
            }
          }
        }

        echo $layout;

      } else {
        $this->status(404);
        echo <h1>Not Found</h1>;
      }
    }
  }

  // Called when the controller executes getFlow with success. Override this
  // method when you need to send extra field in your response.
  // protected function render() {echo '';}
  protected function renderJSON() {
    $view = new BaseJSONView();
    $view->success();
    return $view;
  }

  protected function renderError(Exception $e) {
    return <div>{print_r($e, true)}</div>;
  }

  protected function renderJSONError(Exception $e) {
    $view = new BaseJSONView();
    $view->error($e->getMessage(), 500, $e->getCode());
    return $view;
  }

  protected final function param($key) {
    return idx($this->params, $key) ? $this->params[$key]->value() : null;
  }

  protected final function env($key) {
    return idx($_SERVER, $key, null);
  }

  public final function done() {
    return $this->success;
  }

  protected final function redirect(URL $url) {
    header('Location: ' . $url->__toString());
    die;
  }
}

abstract class BaseView {
  protected $status;

  public function __construct() {
    $this->status = 200;
  }

  public function status(int $code = 200) {
    http_response_code($code);
  }

  abstract public function render(bool $return_instead_of_echo);
}

final class StringToHTML {

  private $html;

  public function __construct($html) {
    $this->html = $html;
  }

  public function toHTMLString() {
    return $this->html;
  }
}

abstract class StaticPageController {
  public function __construct() {
    $this->init();
    $this->genFlow();
    $this->out();
  }

  protected function init() {}
  protected function genFlow() {}

  private function getFileContents() {
    $called_class = get_called_class();
    $file = 'static/'.$called_class::PAGE;
    if (file_exists($file)) {
      $content = file_get_contents($file);
      $html = new StringToHTML($content);
      return $html;
    }
    invariant_violation('File does not exists');
  }

  private function out() {
    $layout = $this->render();
    $layout->__toString();
    if (method_exists($layout, 'hasSection')) {
      if ($layout->hasSection('stylesheets')) {
        foreach (BaseLayoutHelper::stylesheets() as $css) {
          $layout->section('stylesheets')->appendChild($css);
        }
      }

      if ($layout->hasSection('javascripts')) {
        foreach (BaseLayoutHelper::javascripts() as $js) {
          $layout->section('javascripts')->appendChild($js);
        }
      }

      if ($layout->hasSection('body')) {
        $layout->section('body')->appendChild($this->getFileContents());
      }
    }

    echo $layout;
  }

  abstract public function render();
}

abstract class BaseMutatorController extends BaseController {
  abstract public function render();

  public function renderError(Exception $e = null) {}
}

class BaseListener extends BaseController {
  final public function render() {}
  public function renderError(Exception $e = null) {}
}

class BaseJSONView extends BaseView {
  private $_payload;
  public final function success(
    array $data = null,
    int $http_status = 200
  ) {

    $this->status($http_status);
    $this->_payload = ['success' => true];

    if ($data) {
      $this->_payload['data'] = $data;
    }

    return $this;
  }

  public final function error(
    string $message,
    int $http_status = 500,
    int $code = -1
  ) {

    $this->status($http_status);
    $this->_payload = [
      'success' => false,
      'message' => $message,
      'code' => $code,
    ];

    return $this;
  }

  public final function render(bool $return_instead_of_echo = false) {
    if ($return_instead_of_echo) {
      return json_encode($this->_payload);
    } else {
      header('Access-Control-Allow-Origin: *');
      header('Content-type: application/json; charset: utf-8');
      echo json_encode($this->_payload);
    }
  }
}

class URL {
  protected $url;
  protected $query;
  public function __construct(string $url = null) {
    $current_url = parse_url($this->buildCurrentURL());
    $parsed_url = [];
    if ($url !== null) {
      $parsed_url = parse_url($url);
      invariant($parsed_url !== false, 'Invalid URL');
      if (!(idx($parsed_url, 'scheme') && idx($parsed_url, 'host'))) {
        unset($current_url['query']);
        $this->url = array_merge($current_url, $parsed_url);
      } else {
        $this->url = $parsed_url;
      }
    } else {
      $this->url = $current_url;
    }


    if (idx($this->url, 'query')) {
      parse_str($this->url['query'], $this->query);
    }
  }

  public static function route(string $name, array $params = []) {
    return BaseRouter::generateUrl($name, $params);
  }

  protected function buildCurrentURL() {
    $protocol = idx($_SERVER, 'REQUEST_SCHEME', 'https');

    if (idx($_SERVER, 'HTTP_X_FORWARDED_PROTO') === 'https') {
      $protocol = 'https';
    }

    $server_port_key =
      $protocol === 'https' ?
      'HTTPS_SERVER_PORT' :
      'HTTP_SERVER_PORT';

    return sprintf('%s://%s%s%s',
      $protocol,
      EnvProvider::get('SERVER_NAME'),
      EnvProvider::has($server_port_key) &&
      EnvProvider::get($server_port_key) != 80 &&
      EnvProvider::get($server_port_key) != 443 ?
        ':' . EnvProvider::get($server_port_key) :
        '',
      idx($_SERVER, 'REQUEST_URI'));
  }

  public function query(string $key,  $value = null) {
    if ($value === null) {
      return idx($this->query, $key, null);
    }

    $this->query[$key] = $value;
    return $this;
  }

  public function removeQuery($key = null) {
    if ($key === null) {
      $this->query = [];
    } elseif (idx($this->query, $key)) {
      unset($this->query[$key]);
    }
    return $this;
  }

  public function hash(string $hash = null) {
    if ($hash === null) {
      return idx($this->url, 'hash', null);
    }

    $this->url['hash'] = $hash;
    return $this;
  }

  public function port(int $port = null) {
    if ($port === null) {
      return idx($this->url, 'port', null);
    }

    if ($port === 0) {
      unset($this->url['port']);
    } else {
      $this->url['port'] = $port;
    }

    return $this;
  }

  public function user(string $user = null) {
    if ($user === null) {
      return idx($this->url, 'user', null);
    }

    $this->url['user'] = $user;
    return $this;
  }

  public function pass(string $pass = null) {
    if ($pass === null) {
      return idx($this->url, 'pass', null);
    }

    $this->url['pass'] = $pass;
    return $this;
  }

  public function host(string $host = null) {
    if ($host === null) {
      return idx($this->url, 'host', null);
    }

    $this->url['host'] = $host;
    return $this;
  }

  public function path(string $path = null) {
    if ($path === null) {
      return idx($this->url, 'path', null);
    }

    $this->url['path'] = $path;
    return $this;
  }

  public function scheme(string $scheme = null) {
    if ($scheme === null) {
      return idx($this->url, 'scheme', null);
    }

    $this->url['scheme'] = $scheme;
    return $this;
  }

  public function isAbsolute() {
    return !!(idx($this->url, 'scheme') && idx($this->url, 'host'));
  }

  public function __toString() {
    $query = '';
    if (!empty($this->query)) {
      $query = '?' . http_build_query($this->query);
    }

    $path = '';
    if (idx($this->url, 'path')) {
      $path = $this->url['path'][0] == '/' ?
        $this->url['path'] :
        '/' . $this->url['path'];
    }

    return sprintf(
      '%s%s%s%s%s%s%s%s%s%s%s',
      idx($this->url, 'scheme'),
      idx($this->url, 'scheme') ? '://' : '',
      idx($this->url, 'user') ? $this->url['user'] : '',
      idx($this->url, 'user') && idx($this->url, 'pass') ? ':' : '',
      idx($this->url, 'pass') ? $this->url['pass'] : '',
      idx($this->url, 'user') || idx($this->url, 'pass') ? '@' : '',
      idx($this->url, 'host') ? $this->url['host'] : '',
      idx($this->url, 'port') ? ':' . $this->url['port'] : '',
      $path,
      $query,
      idx($this->url, 'hash') ? '#' . $this->url['hash'] : ''
    );
  }
}

class BaseNotFoundController extends BaseController {
  protected function params() {
    return [
      BaseParam::StringType('path_info')
    ];
  }

  public function render() {
    $this->status(404);
    return <h1>Not Found: {$this->param('path_info')}</h1>;
  }

  public function renderJSON() {
    $view = new BaseJSONView();
    $view->error('Invalid endpoint: ' . $this->param('path_info'), 404);
    return $view;
  }

  public function renderJSONError($e) {
    $view = new BaseJSONView();
    $view->error('Invalid endpoint: ' . $this->param('path_info'), 404);
    return $view;
  }
}

class ApiRunner {
  protected
    $listeners,
    $pathInfo,
    $params,
    $paramNames;

  protected static $map = [];

  public function __construct($map) {
    self::$map = $map;
    $this->paramNames = [];
    $this->params = [];
    $this->listeners = [];
  }

  public static function getMap() {
    return self::$map;
  }

  protected function getPathInfo() {
    if ($this->pathInfo) {
      return $this->pathInfo;
    }

    if (strpos($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME']) === 0) {
        $script_name = $_SERVER['SCRIPT_NAME']; //Without URL rewrite
    } else {
        $script_name = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])); //With URL rewrite
    }

    $this->pathInfo = substr_replace(
      $_SERVER['REQUEST_URI'], '', 0, strlen($script_name));
    if (strpos($this->pathInfo, '?') !== false) {
        $this->pathInfo = substr_replace(
          $this->pathInfo, '', strpos($this->pathInfo, '?'));
    }

    $script_name = rtrim($script_name, '/');
    $this->pathInfo = '/' . ltrim($this->pathInfo, '/');


    return $this->pathInfo;
  }

  public function matches($resourceUri, $pattern)
  {
      //Convert URL params into regex patterns, construct a regex for this route, init params
      $patternAsRegex = preg_replace_callback('#:([\w]+)\+?#', [$this, 'matchesCallback'],
          str_replace(')', ')?', (string) $pattern));

      if (substr($pattern, -1) === '/') {
          $patternAsRegex .= '?';
      }

      //Cache URL params' names and values if this route matches the current HTTP request
      if (!preg_match('#^' . $patternAsRegex . '$#', $resourceUri, $paramValues)) {
          return false;
      }

      foreach ($this->paramNames as $name) {
          if (isset($paramValues[$name])) {
              if (isset($this->paramNamesPath[ $name ])) {
                  $this->params[$name] = explode('/', urldecode($paramValues[$name]));
              } else {
                  $this->params[$name] = urldecode($paramValues[$name]);
              }
          }
      }
      $_GET = array_merge($_GET, $this->params);
      return true;
  }

  /**
   * Convert a URL parameter (e.g. ":id", ":id+") into a regular expression
   * @param  array    URL parameters
   * @return string   Regular expression for URL parameter
   */
  protected function matchesCallback($m)
  {
      $this->paramNames[] = $m[1];
      if (isset($this->conditions[ $m[1] ])) {
          return '(?P<' . $m[1] . '>' . $this->conditions[ $m[1] ] . ')';
      }

      if (substr($m[0], -1) === '+') {
          $this->paramNamesPath[ $m[1] ] = 1;
          return '(?P<' . $m[1] . '>.+)';
      }

      return '(?P<' . $m[1] . '>[^/]+)';
  }

  protected function selectController() {
    foreach (self::$map as $v) {
      if ($this->matches($this->getPathInfo(), $v['route'])) {
        return $v['controller'];
      }
    }
    return false;
  }

  public function getRouteByURL(URL $url) {
    $map = [];
    foreach (self::$map as $key => $v) {
      if ($this->matches($url->path(), $v['route'])) {
        $map['route'] = $key;
        $map['params'] = $this->params;
        break;
      }
    }
    return $map;
  }

  protected function getRequestMethod() {
    return $_SERVER['REQUEST_METHOD'];
  }

  protected function getParams() {
    return $this->params;
  }

  protected function getFiles() {
    return [];
  }

  protected function canAccessRestrictedEndpoints() {
    return false;
  }

  protected function notFound() {
    $_GET['path_info'] = $this->getPathInfo();
    if ($this->fireEvent('notFound') === false) {
      $controller = new BaseNotFoundController();
      return $controller;
    }
  }

  public function addEventListener(string $event, string $controller) {
    if (class_exists($controller)) {
      $this->listeners[$event][] = $controller;
    } else {
      throw new Exception('Listener not found: ' . $controller);
    }
  }

  public function fireEvent($event) {
    if (!idx($this->listeners, $event)) {
      return false;
    }

    foreach ($this->listeners[$event] as $controller_name) {
      $controller = new $controller_name(
        $this->getPathInfo(),
        $this->getParams(),
        $this->getFiles(),
        $this->canAccessRestrictedEndpoints());
    }
  }

  protected function getAllHeaders() {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
     if (substr($name, 0, 5) == 'HTTP_') {
       $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
     }
    }
    return $headers;
  }

  public function run() {

    $this->fireEvent('preprocess');

    $method = $this->getRequestMethod();
    $controller_path = $this->selectController();
    $is_mutator = false;

    if (false === $controller_path) {
      return $this->notFound();
    }

    $controller_name = array_pop(explode('/', $controller_path));
    switch ($method) {
      case 'GET':
          $controller_name .= 'Controller';
        break;
      case 'HEAD':
      case 'OPTIONS':
        $controller_name .= ucfirst(strtolower($method)) . 'Controller';
        $controller_path .= ucfirst(strtolower($method));
        $origin = idx($this->getAllHeaders(), 'Origin');
        $allow_headers = array_keys($this->getAllHeaders());
        $allow_headers[] = 'Access-Control-Allow-Origin';
        $headers = implode(', ', $allow_headers);
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 604800');
        header('Access-Control-Allow-Headers: ' . $headers);
        die;
        break;

      case 'POST':
      case 'PUT':
      case 'DELETE':
        $is_mutator = true;
        $controller_path = str_replace($controller_name, '', $controller_path);
        // $controller_path .= 'mutators/' . $controller_name .
        //   ucfirst(strtolower($method));

        $controller_path .= $controller_name . ucfirst(strtolower($method));
        $controller_name .= ucfirst(strtolower($method)) . 'Controller';
        break;
    }

    $controller_path = 'controllers/' . $controller_path . '.php';

    if (false === strpos($controller_name, 'Controller') ||
      !file_exists($controller_path)) {
      return $this->notFound();
    }

    require_once $controller_path;
    if ($is_mutator &&
      get_parent_class($controller_name) != 'BaseMutatorController') {
      throw new Exception(
        sprintf(
          '%s must be an instance of BaseMutatorController',
          $controller_name));
      return null;
    }

    $controller = new $controller_name(
      $this->getPathInfo(),
      $this->getParams(),
      $this->getFiles(),
      $this->canAccessRestrictedEndpoints());

    $this->fireEvent('controllerEnd');
    return $controller;
  }
}

class BaseRouter {
  static protected $params = [];
  static private function getRouteByName(string $route_name) {
    $map = ApiRunner::getMap();
    invariant($map, 'Cannot get route map.');

    invariant(idx($map, $route_name), 'Route not found: %s', $route_name);

    invariant(
      idx($map[$route_name], 'route') && idx($map[$route_name], 'controller'),
      'Route or controller name not found for route %s', $route_name);

    return $map[$route_name]['route'];
  }

  static public function getParameterizedRoute(
    string $route,
     $matches,
    array $params = []
  ) {
    foreach ($matches[0] as $m) {
      $isMandatoryParam = $m[0] === '/' ? true : false;
      $paramName = $isMandatoryParam
        ? str_replace('/:', '', $m)
        : preg_replace('/\(\/:(\w+)\)/i', '$1', $m);
      $param = idx($params, $paramName);
      self::$params[$paramName] = $param;
      $find = $isMandatoryParam && $param
        ? ':' . $paramName
        : '(/:' . $paramName . ')';
      $replace = !$isMandatoryParam && $param
        ? '/' . $param : $param;

      if ($isMandatoryParam && !$param) {
        invariant_violation($paramName . ' is a mandatory parameter.');
      }
      $route = str_replace($find, $replace, $route);
    }
    return new URL($route);
  }

  static public function addOptionalParameter(URL $url,  $optionalParams) {
    $paramsKeys = array_keys(self::$params);
    $optionalParamsKeys = array_keys($optionalParams);
    $diffArray = array_diff($optionalParamsKeys, $paramsKeys);
    foreach ($diffArray as $p) {
      $url->query($p, (string) $optionalParams[$p]);
    }
    return $url;
  }

  static public function generateUrl(
    string $route_name,
    array $params = []
  ) {
    $matches = [];
    $route = self::getRouteByName($route_name);
    preg_match_all('#\(?\/:\w+\)?#', $route, $matches);
    $url = self::getParameterizedRoute($route, $matches, $params);
    if ($params) {
      $url = self::addOptionalParameter($url, $params);
    }
    self::$params = [];
    return $url;
  }
}

abstract class BaseEnum {
  protected $value;
  /**
   * Allows to reference a BaseEnum's value by calling:
   * AVeryLongEnumName::MY_VALUE()
   */
  public static function __callStatic($name, $args) {
    $const = static::class . '::' . $name;
    if (!defined($const)) {
      throw new InvalidArgumentException(
        sprintf(
          'Could not find enumeration %s in %s',
          $name,
          static::class));

      return null;
    } else {
      return new static($name);
    }
  }

  public final function __construct($key = '__default') {
    $this->setValue($key);
  }

  protected final function setValue($key) {
    if (static::isValid($key)) {
      $this->value = constant(static::class . '::' . $key);
    } else {
      throw new InvalidArgumentException(
        sprintf(
          'Could not find enumeration %s in %s',
          $key,
          get_class($this)));
    }
  }

  protected static final function isValid($key) {
    return defined(static::class . '::' . $key);
  }

  public static final function validValues() {
    $r = new ReflectionClass(get_called_class());
    return array_keys($r->getConstants());
  }

  public final function value() {
    return $this->value;
  }

  public final function __toString() {
    return (string)$this->value;
  }
}

class Base {
  public static function registerAutoloader() {
    spl_autoload_register(function ($class) {
      $map = [
        'Provider' => 'providers',
        'Model' => 'models',
        'Store' => 'storage',
        'Trait' => 'traits',
        'Enum' => 'enums',
        'Type' => 'enums',
        'Exception' => 'exceptions',
        'Controller' => 'controllers',
        'Worker' => 'workers',
        'Listener' => 'listeners',
      ];

      // These classes are stored in lib/base or lib/queue, so no need to
      // autoload
      switch($class) {
        case 'BaseModel':
        case 'BaseEnum':
        case 'BaseQueueStore':
        case 'BaseQueueFilesStore':
        case 'BaseQueueFileModel':
        case 'BaseStore':
        case 'BaseWorkerScheduler':
        case 'BaseWorker':
        case 'BaseListener':
        return;
      }

      $pattern = '/^(\w+)(' . implode('|', array_keys($map)) . ')$/';

      $class_type = [];
      if (preg_match($pattern, $class, $class_type)) {
        $dir = array_pop($class_type);
        require $map[$dir] . DIRECTORY_SEPARATOR . str_replace($dir, '', $class) . '.php';
      }

      $layout_type = [];
      if (preg_match('/^xhp_layout__([\d\w-]+)$/', $class, $layout_type)) {
        $layout = array_pop($layout_type);
        require 'layouts' . DIRECTORY_SEPARATOR . $layout . '.php';
      }

      $widget_type = [];
      if (preg_match('/^xhp_widget__([\d\w-]+)$/', $class, $widget_type)) {
        $widget = array_pop($widget_type);
        require 'widgets' . DIRECTORY_SEPARATOR . $widget . '.php';
      }

    });
  }
}

class MongoInstance {
  protected static $db = [];
  public static function get($collection = null, $with_collection = false) {
    if (strpos($collection, 'mongodb://') !== false) {
      $db_url = explode('/', $collection);
      $collection = $with_collection ? array_pop($db_url) : null;
      $db_url = implode('/', $db_url);
      $parts = parse_url($db_url);
      if (idx($parts, 'user') && idx($parts, 'pass')) {
        $auth_pattern = sprintf('%s:%s@', $parts['user'], $parts['pass']);
        $db_url = str_replace($auth_pattern, '', $db_url);
      }

    } elseif (isset($_ENV['MONGOHQ_URL'])) {
      $db_url = $_ENV['MONGOHQ_URL'];
      $parts = parse_url($db_url);
      if (idx($parts, 'user') && idx($parts, 'pass')) {
        $auth_pattern = sprintf('%s:%s@', $parts['user'], $parts['pass']);
        $db_url = str_replace($auth_pattern, '', $db_url);
      }
    } else {
      error_log('MongoInstance: No MONGOHQ_URL specified or invalid collection.');
      error_log(sprintf('MONGOHQ_URL: %s, collection: %s',
        idx($_SERVER, 'MONGOHQ_URL'),
        $collection));
      error_log('_ENV[MONGOHQ_URL]:', $_ENV['MONGOHQ_URL']);

      throw new Exception('No MONGOHQ_URL specified');
      return null;
    }

    if (idx(self::$db, $db_url)) {
      return $collection != null ?
        self::$db[$db_url]->selectCollection($collection) :
        self::$db[$db_url];
    }

    $dbname = array_pop(explode('/', $db_url));

    $db = new MongoDB\Client($_ENV['MONGOHQ_URL']);
    self::$db[$db_url] = $db->selectDatabase($dbname);

    if ($collection) {
      return $collection != null ?
        self::$db[$db_url]->selectCollection($collection) :
        self::$db[$db_url];
    }
  }
}

class MongoFn {
  public static function get($file, $scope = []) {
    $code = file_get_contents('mongo_functions/' . $file . '.js');
    return new MongoCode($code, $scope);
  }
}

abstract class :base:layout extends :x:element {
  public function init() {}

  final public function section(string $section) {
    invariant(
      isset($this->sections),
      'At least one section need to be defined');

    invariant(
      idx($this->sections, $section) !== null,
      'Section %s does not exist', $section);

    return $this->sections[$section];
  }

  final public function hasSection(string $section) {
    return !!idx($this->sections, $section, false);
  }

  public function render() {}
}

class :base:widget extends :x:element {
  public function init() {
    $widget_name = self::class2element(get_called_class());
    $widget_name = str_replace('widget:', '', $widget_name);
    $this->setAttribute('data-widget', $widget_name);
  }

  public function getName() {
    return $this->getAttribute('data-widget');
  }

  public final function css( $url) {
    invariant(is_string($url) || is_array($url), 'url must be array or string');

    if (is_array($url)) {
      foreach ($url as $u) {
        invariant(is_string($u), 'Invalid string provided');
        BaseLayoutHelper::addStylesheet(<link rel="stylesheet" href={$u} />);
      }
    } elseif (is_string($url)) {
      BaseLayoutHelper::addStylesheet(<link rel="stylesheet" href={$url} />);
    }
  }

  public final function js( $url) {
    invariant(is_string($url) || is_array($url), 'url must be array or string');

    if (is_array($url)) {
      foreach ($url as $u) {
        invariant(is_string($u), 'Invalid string provided');
        BaseLayoutHelper::addJavascript(
          <script type="text/javascript" src={$u}></script>);
      }
    } elseif (is_string($url)) {
      BaseLayoutHelper::addJavascript(
        <script type="text/javascript" src={$url}></script>);
    }
  }

  public function render() {}
}

class BaseLayoutHelper {
  protected static $build;
  protected static $resources = [
    'layout' => [
      'local' => [
        'css' => [],
        'js' => []],
      'remote' => [
        'css' => [],
        'js' => []]],
    'widget' => [
      'local' => [
        'css' => [],
        'js' => []],
      'remote' => [
        'css' => [],
        'js' => []]],
  ];

  public static function addJavascript(
    :script $javascript,
    bool $layout = false
  ) {
    $url = $javascript->getAttribute('src');

    $element = $layout ? 'layout' : 'widget';
    $origin = self::isLocal($url) ? 'local' : 'remote';

    self::$resources[$element][$origin]['js'][$url] = $javascript;
  }

  public static function addStylesheet(
    :link $stylesheet,
    bool $layout = false
  ) {
    $url = $stylesheet->getAttribute('href');

    $element = $layout ? 'layout' : 'widget';
    $origin = self::isLocal($url) ? 'local' : 'remote';

    self::$resources[$element][$origin]['css'][$url] = $stylesheet;
  }

  protected static function isLocal(string $url) {
    return !!(preg_match('/^(https?:)?(\/\/)/', $url) === 0);
  }

  protected static function cacheBuster() {
    if (!EnvProvider::get('ENABLE_RESOURCES_COMPRESSION') ||
      !EnvProvider::get('DYNAMIC_RESOURCES_CACHE_BUSTER')) {
      return '';
    }

    if (self::$build === null) {
      self::$build = file_exists('build') ? file_get_contents('build') : time();
    }

    return self::$build != false ? (string)self::$build : '';
  }

  protected static function hash(string $type) {
    switch ($type) {
      case 'js':
        $array = array_merge(
          array_keys(self::$resources['layout']['local']['js']),
          array_keys(self::$resources['widget']['local']['js']));
        break;
      case 'css':
        $array = array_merge(
          array_keys(self::$resources['layout']['local']['css']),
          array_keys(self::$resources['widget']['local']['css']));
        break;
    }

    $map = $array;

    // Preserve file order so that JS and CSS can respect loading priority.
    // Layout resources go first.
    natsort($map);
    $map = array_values($map);

    $hash = sha256(implode(':', $map)) . '.' . $type;
    $file_path = sys_get_temp_dir() . '/' . $hash;

    if (!file_exists($file_path)) {
      $content = '';
      foreach ($array as $file) {
        $content .= file_get_contents('public/' . $file) . PHP_EOL;
      }
      file_put_contents($file_path, $content);
    }

    $cache_buster = self::cacheBuster();
    $route_params = [
        'type' => $type,
        'hash' => $hash,
    ];

    if ($cache_buster) {
      $route_params['c'] = $cache_buster;
    }

    return URL::route('dynamic_resource', $route_params);
  }

  public static function javascripts() {
    if (EnvProvider::get('ENABLE_RESOURCES_COMPRESSION') == 1) {
      return array_merge(
        self::$resources['layout']['remote']['js'],
        self::$resources['widget']['remote']['js'],
        [<script src={self::hash('js')} />]);
    } else {
      return array_merge(
        self::$resources['layout']['remote']['js'],
        self::$resources['widget']['remote']['js'],
        self::$resources['layout']['local']['js'],
        self::$resources['widget']['local']['js']);
    }
  }

  public static function stylesheets() {
    if (EnvProvider::get('ENABLE_RESOURCES_COMPRESSION') == 1) {
      return array_merge(
        self::$resources['layout']['remote']['css'],
        self::$resources['widget']['remote']['css'],
        [<link rel="stylesheet" href={self::hash('css')} />]);
    } else {
      return array_merge(
        self::$resources['layout']['remote']['css'],
        self::$resources['widget']['remote']['css'],
        self::$resources['layout']['local']['css'],
        self::$resources['widget']['local']['css']);
    }
  }
}

class BaseTranslationHolder {
  static $projects = [];

  static protected function loadProject(string $locale, string $project) {
    if (idx(static::$projects, $locale) &&
      idx(static::$projects[$locale], $project)) {
      return true;
    }

    $project_path = sprintf('projects/%s/%s.json', $locale, $project);

    if (!file_exists($project_path)) {
      error_log('The requested translation project is missing:', $project_path);
      static::$projects[$locale][$project] = [];
      return false;
    } else {
      static::$projects[$locale][$project] =
        json_decode(file_get_contents($project_path));

      invariant(
        json_last_error() == JSON_ERROR_NONE,
        'Failed parsing translation project: ', $project_path);

      return true;
    }
  }

  static public function translation(
    string $locale,
    string $project,
    string $key
  ) {
    if (!isset(static::$projects[$locale][$project])) {
      static::loadProject($locale, $project);
    }
    $key = trim($key);
    return idx(static::$projects[$locale][$project], $key, $key);
  }
}

class :t extends :x:primitive {
  category %phrase, %flow, %pcdata;
  children (pcdata | %translation)*;
  attribute
    string locale,
    string project,
    string description;

  public function init() {
    if ($this->getAttribute('locale')) {
      $this->locale = $this->getAttribute('locale');
    } elseif (EnvProvider::getLocale()) {
      $this->locale = EnvProvider::getLocale();
    } else {
      invariant_violation('No default locale provided');
    }

    $this->setAttribute('project', 'main');
  }

  public function stringify() {
    $key = '';
    $tokens = [];
    foreach ($this->getChildren() as $elem) {
      if (is_string($elem)) {
        $key .= $elem;
      } else {
        $token_key = sprintf('{%s}', $elem->getAttribute('name'));
        $tokens[$token_key] = $elem->stringify();
        $key .= $token_key;
      }
    }

    $translation = BaseTranslationHolder::translation(
      $this->locale,
      $this->getAttribute('project'),
      $key);

    foreach ($tokens as $token_key => &$token) {
      $translated_token = BaseTranslationHolder::translation(
        $this->locale,
        $this->getAttribute('project'),
        $token_key);

      $token = $translated_token === $token_key ?
        $token :
        $translated_token;
    }

    return str_replace(
      array_keys($tokens),
      array_values($tokens),
      $translation);
  }
}

class :t:p extends :x:primitive {
  category %translation;
  children (pcdata);
  attribute
    string name @required;

  public function stringify() {
    $children = $this->getChildren();
    if (array_key_exists(0, $children)) {
      return $children[0];
    }
    return;
  }
}

class TemplateEngine {

  protected $template;
  protected $templateRaw;
  protected $css;
  protected $vars;

  public function __construct(string $template_name) {
    $this->vars = [];
    $this->templateRaw = $this->getResource('templates/'.$template_name);
  }

  protected function getResource(string $path) {
    $buffer = file_get_contents($path);
    invariant($buffer, 'Invalid resource '.$path);
    return $buffer;
  }

  public function loadCSS( $css_path) {
    invariant(
      is_string($css_path) || is_array($css_path),
      'url must be array or string');

    if (is_array($css_path)) {
      foreach ($css_path as $css) {
        $this->css .= $this->getResource('public/'.$css);
      }
    } else if (is_string($css_path)) {
      $this->css = $this->getResource('public/'.$css_path);
    }
    $this->css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $this->css);
    $this->css = str_replace(': ', ':', $this->css);
    $this->css = str_replace(
      ["\r\n", "\r", "\n", "\t", '  ', '    ', '    '],
      '',
      $this->css);
    return $this->css;
  }

  public function setVar(string $key,  $value) {
    $this->vars->set($key, $value);
    return $this;
  }

  public function layout(bool $render_html = false) {
    $keys = [];
    $values = [];
    foreach ($this->vars as $key => $value) {
      $keys[] = $key;
      $values[] = $value;
    }

    $this->templateRaw = str_replace($keys, $values, $this->templateRaw);
    $this->template = new StringToHTML($this->templateRaw);
    if ($render_html) {
      return $this->template;
    }
    return $this->templateRaw;
  }
}