<?php

class DB {

    protected $instance = null;
    protected $db_host;
    protected $db_name;
    protected $db_user;
    protected $db_pass;
    protected $db_char;
    protected $db_port;

    public function __construct($db_host, $db_port, $db_name, $db_user, $db_pass, $db_char, $debug = false) {
        $this->db_host = $db_host;
        $this->db_port = $db_port;
        $this->db_name = $db_name;
        $this->db_user = $db_user;
        $this->db_pass = $db_pass;
        $this->db_char = $db_char;
        $this->debug = $debug;
    }

    protected function __clone() {
        
    }

    public function instance() {
        if ($this->instance === null) {
            $opt = array(
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => FALSE,
            );
            $dsn = 'mysql:host=' . $this->db_host . ';port=' . $this->db_port . ';dbname=' . $this->db_name . ';charset=' . $this->db_char;
            if ($this->debug) {
                $this->instance = new LoggedPDO($dsn, $this->db_user, $this->db_pass, $opt);
            } else {
                $this->instance = new PDO($dsn, $this->db_user, $this->db_pass, $opt);
            }
        }
        return $this->instance;
    }

    public static function __callStatic($method, $args) {
        return call_user_func_array(array($this->instance(), $method), $args);
    }

    public function run($sql, $args = array()) {
        $stmt = $this->instance()->prepare($sql);
        $stmt->execute($args);
        return $stmt;
    }

    public function log($message, $type, $source) {
        if (empty($source)) {
            $source = '?';
        }
        $this->run('INSERT INTO log SET message = :message, type = :type, source = :source, log_time = NOW()', array('type' => $type, 'message' => $message, 'source' => $source));
    }

}

/**
 * Extends PDO and logs all queries that are executed and how long
 * they take, including queries issued via prepared statements
 */
class LoggedPDO extends PDO {

    public static $log = array();

    public function __construct($dsn, $username = null, $password = null, $options = null) {
        parent::__construct($dsn, $username, $password, $options);
    }

    public function query($statement, $mode = PDO::ATTR_DEFAULT_FETCH_MODE, ...$fetch_mode_args) {
        $result = parent::query($statement, $model, $arg3, $ctorargs);
        return $result;
    }

    /**
     * @return LoggedPDOStatement
     */
    public function prepare($statement, $options = NULL) {
        if (!$options) {
            $options = array();
        }
        return new \LoggedPDOStatement(parent::prepare($statement, $options));
    }

}

/**
 * PDOStatement decorator that logs when a PDOStatement is
 * executed, and the time it took to run
 * @see LoggedPDO
 */
class LoggedPDOStatement {

    /**
     * The PDOStatement we decorate
     */
    private $statement;
    protected $_debugValues = null;

    public function __construct(PDOStatement $statement) {
        $this->statement = $statement;
    }

    /**
     * When execute is called record the time it takes and
     * then log the query
     * @return PDO result set
     */
    public function execute(array $params = array()) {
        $start = microtime(true);
        if (empty($params)) {
            $result = $this->statement->execute();
        } else {
            foreach ($params as $key => $value) {
                $this->_debugValues[$key] = $value;
            }
            $result = $this->statement->execute($params);
        }

        error_log($this->_debugQuery());

        $time = microtime(true) - $start;
        $ar = (int) $this->statement->rowCount();
        error_log('Affected rows: ' . $ar . ' Query took: ' . round($time * 1000, 3) . ' ms');
        return $result;
    }

    public function bindValue($parameter, $value, $data_type = false) {
        $this->_debugValues[$parameter] = $value;
        return $this->statement->bindValue($parameter, $value, $data_type);
    }

    public function _debugQuery($replaced = true) {
        $q = $this->statement->queryString;

        if (!$replaced) {
            return $q;
        }

        return preg_replace_callback('/:([0-9a-z_]+)/i', array($this, '_debugReplace'), $q);
    }

    protected function _debugReplace($m) {
        $name = str_replace(':', '', $m[0]);
        $v = $this->_debugValues[$name];

        if ($v === null) {
            return "NULL";
        }
        if (!is_numeric($v)) {
            $v = str_replace("'", "''", $v);
        }

        return "'" . $v . "'";
    }

    /**
     * Other than execute pass all other calls to the PDOStatement object
     * @param string $function_name
     * @param array $parameters arguments
     */
    public function __call($function_name, $parameters) {
        return call_user_func_array(array($this->statement, $function_name), $parameters);
    }

}
