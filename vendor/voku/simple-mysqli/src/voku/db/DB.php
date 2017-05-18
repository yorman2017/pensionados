<?php

namespace voku\db;

use voku\cache\Cache;
use voku\helper\UTF8;

/**
 * DB: this handles DB queries via MySQLi
 *
 * @package   voku\db
 */
final class DB
{

  /**
   * @var int
   */
  public $query_count = 0;

  /**
   * @var \mysqli
   */
  private $link = false;

  /**
   * @var bool
   */
  private $connected = false;

  /**
   * @var array
   */
  private $mysqlDefaultTimeFunctions;

  /**
   * @var string
   */
  private $hostname = '';

  /**
   * @var string
   */
  private $username = '';

  /**
   * @var string
   */
  private $password = '';

  /**
   * @var string
   */
  private $database = '';

  /**
   * @var int
   */
  private $port = 3306;

  /**
   * @var string
   */
  private $charset = 'utf8';

  /**
   * @var string
   */
  private $socket = '';

  /**
   * @var bool
   */
  private $session_to_db = false;

  /**
   * @var bool
   */
  private $_in_transaction = false;

  /**
   * @var bool
   */
  private $_convert_null_to_empty_string = false;

  /**
   * @var Debug
   */
  private $_debug;

  /**
   * __construct()
   *
   * @param string         $hostname
   * @param string         $username
   * @param string         $password
   * @param string         $database
   * @param int            $port
   * @param string         $charset
   * @param boolean|string $exit_on_error use a empty string "" or false to disable it
   * @param boolean|string $echo_on_error use a empty string "" or false to disable it
   * @param string         $logger_class_name
   * @param string         $logger_level  'TRACE', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'
   * @param boolean|string $session_to_db use a empty string "" or false to disable it
   */
  protected function __construct($hostname, $username, $password, $database, $port, $charset, $exit_on_error, $echo_on_error, $logger_class_name, $logger_level, $session_to_db)
  {
    $this->connected = false;

    $this->_debug = new Debug($this);

    $this->_loadConfig(
        $hostname,
        $username,
        $password,
        $database,
        $port,
        $charset,
        $exit_on_error,
        $echo_on_error,
        $logger_class_name,
        $logger_level,
        $session_to_db
    );

    $this->connect();

    $this->mysqlDefaultTimeFunctions = array(
      // Returns the current date.
      'CURDATE()',
      // CURRENT_DATE	| Synonyms for CURDATE()
      'CURRENT_DATE()',
      // CURRENT_TIME	| Synonyms for CURTIME()
      'CURRENT_TIME()',
      // CURRENT_TIMESTAMP | Synonyms for NOW()
      'CURRENT_TIMESTAMP()',
      // Returns the current time.
      'CURTIME()',
      // Synonym for NOW()
      'LOCALTIME()',
      // Synonym for NOW()
      'LOCALTIMESTAMP()',
      // Returns the current date and time.
      'NOW()',
      // Returns the time at which the function executes.
      'SYSDATE()',
      // Returns a UNIX timestamp.
      'UNIX_TIMESTAMP()',
      // Returns the current UTC date.
      'UTC_DATE()',
      // Returns the current UTC time.
      'UTC_TIME()',
      // Returns the current UTC date and time.
      'UTC_TIMESTAMP()',
    );
  }

  /**
   * Load the config from the constructor.
   *
   * @param string         $hostname
   * @param string         $username
   * @param string         $password
   * @param string         $database
   * @param int            $port
   * @param string         $charset
   * @param boolean|string $exit_on_error use a empty string "" or false to disable it
   * @param boolean|string $echo_on_error use a empty string "" or false to disable it
   * @param string         $logger_class_name
   * @param string         $logger_level
   * @param boolean|string $session_to_db use a empty string "" or false to disable it
   *
   * @return bool
   */
  private function _loadConfig($hostname, $username, $password, $database, $port, $charset, $exit_on_error, $echo_on_error, $logger_class_name, $logger_level, $session_to_db)
  {
    $this->hostname = (string)$hostname;
    $this->username = (string)$username;
    $this->password = (string)$password;
    $this->database = (string)$database;

    if ($charset) {
      $this->charset = (string)$charset;
    }

    if ($port) {
      $this->port = (int)$port;
    } else {
      /** @noinspection PhpUsageOfSilenceOperatorInspection */
      $this->port = (int)@ini_get('mysqli.default_port');
    }

    // fallback
    if (!$this->port) {
      $this->port = 3306;
    }

    if (!$this->socket) {
      /** @noinspection PhpUsageOfSilenceOperatorInspection */
      $this->socket = @ini_get('mysqli.default_socket');
    }

    if ($exit_on_error === true || $exit_on_error === false) {
      $this->_debug->setExitOnError($exit_on_error);
    }

    if ($echo_on_error === true || $echo_on_error === false) {
      $this->_debug->setEchoOnError($echo_on_error);
    }

    $this->_debug->setLoggerClassName($logger_class_name);
    $this->_debug->setLoggerLevel($logger_level);

    $this->session_to_db = (boolean)$session_to_db;

    return $this->showConfigError();
  }

