<?php
chdir(__DIR__);
chdir(realpath('../../../../'));

if (getenv('APPLICATION_ENV') != 'prod') {
  chdir('/home/cabox/workspace');
  $env_file = 'env/' . $_SERVER['SERVER_NAME'] . '.json';
  if (file_exists($env_file)) {
    $vars = json_decode(file_get_contents($env_file), true);
    if (json_last_error() == JSON_ERROR_NONE) {
      foreach ($vars as $key => $value) {
        $_ENV[$key] = $value;
      }
    }
  }
}

if (!array_key_exists('BASE_LOG_FILE', $_ENV)) {
  $_ENV['BASE_LOG_FILE'] = 'php://stderr';
}

$_ENV['BASE_LOG_FILE'] = str_replace(
  '{{PORT}}',
  $_ENV['PORT'],
  $_ENV['BASE_LOG_FILE']);


require_once 'common.php';
require_once 'BaseParam.php';
require_once 'BaseStore.php';
require_once 'BaseWorker.php';
require_once 'Base.php';

// if (array_key_exists('APPLICATION_ENV', $_ENV) &&
//   $_ENV['APPLICATION_ENV'] != 'prod') {
//   register_shutdown_function('fatal_log');
// }
Base::registerAutoloader();