  /**
   * Show config errors by throw exceptions.
   *
   * @return bool
   *
   * @throws \Exception
   */
  public function showConfigError()
  {

    if (
        !$this->hostname
        ||
        !$this->username
        ||
        !$this->database
    ) {

      if (!$this->hostname) {
        throw new \Exception('no-sql-hostname');
      }

      if (!$this->username) {
        throw new \Exception('no-sql-username');
      }

      if (!$this->database) {
        throw new \Exception('no-sql-database');
      }

      return false;
    }

    return true;
  }

  /**
   * Open a new connection to the MySQL server.
   *
   * @return boolean
   */
  public function connect()
  {
    if ($this->isReady()) {
      return true;
    }

    \mysqli_report(MYSQLI_REPORT_STRICT);
    try {
      $this->link = \mysqli_init();

      if (Helper::isMysqlndIsUsed() === true) {
        \mysqli_options($this->link, MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
      }

      /** @noinspection PhpUsageOfSilenceOperatorInspection */
      $this->connected = @\mysqli_real_connect(
          $this->link,
          $this->hostname,
          $this->username,
          $this->password,
          $this->database,
          $this->port,
          $this->socket
      );
    } catch (\Exception $e) {
      $this->_debug->displayError('Error connecting to mysql server: ' . $e->getMessage(), true);
    }
    \mysqli_report(MYSQLI_REPORT_OFF);

    if (!$this->connected) {
      $this->_debug->displayError('Error connecting to mysql server: ' . \mysqli_connect_error(), true);
    } else {
      $this->set_charset($this->charset);
    }

    return $this->isReady();
  }

  /**
   * Check if db-connection is ready.
   *
   * @return boolean
   */
  public function isReady()
  {
    return $this->connected ? true : false;
  }

  /**
   * Get a new "Prepare"-Object for your sql-query.
   *
   * @param string $query
   *
   * @return Prepare
   */
  public function prepare($query)
  {
    return new Prepare($this, $query);
  }

  /**
   * Execute a sql-query and return the result-array for select-statements.
   *
   * @param $query
   *
   * @return mixed
   * @deprecated
   * @throws \Exception
   */
  public static function qry($query)
  {
    $db = self::getInstance();

    $args = func_get_args();
    /** @noinspection SuspiciousAssignmentsInspection */
    $query = array_shift($args);
    $query = str_replace('?', '%s', $query);
    $args = array_map(
        array(
            $db,
            'escape',
        ),
        $args
    );
    array_unshift($args, $query);
    $query = call_user_func_array('sprintf', $args);
    $result = $db->query($query);

    if ($result instanceof Result) {
      return $result->fetchAllArray();
    }

    return $result;
  }

  /**
   * getInstance()
   *
   * @param string      $hostname
   * @param string      $username
   * @param string      $password
   * @param string      $database
   * @param string      $port          default is (int)3306
   * @param string      $charset       default is 'utf8' or 'utf8mb4' (if supported)
   * @param bool|string $exit_on_error use a empty string "" or false to disable it
   * @param bool|string $echo_on_error use a empty string "" or false to disable it
   * @param string      $logger_class_name
   * @param string      $logger_level
   * @param bool|string $session_to_db use a empty string "" or false to disable it
   *
   * @return \voku\db\DB
   */
  public static function getInstance($hostname = '', $username = '', $password = '', $database = '', $port = '', $charset = '', $exit_on_error = '', $echo_on_error = '', $logger_class_name = '', $logger_level = '', $session_to_db = '')
  {
    /**
     * @var $instance DB[]
     */
    static $instance = array();

    /**
     * @var $firstInstance DB
     */
    static $firstInstance = null;

    if (
        $hostname . $username . $password . $database . $port . $charset == ''
        &&
        null !== $firstInstance
    ) {
      return $firstInstance;
    }

    $connection = md5(
        $hostname . $username . $password . $database . $port . $charset . (int)$exit_on_error . (int)$echo_on_error . $logger_class_name . $logger_level . (int)$session_to_db
    );

    if (!isset($instance[$connection])) {
      $instance[$connection] = new self(
          $hostname,
          $username,
          $password,
          $database,
          $port,
          $charset,
          $exit_on_error,
          $echo_on_error,
          $logger_class_name,
          $logger_level,
          $session_to_db
      );

      if (null === $firstInstance) {
        $firstInstance = $instance[$connection];
      }
    }

    return $instance[$connection];
  }

  /**
   * Execute a sql-query.
   *
   * @param string        $sql            sql-query
   *
   * @param array|boolean $params         "array" of sql-query-parameters
   *                                      "false" if you don't need any parameter (default)
   *
   * @return bool|int|Result              "Result" by "<b>SELECT</b>"-queries<br />
   *                                      "int" (insert_id) by "<b>INSERT / REPLACE</b>"-queries<br />
   *                                      "int" (affected_rows) by "<b>UPDATE / DELETE</b>"-queries<br />
   *                                      "true" by e.g. "DROP"-queries<br />
   *                                      "false" on error
   *
   * @throws \Exception
   */
  public function query($sql = '', $params = false)
  {
    if (!$this->isReady()) {
      return false;
    }

    if (!$sql || $sql === '') {
      $this->_debug->displayError('Can\'t execute an empty Query', false);

      return false;
    }

    if (
        $params !== false
        &&
        is_array($params)
        &&
        count($params) > 0
    ) {
      $sql = $this->_parseQueryParams($sql, $params);
    }

    $query_start_time = microtime(true);
    $query_result = \mysqli_real_query($this->link, $sql);
    $query_duration = microtime(true) - $query_start_time;

    $this->query_count++;

    $mysqli_field_count = \mysqli_field_count($this->link);
    if ($mysqli_field_count) {
      $result = \mysqli_store_result($this->link);
    } else {
      $result = $query_result;
    }

    if ($result instanceof \mysqli_result) {

      // log the select query
      $this->_debug->logQuery($sql, $query_duration, $mysqli_field_count);

      // return query result object
      return new Result($sql, $result);

    }

    if ($query_result === true) {

      // "INSERT" || "REPLACE"
      if (preg_match('/^\s*"?(INSERT|REPLACE)\s+/i', $sql)) {
        $insert_id = (int)$this->insert_id();
        $this->_debug->logQuery($sql, $query_duration, $insert_id);

        return $insert_id;
      }

      // "UPDATE" || "DELETE"
      if (preg_match('/^\s*"?(UPDATE|DELETE)\s+/i', $sql)) {
        $affected_rows = (int)$this->affected_rows();
        $this->_debug->logQuery($sql, $query_duration, $affected_rows);

        return $affected_rows;
      }

      // log the ? query
      $this->_debug->logQuery($sql, $query_duration, 0);

      return true;
    }

    // log the error query
    $this->_debug->logQuery($sql, $query_duration, 0, true);

    return $this->queryErrorHandling(\mysqli_error($this->link), $sql, $params);
  }

  /**
   * _parseQueryParams
   *
   * @param string $sql
   * @param array  $params
   *
   * @return string
   */
  private function _parseQueryParams($sql, array $params)
  {
    // is there anything to parse?
    if (strpos($sql, '?') === false) {
      return $sql;
    }

    if (count($params) > 0) {
      $parseKey = md5(uniqid((string)mt_rand(), true));
      $sql = str_replace('?', $parseKey, $sql);

      $k = 0;
      while (strpos($sql, $parseKey) !== false) {
        $value = $this->secure($params[$k]);
        $sql = UTF8::str_replace_first($parseKey, $value, $sql);
        $k++;
      }
    }

    return $sql;
  }

  /**
   * Try to secure a variable, so can you use it in sql-queries.
   *
   * <p>
   * <strong>int:</strong> (also strings that contains only an int-value)<br />
   * 1. parse into "int"
   * </p><br />
   *
   * <p>
   * <strong>float:</strong><br />
   * 1. return "float"
   * </p><br />
   *
   * <p>
   * <strong>string:</strong><br />
   * 1. check if the string isn't a default mysql-time-function e.g. 'CURDATE()'<br />
   * 2. trim whitespace<br />
   * 3. trim '<br />
   * 4. escape the string (and remove non utf-8 chars)<br />
   * 5. trim ' again (because we maybe removed some chars)<br />
   * 6. add ' around the new string<br />
   * </p><br />
   *
   * <p>
   * <strong>array:</strong><br />
   * 1. return null
   * </p><br />
   *
   * <p>
   * <strong>object:</strong><br />
   * 1. return false
   * </p><br />
   *
   * <p>
   * <strong>null:</strong><br />
   * 1. return null
   * </p>
   *
   * @param mixed $var
   *
   * @return mixed
   */
  public function secure($var)
  {
    if (
        $var === ''
        ||
        ($this->_convert_null_to_empty_string === true && $var === null)
    ) {
      return "''";
    }

    if (in_array($var, $this->mysqlDefaultTimeFunctions, true)) {
      return $var;
    }

    if (is_string($var)) {
      $var = trim(trim($var), "'");
    }

    $var = $this->escape($var, false, false, null);

    if (is_string($var)) {
      $var = "'" . trim($var, "'") . "'";
    }

    return $var;
  }

  /**
   * Escape: Use "mysqli_real_escape_string" and clean non UTF-8 chars + some extra optional stuff.
   *
   * @param mixed     $var           boolean: convert into "integer"<br />
   *                                 int: int (don't change it)<br />
   *                                 float: float (don't change it)<br />
   *                                 null: null (don't change it)<br />
   *                                 array: run escape() for every key => value<br />
   *                                 string: run UTF8::cleanup() and mysqli_real_escape_string()<br />
   * @param bool      $stripe_non_utf8
   * @param bool      $html_entity_decode
   * @param bool|null $convert_array <strong>false</strong> => Keep the array.<br />
   *                                 <strong>true</strong> => Convert to string var1,var2,var3...<br />
   *                                 <strong>null</strong> => Convert the array into null, every time.
   *
   * @return mixed
   */
  public function escape($var = '', $stripe_non_utf8 = true, $html_entity_decode = false, $convert_array = false)
  {
    if ($var === '') {
      return '';
    }

    if ($var === null) {
      return null;
    }

    // save the current value as int (for later usage)
    if (!is_object($var)) {
      $varInt = (int)$var;
    }

    /** @noinspection TypeUnsafeComparisonInspection */
    if (
        is_int($var)
        ||
        is_bool($var)
        ||
        (
            isset($varInt, $var[0])
            &&
            $var[0] != '0'
            &&
            "$varInt" == $var
        )
    ) {

      // "int" || int || bool

      return (int)$var;
    }

    if (is_float($var)) {

      // float

      return $var;
    }

    if (is_array($var)) {

      // array

      if ($convert_array === null) {
        return null;
      }

      $varCleaned = array();
      foreach ((array)$var as $key => $value) {

        $key = $this->escape($key, $stripe_non_utf8, $html_entity_decode);
        $value = $this->escape($value, $stripe_non_utf8, $html_entity_decode);

        /** @noinspection OffsetOperationsInspection */
        $varCleaned[$key] = $value;
      }

      if ($convert_array === true) {
        $varCleaned = implode(',', $varCleaned);

        return $varCleaned;
      }

      return (array)$varCleaned;
    }

    if (
        is_string($var)
        ||
        (
            is_object($var)
            &&
            method_exists($var, '__toString')
        )
    ) {

      // "string"

      $var = (string)$var;

      if ($stripe_non_utf8 === true) {
        $var = UTF8::cleanup($var);
      }

      if ($html_entity_decode === true) {
        // use no-html-entity for db
        $var = UTF8::html_entity_decode($var);
      }

      $var = get_magic_quotes_gpc() ? stripslashes($var) : $var;

      $var = \mysqli_real_escape_string($this->getLink(), $var);

      return (string)$var;

    }

    if ($var instanceof \DateTime) {

      // "DateTime"-object

      try {
        return $this->escape($var->format('Y-m-d H:i:s'), false);
      } catch (\Exception $e) {
        return null;
      }

    } else {
      return false;
    }
  }

  /**
   * Get the mysqli-link (link identifier returned by mysqli-connect).
   *
   * @return \mysqli
   */
  public function getLink()
  {
    return $this->link;
  }

  /**
   * Returns the auto generated id used in the last query.
   *
   * @return int|string
   */
  public function insert_id()
  {
    return \mysqli_insert_id($this->link);
  }

  /**
   * Gets the number of affected rows in a previous MySQL operation.
   *
   * @return int
   */
  public function affected_rows()
  {
    return \mysqli_affected_rows($this->link);
  }

  /**
   * Error-handling for the sql-query.
   *
   * @param string     $errorMsg
   * @param string     $sql
   * @param array|bool $sqlParams false if there wasn't any parameter
   *
   * @throws \Exception
   *
   * @return bool
   */
  protected function queryErrorHandling($errorMsg, $sql, $sqlParams = false)
  {
    if ($errorMsg === 'DB server has gone away' || $errorMsg === 'MySQL server has gone away') {
      static $RECONNECT_COUNTER;

      // exit if we have more then 3 "DB server has gone away"-errors
      if ($RECONNECT_COUNTER > 3) {
        $this->_debug->mailToAdmin('SQL-Fatal-Error', $errorMsg . ":\n<br />" . $sql, 5);
        throw new \Exception($errorMsg);
      }

      $this->_debug->mailToAdmin('SQL-Error', $errorMsg . ":\n<br />" . $sql);

      // reconnect
      $RECONNECT_COUNTER++;
      $this->reconnect(true);

      // re-run the current query
      return $this->query($sql, $sqlParams);
    }

    $this->_debug->mailToAdmin('SQL-Warning', $errorMsg . ":\n<br />" . $sql);

    // this query returned an error, we must display it (only for dev) !!!
    $this->_debug->displayError($errorMsg . ' | ' . $sql);

    return false;
  }

  /**
   * Reconnect to the MySQL-Server.
   *
   * @param bool $checkViaPing
   *
   * @return bool
   */
  public function reconnect($checkViaPing = false)
  {
    $ping = false;

    if ($checkViaPing === true) {
      $ping = $this->ping();
    }

    if ($ping !== true) {
      $this->connected = false;
      $this->connect();
    }

    return $this->isReady();
  }

  /**
   * Pings a server connection, or tries to reconnect
   * if the connection has gone down.
   *
   * @return boolean
   */
  public function ping()
  {
    if (
        $this->link
        &&
        $this->link instanceof \mysqli
    ) {
      /** @noinspection PhpUsageOfSilenceOperatorInspection */
      return @\mysqli_ping($this->link);
    }

    return false;
  }

  /**
   * Execute select/insert/update/delete sql-queries.
   *
   * @param string $query    sql-query
   * @param bool   $useCache use cache?
   * @param int    $cacheTTL cache-ttl in seconds
   *
   * @return mixed "array" by "<b>SELECT</b>"-queries<br />
   *               "int" (insert_id) by "<b>INSERT</b>"-queries<br />
   *               "int" (affected_rows) by "<b>UPDATE / DELETE</b>"-queries<br />
   *               "true" by e.g. "DROP"-queries<br />
   *               "false" on error
   */
  public static function execSQL($query, $useCache = false, $cacheTTL = 3600)
  {
    // init
    $cacheKey = null;
    $db = self::getInstance();

    if ($useCache === true) {
      $cache = new Cache(null, null, false, $useCache);
      $cacheKey = 'sql-' . md5($query);

      if (
          $cache->getCacheIsReady() === true
          &&
          $cache->existsItem($cacheKey)
      ) {
        return $cache->getItem($cacheKey);
      }

    } else {
      $cache = false;
    }

    $result = $db->query($query);

    if ($result instanceof Result) {

      $return = $result->fetchAllArray();

      // save into the cache
      if (
          $cacheKey !== null
          &&
          $useCache === true
          &&
          $cache instanceof Cache
          &&
          $cache->getCacheIsReady() === true
      ) {
        $cache->setItem($cacheKey, $return, $cacheTTL);
      }

    } else {
      $return = $result;
    }

    return $return;
  }

  /**
   * Get the current charset.
   *
   * @return string
   */
  public function get_charset()
  {
    return $this->charset;
  }

  /**
   * Enables or disables internal report functions
   *
   * @link http://php.net/manual/en/function.mysqli-report.php
   *
   * @param int $flags <p>
   *                   <table>
   *                   Supported flags
   *                   <tr valign="top">
   *                   <td>Name</td>
   *                   <td>Description</td>
   *                   </tr>
   *                   <tr valign="top">
   *                   <td><b>MYSQLI_REPORT_OFF</b></td>
   *                   <td>Turns reporting off</td>
   *                   </tr>
   *                   <tr valign="top">
   *                   <td><b>MYSQLI_REPORT_ERROR</b></td>
   *                   <td>Report errors from mysqli function calls</td>
   *                   </tr>
   *                   <tr valign="top">
   *                   <td><b>MYSQLI_REPORT_STRICT</b></td>
   *                   <td>
   *                   Throw <b>mysqli_sql_exception</b> for errors
   *                   instead of warnings
   *                   </td>
   *                   </tr>
   *                   <tr valign="top">
   *                   <td><b>MYSQLI_REPORT_INDEX</b></td>
   *                   <td>Report if no index or bad index was used in a query</td>
   *                   </tr>
   *                   <tr valign="top">
   *                   <td><b>MYSQLI_REPORT_ALL</b></td>
   *                   <td>Set all options (report all)</td>
   *                   </tr>
   *                   </table>
   *                   </p>
   *
   * @return bool
   */
  public function set_mysqli_report($flags)
  {
    return \mysqli_report($flags);
  }

  /**
   * Set the current charset.
   *
   * @param string $charset
   *
   * @return bool
   */
  public function set_charset($charset)
  {
    $charsetLower = strtolower($charset);
    if ($charsetLower === 'utf8' || $charsetLower === 'utf-8') {
      $charset = 'utf8';
    }
    if ($charset === 'utf8' && Helper::isUtf8mb4Supported($this) === true) {
      $charset = 'utf8mb4';
    }

    $this->charset = (string)$charset;

    $return = mysqli_set_charset($this->link, $charset);
    /** @noinspection PhpUsageOfSilenceOperatorInspection */
    @\mysqli_query($this->link, 'SET CHARACTER SET ' . $charset);
    /** @noinspection PhpUsageOfSilenceOperatorInspection */
    @\mysqli_query($this->link, "SET NAMES '" . $charset . "'");

    return $return;
  }

  /**
   * Set the option to convert null to "''" (empty string).
   *
   * Used in secure() => select(), insert(), update(), delete()
   *
   * @param $bool
   */
  public function set_convert_null_to_empty_string($bool)
  {
    $this->_convert_null_to_empty_string = (bool)$bool;
  }

  /**
   * Get all table-names via "SHOW TABLES".
   *
   * @return array
   */
  public function getAllTables()
  {
    $query = 'SHOW TABLES';
    $result = $this->query($query);

    return $result->fetchAllArray();
  }

  /**
   * Execute a sql-multi-query.
   *
   * @param string $sql
   *
   * @return false|Result[] "Result"-Array by "<b>SELECT</b>"-queries<br />
   *                        "boolean" by only "<b>INSERT</b>"-queries<br />
   *                        "boolean" by only (affected_rows) by "<b>UPDATE / DELETE</b>"-queries<br />
   *                        "boolean" by only by e.g. "DROP"-queries<br />
   *
   * @throws \Exception
   */
  public function multi_query($sql)
  {
    if (!$this->isReady()) {
      return false;
    }

    if (!$sql || $sql === '') {
      $this->_debug->displayError('Can\'t execute an empty Query', false);

      return false;
    }

    $query_start_time = microtime(true);
    $resultTmp = \mysqli_multi_query($this->link, $sql);
    $query_duration = microtime(true) - $query_start_time;

    $this->_debug->logQuery($sql, $query_duration, 0);

    $returnTheResult = false;
    $result = array();
    if ($resultTmp) {
      do {
        $resultTmpInner = \mysqli_store_result($this->link);

        if ($resultTmpInner instanceof \mysqli_result) {
          $returnTheResult = true;
          $result[] = new Result($sql, $resultTmpInner);
        } else {
          $errorMsg = \mysqli_error($this->link);

          // is the query successful
          if ($resultTmpInner === true || !$errorMsg) {
            $result[] = true;
          } else {
            $result[] = $this->queryErrorHandling($errorMsg, $sql);
          }
        }
      } while (\mysqli_more_results($this->link) === true ? \mysqli_next_result($this->link) : false);

    } else {

      $errorMsg = \mysqli_error($this->link);

      if ($this->_debug->checkForDev() === true) {
        echo "Info: maybe you have to increase your 'max_allowed_packet = 30M' in the config: 'my.conf' \n<br />";
        echo 'Error:' . $errorMsg;
      }

      $this->_debug->mailToAdmin('SQL-Error in mysqli_multi_query', $errorMsg . ":\n<br />" . $sql);
    }

    // return the result only if there was a "SELECT"-query
    if ($returnTheResult === true) {
      return $result;
    }

    if (in_array(false, $result, true) === false) {
      return true;
    }

    return false;
  }

  /**
   * alias: "beginTransaction()"
   */
  public function startTransaction()
  {
    $this->beginTransaction();
  }

  /**
   * Begins a transaction, by turning off auto commit.
   *
   * @return boolean this will return true or false indicating success of transaction
   */
  public function beginTransaction()
  {
    $this->clearErrors();

    if ($this->inTransaction() === true) {
      $this->_debug->displayError('Error mysql server already in transaction!', true);

      return false;
    }

    if (\mysqli_connect_errno()) {
      $this->_debug->displayError('Error connecting to mysql server: ' . \mysqli_connect_error(), true);

      return false;
    }

    $this->_in_transaction = true;
    \mysqli_autocommit($this->link, false);

    return true;
  }

  /**
   * Clear the errors in "_debug->_errors".
   *
   * @return bool
   */
  public function clearErrors()
  {
    return $this->_debug->clearErrors();
  }

  /**
   * Check if we are in a transaction.
   *
   * @return boolean
   */
  public function inTransaction()
  {
    return $this->_in_transaction;
  }

  /**
   * Ends a transaction and commits if no errors, then ends autocommit.
   *
   * @return boolean this will return true or false indicating success of transactions
   */
  public function endTransaction()
  {

    if (!$this->errors()) {
      \mysqli_commit($this->link);
      $return = true;
    } else {
      $this->rollback();
      $return = false;
    }

    \mysqli_autocommit($this->link, true);
    $this->_in_transaction = false;

    return $return;
  }

  /**
   * Get all errors from "$this->_errors".
   *
   * @return array|false false === on errors
   */
  public function errors()
  {
    $errors = $this->_debug->getErrors();

    return count($errors) > 0 ? $errors : false;
  }

  /**
   * Rollback in a transaction.
   */
  public function rollback()
  {
    // init
    $return = false;

    if ($this->_in_transaction === true) {
      $return = \mysqli_rollback($this->link);
      \mysqli_autocommit($this->link, true);
      $this->_in_transaction = false;
    }

    return $return;
  }

  /**
   * Execute a "insert"-query.
   *
   * @param string      $table
   * @param array       $data
   * @param string|null $databaseName <p>use <strong>null</strong> if you will use the current database</p>
   *
   * @return false|int false on error
   */
  public function insert($table, array $data = array(), $databaseName = null)
  {
    // init
    $table = trim($table);

    if ($table === '') {
      $this->_debug->displayError('invalid table name');

      return false;
    }

    if (count($data) === 0) {
      $this->_debug->displayError('empty data for INSERT');

      return false;
    }

    $SET = $this->_parseArrayPair($data);

    if ($databaseName) {
      $databaseName = $this->quote_string(trim($databaseName)) . '.';
    }

    $sql = 'INSERT INTO ' . $databaseName . $this->quote_string($table) . " SET $SET;";

    return $this->query($sql);
  }

  /**
   * Parses arrays with value pairs and generates SQL to use in queries.
   *
   * @param array  $arrayPair
   * @param string $glue this is the separator
   *
   * @return string
   *
   * @internal
   */
  public function _parseArrayPair($arrayPair, $glue = ',')
  {
    // init
    $sql = '';

    /** @noinspection IsEmptyFunctionUsageInspection */
    if (empty($arrayPair)) {
      return '';
    }

    $arrayPairCounter = 0;
    foreach ($arrayPair as $_key => $_value) {
      $_connector = '=';
      $_glueHelper = '';
      $_key_upper = strtoupper($_key);

      if (strpos($_key_upper, ' NOT') !== false) {
        $_connector = 'NOT';
      }

      if (strpos($_key_upper, ' IS') !== false) {
        $_connector = 'IS';
      }

      if (strpos($_key_upper, ' IS NOT') !== false) {
        $_connector = 'IS NOT';
      }

      if (strpos($_key_upper, ' IN') !== false) {
        $_connector = 'IN';
      }

      if (strpos($_key_upper, ' NOT IN') !== false) {
        $_connector = 'NOT IN';
      }

      if (strpos($_key_upper, ' BETWEEN') !== false) {
        $_connector = 'BETWEEN';
      }

      if (strpos($_key_upper, ' NOT BETWEEN') !== false) {
        $_connector = 'NOT BETWEEN';
      }

      if (strpos($_key_upper, ' LIKE') !== false) {
        $_connector = 'LIKE';
      }

      if (strpos($_key_upper, ' NOT LIKE') !== false) {
        $_connector = 'NOT LIKE';
      }

      if (strpos($_key_upper, ' >') !== false && strpos($_key_upper, ' =') === false) {
        $_connector = '>';
      }

      if (strpos($_key_upper, ' <') !== false && strpos($_key_upper, ' =') === false) {
        $_connector = '<';
      }

      if (strpos($_key_upper, ' >=') !== false) {
        $_connector = '>=';
      }

      if (strpos($_key_upper, ' <=') !== false) {
        $_connector = '<=';
      }

      if (strpos($_key_upper, ' <>') !== false) {
        $_connector = '<>';
      }

      if (strpos($_key_upper, ' OR') !== false) {
        $_glueHelper = 'OR';
      }

      if (strpos($_key_upper, ' AND') !== false) {
        $_glueHelper = 'AND';
      }

      if (is_array($_value) === true) {
        foreach ($_value as $oldKey => $oldValue) {
          $_value[$oldKey] = $this->secure($oldValue);
        }

        if ($_connector === 'NOT IN' || $_connector === 'IN') {
          $_value = '(' . implode(',', $_value) . ')';
        } elseif ($_connector === 'NOT BETWEEN' || $_connector === 'BETWEEN') {
          $_value = '(' . implode(' AND ', $_value) . ')';
        }

      } else {
        $_value = $this->secure($_value);
      }

      $quoteString = $this->quote_string(
          trim(
              str_ireplace(
                  array(
                      $_connector,
                      $_glueHelper,
                  ),
                  '',
                  $_key
              )
          )
      );

      if (!is_array($_value)) {
        $_value = array($_value);
      }

      if (!$_glueHelper) {
        $_glueHelper = $glue;
      }

      $tmpCounter = 0;
      foreach ($_value as $valueInner) {

        $_glueHelperInner = $_glueHelper;

        if ($arrayPairCounter === 0) {

          if ($tmpCounter === 0 && $_glueHelper === 'OR') {
            $_glueHelperInner = '1 = 1 AND ('; // first "OR"-query glue
          } elseif ($tmpCounter === 0) {
            $_glueHelperInner = ''; // first query glue e.g. for "INSERT"-query -> skip the first ","
          }

        } elseif ($tmpCounter === 0 && $_glueHelper === 'OR') {
          $_glueHelperInner = 'AND ('; // inner-loop "OR"-query glue
        }

        $sql .= ' ' . $_glueHelperInner . ' ' . $quoteString . ' ' . $_connector . ' ' . $valueInner . " \n";
        $tmpCounter++;
      }

      if ($_glueHelper === 'OR') {
        $sql .= ' ) ';
      }

      $arrayPairCounter++;
    }

    return $sql;
  }

  /**
   * Quote && Escape e.g. a table name string.
   *
   * @param string $str
   *
   * @return string
   */
  public function quote_string($str)
  {
    $str = str_replace(
        '`',
        '``',
        trim(
            $this->escape($str, false),
            '`'
        )
    );

    return '`' . $str . '`';
  }

  /**
   * Get errors from "$this->_errors".
   *
   * @return array
   */
  public function getErrors()
  {
    return $this->_debug->getErrors();
  }

  /**
   * Execute a "replace"-query.
   *
   * @param string      $table
   * @param array       $data
   * @param null|string $databaseName <p>use <strong>null</strong> if you will use the current database</p>
   *
   * @return false|int false on error
   */
  public function replace($table, array $data = array(), $databaseName = null)
  {
    // init
    $table = trim($table);

    if ($table === '') {
      $this->_debug->displayError('invalid table name');

      return false;
    }

    if (count($data) === 0) {
      $this->_debug->displayError('empty data for REPLACE');

      return false;
    }

    // extracting column names
    $columns = array_keys($data);
    foreach ($columns as $k => $_key) {
      /** @noinspection AlterInForeachInspection */
      $columns[$k] = $this->quote_string($_key);
    }

    $columns = implode(',', $columns);

    // extracting values
    foreach ($data as $k => $_value) {
      /** @noinspection AlterInForeachInspection */
      $data[$k] = $this->secure($_value);
    }
    $values = implode(',', $data);

    if ($databaseName) {
      $databaseName = $this->quote_string(trim($databaseName)) . '.';
    }

    $sql = 'REPLACE INTO ' . $databaseName . $this->quote_string($table) . " ($columns) VALUES ($values);";

    return $this->query($sql);
  }

  /**
   * Execute a "update"-query.
   *
   * @param string       $table
   * @param array        $data
   * @param array|string $where
   * @param null|string  $databaseName <p>use <strong>null</strong> if you will use the current database</p>
   *
   * @return false|int false on error
   */
  public function update($table, array $data = array(), $where = '1=1', $databaseName = null)
  {
    // init
    $table = trim($table);

    if ($table === '') {
      $this->_debug->displayError('invalid table name');

      return false;
    }

    if (count($data) === 0) {
      $this->_debug->displayError('empty data for UPDATE');

      return false;
    }

    $SET = $this->_parseArrayPair($data);

    if (is_string($where)) {
      $WHERE = $this->escape($where, false);
    } elseif (is_array($where)) {
      $WHERE = $this->_parseArrayPair($where, 'AND');
    } else {
      $WHERE = '';
    }

    if ($databaseName) {
      $databaseName = $this->quote_string(trim($databaseName)) . '.';
    }

    $sql = 'UPDATE ' . $databaseName . $this->quote_string($table) . " SET $SET WHERE ($WHERE);";

    return $this->query($sql);
  }

  /**
   * Execute a "delete"-query.
   *
   * @param string       $table
   * @param string|array $where
   * @param string|null  $databaseName <p>use <strong>null</strong> if you will use the current database</p>
   *
   * @return false|int false on error
   */
  public function delete($table, $where, $databaseName = null)
  {
    // init
    $table = trim($table);

    if ($table === '') {
      $this->_debug->displayError('invalid table name');

      return false;
    }

    if (is_string($where)) {
      $WHERE = $this->escape($where, false);
    } elseif (is_array($where)) {
      $WHERE = $this->_parseArrayPair($where, 'AND');
    } else {
      $WHERE = '';
    }

    if ($databaseName) {
      $databaseName = $this->quote_string(trim($databaseName)) . '.';
    }

    $sql = 'DELETE FROM ' . $databaseName . $this->quote_string($table) . " WHERE ($WHERE);";

    return $this->query($sql);
  }

  /**
   * Execute a "select"-query.
   *
   * @param string       $table
   * @param string|array $where
   * @param string|null  $databaseName <p>use <strong>null</strong> if you will use the current database</p>
   *
   * @return false|Result false on error
   */
  public function select($table, $where = '1=1', $databaseName = null)
  {
    // init
    $table = trim($table);

    if ($table === '') {
      $this->_debug->displayError('invalid table name');

      return false;
    }

    if (is_string($where)) {
      $WHERE = $this->escape($where, false);
    } elseif (is_array($where)) {
      $WHERE = $this->_parseArrayPair($where, 'AND');
    } else {
      $WHERE = '';
    }

    if ($databaseName) {
      $databaseName = $this->quote_string(trim($databaseName)) . '.';
    }

    $sql = 'SELECT * FROM ' . $databaseName . $this->quote_string($table) . " WHERE ($WHERE);";

    return $this->query($sql);
  }

  /**
   * Get the last sql-error.
   *
   * @return string false on error
   */
  public function lastError()
  {
    $errors = $this->_debug->getErrors();

    return count($errors) > 0 ? end($errors) : false;
  }

  /**
   * @return Debug
   */
  public function getDebugger()
  {
    return $this->_debug;
  }

  /**
   * __destruct
   *
   */
  public function __destruct()
  {
    // close the connection only if we don't save PHP-SESSION's in DB
    if ($this->session_to_db === false) {
      $this->close();
    }
  }

  /**
   * Closes a previously opened database connection.
   */
  public function close()
  {
    $this->connected = false;

    if ($this->link) {
      \mysqli_close($this->link);
    }
  }

  /**
   * Prevent the instance from being cloned.
   *
   * @return void
   */
  private function __clone()
  {
  }

  /**
   * __wakeup
   *
   * @return void
   */
  public function __wakeup()
  {
    $this->reconnect();
  }

}
