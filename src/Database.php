<?php
namespace DBAL;

use DBAL\Caching\CacheInterface;
use DBAL\Modifiers\Operators;
use DBAL\Modifiers\SafeString;
use Exception;
use PDO;
use PDOStatement;

/**
 * PDO Database connection class
 *
 * @author  Adam Binnersley <abinnersley@gmail.com>
 * @version PDO Database Class
 */
class Database implements DBInterface {
	/* @var null|string */
	protected $charset = null;

	/* @var null|string */
	protected $collation = null;

	/*  @var int */
	protected $defaultCacheTime = 21600; // 6 Hours

	/* @var PDO */
	protected $db;

	/* @var string */
	public $sql;

	/* @var string */
	protected $key;

	/* @var string */
	protected $logLocation;

	/* @var bool */
	public $logErrors = true;

	/* @var bool */
	public $logQueries = false;

	/* @var bool */
	public $displayErrors = false;

	/* @var string */
	protected $database;

	/* @var bool */
	protected $cacheEnabled = false;

	/* @var bool|CacheInterface */
	protected $cacheObj;

	/* @var mixed */
	protected $cacheValue;

	/* @var bool */
	protected $modified = false;

	/* @var bool|PDOStatement */
	protected $query;

	/* @var array */
	protected $values = [];

	/* @var array */
	protected $prepare = [];

	/* @var array */
	protected static $connectors = [
		'cubrid' => 'cubrid:host=%s;port=%d;dbname=%s',
		'dblib'  => 'dblib:host=%s:%d;dbname=%s',
		'mssql'  => 'sqlsrv:Server=%s,%d;Database=%s',
		'mysql'  => 'mysql:host=%s;port=%d;dbname=%s',
		'pgsql'  => 'pgsql:host=%s;port=%d;dbname=%s',
		'sqlite' => 'sqlite::memory:',
	];

	/**
	 * Connect to database using PDO connection
	 *
	 * @param string       $hostname    This should be the host of the database e.g. 'localhost'
	 * @param string       $username    This should be the username for the chosen database
	 * @param string       $password    This should be the password for the chosen database
	 * @param string       $database    This should be the database that you wish to connect to
	 * @param string|false $backupHost  If you have a replication server set up put the hostname or IP address in case the primary server goes down
	 * @param object|false $cache       If you want to cache the queries with Memcache(d)/Redis/APC/Xcache This should be the object else set to false
	 * @param boolean      $persistent  If you want a persistent database connection set to true
	 * @param string       $type        The type of connection that you wish to make can be 'mysql', 'cubrid', 'dblib', 'mssql', 'odbc', 'pgsql, or 'sqlite'
	 * @param int          $port        This should be the port number of the MySQL database connection
	 * @param string|false $logLocation This should be where you wish the logs to be stored leave as false if default location is adequate
	 * @param array        $options     Add any additional PDO connection options here
	 */
	public function __construct( $hostname, $username, $password, $database, $backupHost = false, $cache = false, $persistent = false, $type = 'mysql', $port = 3306, $logLocation = false, $options = [] ) {
		$this->setLogLocation( $logLocation );
		try {
			$this->connectToServer( $username, $password, $database, $hostname, $persistent, $type, $port, $options );
		} catch ( Exception $e ) {
			if ( $backupHost !== false ) {
				$this->connectToServer( $username, $password, $database, $backupHost, $persistent, $type, $port, $options );
			}
			$this->error( $e );
		}
		if ( is_object( $cache ) ) {
			$this->setCaching( $cache );
		}
	}

	/**
	 * Closes the PDO database connection when Database object unset
	 */
	public function __destruct() {
		$this->closeDatabase();
	}

	/**
	 * Return the last charset that was set
	 *
	 * @return string|null
	 */
	public function getCharset() {
		if ( $this->charset == null ) {
			// We didn't specify one so we need to ask the DB
			$result = $this->query( "SELECT CHARSET('')" );
			if ( $result ) {
				$this->charset = $result[0]['CHARSET(\'\')'];
			}
		}

		return $this->charset;
	}

	/**
	 * Return the last collation that was set
	 *
	 * @return string|null
	 */
	public function getCollation() {
		if ( $this->collation == null ) {
			// We didn't specify one so we need to ask the DB
			$result = $this->query( "SELECT COLLATION('')" );
			if ( $result ) {
				$this->collation = $result[0]['COLLATION(\'\')'];
			}
		}

		return $this->collation;
	}

	/**
	 * Return the default cache time
	 *
	 * @return int
	 */
	public function getDefaultCacheTime() {
		return $this->defaultCacheTime;
	}

	/**
	 * Enables the caching and set the caching object to the one provided
	 *
	 * @param CacheInterface|bool $caching This should be class of the type of caching you are using
	 *
	 * @return $this
	 */
	public function setCaching( CacheInterface $caching ) {
		if ( is_object( $caching ) ) {
			$this->cacheObj     = $caching;
			$this->cacheEnabled = true;
		}

		return $this;
	}

	/**
	 * Set the charset and collation for the connection
	 *
	 * @param string      $charset
	 * @param string|null $collation
	 */
	public function setCharset( $charset, $collation = null ) {
		$this->charset = $charset;
		$query         = "SET NAMES ?";
		$params        = [ $this->charset ];

		if ( $collation != null ) {
			$this->collation = $collation;

			$query    .= " COLLATE ?";
			$params[] = $this->collation;
		}

		$this->query( $query, $params );
	}

	/**
	 * Set the default cache time for the connection
	 *
	 * @param int $defaultCacheTime
	 */
	public function setDefaultCacheTime( $defaultCacheTime ) {
		$this->defaultCacheTime = $defaultCacheTime;
	}

	/**
	 * Connect to the database using PDO connection
	 *
	 * @param string  $username   This should be the username for the chosen database
	 * @param string  $password   This should be the password for the chosen database
	 * @param string  $database   This should be the database that you wish to connect to
	 * @param string  $hostname   The hostname for the database
	 * @param boolean $persistent If you want a persistent database connection set to true
	 * @param string  $type       The type of connection that you wish to make can be 'mysql', 'cubrid', 'dblib', 'mssql', 'pgsql, or 'sqlite'
	 * @param int     $port       The port number to connect to the MySQL server
	 * @param array   $options    Add any additional PDO connection options here
	 */
	protected function connectToServer( $username, $password, $database, $hostname, $persistent = false, $type = 'mysql', $port = 3306, $options = [] ) {
		if ( ! $this->db ) {
			$this->database = $database;
			$this->db       = new PDO( sprintf( self::$connectors[ $type ], $hostname, $port, $database ), $username, $password,
				array_merge(
					( $persistent !== false ? [ PDO::ATTR_PERSISTENT => true ] : [] ),
					( $type === 'mysql' ? [ PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, PDO::ATTR_EMULATE_PREPARES => true ] : [] ),
					( is_array( $options ) ? $options : [] )
				)
			);
			$this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		}
	}

	/**
	 * This query function is used for more advanced SQL queries for which non of the other methods fit
	 *
	 * @param string  $sql       This should be the SQL query which you wish to run
	 * @param array   $variables This should be an array of values to execute as the values in a prepared statement
	 * @param boolean $cache     If the query should be cached or loaded from cache set to true else set to false
	 *
	 * @return boolean|array Returns array of results for the query that has just been run if select or returns true and false if executed successfully or not
	 */
	public function query( $sql, $variables = [], $cache = true ) {
		try {
			$this->sql   = $sql;
			$this->query = $this->db->prepare( $this->sql );
			$this->query->execute( $variables );
			if ( strpos( $this->sql, 'SELECT' ) !== false ) {
				return $this->query->fetchAll( PDO::FETCH_ASSOC );
			}
		} catch ( Exception $e ) {
			$this->error( $e );
		}

		return false;
	}

	/**
	 * Returns a single record for a select query for the chosen table
	 *
	 * @param string       $table  This should be the table you wish to select the values from
	 * @param array        $where  Should be the field names and values you wish to use as the where query e.g. array('fieldName' => 'value', 'fieldName2' => 'value2', etc).
	 * @param string|array $fields This should be the records you wis to select from the table. It should be either set as '*' which is the default or set as an array in the following format array('field', 'field2', 'field3', etc).
	 * @param array        $order  This is the order you wish the results to be ordered in should be formatted as follows array('fieldName' => 'ASC') or array("'fieldName', 'fieldName2'" => 'DESC')
	 * @param boolean      $cache  If the query should be cached or loaded from cache set to true else set to false
	 *
	 * @return array Returns a single table record as the standard array when running SQL queries
	 */
	public function select( $table, $where = [], $fields = '*', $order = [], $cache = true ) {
		return $this->selectAll( $table, $where, $fields, $order, 1, $cache );
	}

	/**
	 * Returns a multidimensional array of the results from the selected table given the given parameters
	 *
	 * @param string        $table  This should be the table you wish to select the values from
	 * @param array         $where  Should be the field names and values you wish to use as the where query e.g. array('fieldName' => 'value', 'fieldName2' => 'value2', etc).
	 * @param string|array  $fields This should be the records you wis to select from the table. It should be either set as '*' which is the default or set as an array in the following format array('field', 'field2', 'field3', etc).
	 * @param array         $order  This is the order you wish the results to be ordered in should be formatted as follows array('fieldName' => 'ASC') or array("'fieldName', 'fieldName2'" => 'DESC')
	 * @param integer|array $limit  The number of results you want to return 0 is default and returns all results, else should be formatted either as a standard integer or as an array as the start and end values e.g. array(0 => 150)
	 * @param boolean       $cache  If the query should be cached or loaded from cache set to true else set to false
	 *
	 * @return boolean|array Returns a multidimensional array with the chosen fields from the table
	 */
	public function selectAll( $table, $where = [], $fields = '*', $order = [], $limit = 0, $cache = true ) {
		$this->buildSelectQuery( SafeString::makeSafe( $table ), $where, $fields, $order, $limit );
		$result = $this->executeQuery( $cache );
		if ( ! $result ) {
			if ( $limit === 1 ) {
				$result = $this->query->fetch( PDO::FETCH_ASSOC );
			} // Reduce the memory usage if only one record and increase performance
			else {
				$result = $this->query->fetchAll( PDO::FETCH_ASSOC );
			}
			if ( $cache && $this->cacheEnabled ) {
				$this->setCache( $this->key, $result );
			}
		}

		return $result ?? false;
	}

	/**
	 * Returns a single column value for a given query
	 *
	 * @param string       $table  This should be the table you wish to select the values from
	 * @param array        $where  Should be the field names and values you wish to use as the where query e.g. array('fieldName' => 'value', 'fieldName2' => 'value2', etc).
	 * @param array|string $fields This should be the records you wis to select from the table. It should be either set as '*' which is the default or set as an array in the following format array('field', 'field2', 'field3', etc).
	 * @param int          $colNum This should be the column number you wish to get (starts at 0)
	 * @param array        $order  This is the order you wish the results to be ordered in should be formatted as follows array('fieldName' => 'ASC') or array("'fieldName', 'fieldName2'" => 'DESC') so it can be done in both directions
	 * @param boolean      $cache  If the query should be cached or loaded from cache set to true else set to false
	 *
	 * @return mixed If a result is found will return the value of the column given else will return false
	 */
	public function fetchColumn( $table, $where = [], $fields = '*', $colNum = 0, $order = [], $cache = true ) {
		$this->buildSelectQuery( SafeString::makeSafe( $table ), $where, $fields, $order, 1 );
		$result = $this->executeQuery( $cache );
		if ( ! $result ) {
			$column = $this->query->fetchColumn( intval( $colNum ) );
			if ( $cache && $this->cacheEnabled ) {
				$this->setCache( $this->key, $column );
			}

			return ( $column ? $column : false );
		}

		return false;
	}

	/**
	 * Inserts into database using the prepared PDO statements
	 *
	 * @param string $table   This should be the table you wish to insert the values into
	 * @param array  $records This should be the field names and values in the format of array('fieldName' => 'value', 'fieldName2' => 'value2', etc.)
	 *
	 * @return boolean If data is inserted returns true else returns false
	 */
	public function insert( $table, $records ) {
		unset( $this->prepare );

		$this->sql = sprintf( "INSERT INTO `%s` (%s) VALUES (%s);", SafeString::makeSafe( $table ), $this->fields( $records, true ), implode( ', ', $this->prepare ) );
		$this->executeQuery( false );

		return $this->numRows() ? true : false;
	}

	/**
	 * Updates values in a database using the provide variables
	 *
	 * @param string $table   This should be the table you wish to update the values for
	 * @param array  $records This should be the field names and new values in the format of array('fieldName' => 'newValue', 'fieldName2' => 'newValue2', etc.)
	 * @param array  $where   Should be the field names and values you wish to update in the form of an array e.g. array('fieldName' => 'value', 'fieldName2' => 'value2', etc).
	 * @param int    $limit   The number of results you want to return 0 is default and will update all results that match the query, else should be formatted as a standard integer
	 *
	 * @return boolean Returns true if update is successful else returns false
	 */
	public function update( $table, $records, $where = [], $limit = 0 ) {
		$this->sql = sprintf( "UPDATE `%s` SET %s %s%s;", SafeString::makeSafe( $table ), $this->fields( $records ), $this->where( $where ), $this->limit( $limit ) );
		$this->executeQuery( false );

		return $this->numRows() ? true : false;
	}

	/**
	 * Deletes records from the given table based on the variables given
	 *
	 * @param string $table This should be the table you wish to delete the records from
	 * @param array  $where This should be an array of for the where statement
	 * @param int    $limit The number of results you want to return 0 is default and will delete all results that match the query, else should be formatted as a standard integer
	 *
	 * @return boolean
	 */
	public function delete( $table, $where, $limit = 0 ) {
		$this->sql = sprintf( "DELETE FROM `%s` %s%s;", SafeString::makeSafe( $table ), $this->where( $where ), $this->limit( $limit ) );
		$this->executeQuery( false );

		return $this->numRows() ? true : false;
	}

	/**
	 * Count the number of return results
	 *
	 * @param string  $table The table you wish to count the result of
	 * @param array   $where Should be the field names and values you wish to use as the where query e.g. array('fieldName' => 'value', 'fieldName2' => 'value2', etc).
	 * @param boolean $cache If the query should be cached or loaded from cache set to true else set to false
	 *
	 * @return int Returns the number of results
	 */
	public function count( $table, $where = [], $cache = true ) {
		$this->sql = sprintf( "SELECT count(*) FROM `%s`%s;", SafeString::makeSafe( $table ), $this->where( $where ) );
		$this->key = md5( $this->database . $this->sql . serialize( $this->values ) );

		$result = $this->executeQuery( $cache );
		if ( ! $result ) {
			$result = $this->query->fetchColumn();
			if ( $cache && $this->cacheEnabled ) {
				$this->setCache( $this->key, $result );
			}
		}

		return $result;
	}

	/**
	 * Truncates a given table from the selected database so there are no values in the table
	 *
	 * @param string $table This should be the table you wish to truncate
	 *
	 * @return boolean If the table is emptied returns true else returns false
	 */
	public function truncate( $table ) {
		try {
			$this->sql = sprintf( "TRUNCATE TABLE `%s`", SafeString::makeSafe( $table ) );
			$this->executeQuery( false );
		} catch ( Exception $e ) {
			$this->error( $e );
		}

		return $this->numRows() ? true : false;
	}

	/**
	 * Returns the number of rows for the last query sent
	 *
	 * @return int Returns the number of rows for the last query
	 */
	public function numRows() {
		if ( isset( $this->query ) ) {
			return $this->query->rowCount();
		}

		return 0;
	}

	/**
	 * Returns the number of rows for the last query sent (Looks at the numRows() function just added in case of habit)
	 *
	 * @return int Returns the number of rows for the last query
	 */
	public function rowCount() {
		return $this->numRows();
	}

	/**
	 * Returns the ID of the last record last inserted
	 *
	 * @param string $name This should be the name of the sequence object you wish to retrieve
	 *
	 * @return int|string Returns the last inserted ID of the last insert item if $name is null else returns string with sequenced object
	 */
	public function lastInsertId( $name = null ) {
		return $this->db->lastInsertId( $name );
	}

	/**
	 * Return the last sql statement with parameters.
	 *
	 * @return string
	 */
	public function getLastQueryStatement() {
		return "SQL: " . $this->sql . ":" . serialize( $this->values );
	}

	/**
	 * Checks to see if a connection has been made to the server
	 *
	 * @return boolean
	 */
	public function isConnected() {
		return is_object( $this->db ) ? true : false;
	}

	/**
	 * Returns the server version information
	 */
	public function serverVersion() {
		return $this->db->getAttribute( PDO::ATTR_SERVER_VERSION );
	}

	/**
	 * Sets the location of the log files
	 *
	 * @param string|boolean $location This should be where you wish the logs to be stored
	 *
	 * @return $this
	 */
	public function setLogLocation( $location = false ) {
		if ( $location === false ) {
			$location = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
		}
		$this->logLocation = $location;
		if ( ! file_exists( $location ) ) {
			mkdir( $location, 0777, true );
		}

		return $this;
	}

	/**
	 * Displays the error massage which occurs
	 *
	 * @param Exception $error This should be an instance of Exception
	 */
	protected function error( $error ) {
		if ( $this->logErrors ) {
			$file    = $this->logLocation . 'db-errors.txt';
			$current = file_get_contents( $file );
			$current .= date( 'd/m/Y H:i:s' ) . " ERROR: " . $error->getMessage() . " on " . $this->sql . "\n";
			file_put_contents( $file, $current );
		}
		if ( $this->displayErrors ) {
			die( 'ERROR: ' . $error->getMessage() . ' on ' . $this->sql );
		}
	}

	/**
	 * Writes all queries to a log file
	 */
	public function writeQueryToLog() {
		$file    = $this->logLocation . 'queries.txt';
		$current = file_get_contents( $file );
		$current .= $this->getLastQueryStatement() . "\n";
		file_put_contents( $file, $current );
	}

	/**
	 * Closes the PDO database connection by setting the connection to NULL
	 */
	public function closeDatabase() {
		$this->db = null;
	}

	/**
	 * Build the SQL query but doesn't execute it
	 *
	 * @param string        $table  This should be the table you wish to select the values from
	 * @param array         $where  Should be the field names and values you wish to use as the where query e.g. array('fieldName' => 'value', 'fieldName2' => 'value2', etc).
	 * @param string|array  $fields This should be the records you wis to select from the table. It should be either set as '*' which is the default or set as an array in the following format array('field', 'field2', 'field3', etc).
	 * @param array         $order  This is the order you wish the results to be ordered in should be formatted as follows array('fieldName' => 'ASC') or array("'fieldName', 'fieldName2'" => 'DESC') so it can be done in both directions
	 * @param integer|array $limit  The number of results you want to return 0 is default and returns all results, else should be formatted either as a standard integer or as an array as the start and end values e.g. array(0 => 150)
	 */
	protected function buildSelectQuery( $table, $where = [], $fields = '*', $order = [], $limit = 0 ) {
		if ( is_array( $fields ) ) {
			$selectFields = [];
			foreach ( $fields as $field => $value ) {
				$selectFields[] = sprintf( "`%s`", SafeString::makeSafe( $value ) );
			}
			$fieldList = implode( ', ', $selectFields );
		} else {
			$fieldList = '*';
		}

		$this->sql = sprintf( "SELECT %s FROM `%s`%s%s%s;", $fieldList, SafeString::makeSafe( $table ), $this->where( $where ), $this->orderBy( $order ), $this->limit( $limit ) );
		$this->key = md5( $this->database . $this->sql . serialize( $this->values ) );
	}

	/**
	 * Execute the current query if no cache value is available
	 *
	 * @param boolean $cache If the cache should be checked for the checked for the values of the query set to true else set to false
	 *
	 * @return mixed If a cached value exists will be returned else if cache is not checked and query is executed will not return anything
	 */
	protected function executeQuery( $cache = true ) {
		if ( $this->logQueries ) {
			$this->writeQueryToLog();
		}
		if ( $cache && $this->cacheEnabled && $this->getCache( $this->key ) ) {
			return $this->cacheValue;
		}
		try {
			$this->query = $this->db->prepare( $this->sql );
			$this->bindValues( $this->values );
			$this->query->execute();
			unset( $this->values );
			$this->values = [];
		} catch ( Exception $e ) {
			unset( $this->values );
			$this->values = [];
			$this->error( $e );
		}

		return false; // This forces the code to pull from the DB
	}

	/**
	 * This outputs the SQL where query based on a given array
	 *
	 * @param array $where This should be an array that you wish to create the where query for in the for array('field1' => 'test') or array('field1' => array('>=', 0))
	 *
	 * @return string|false If the where query is an array will return the where string and set the values else returns false if no array sent
	 */
	protected function where( $where ) {
		if ( is_array( $where ) && ! empty( $where ) ) {
			$whereFields = [];

			foreach ( $where as $field => $value ) {
				$whereFields[] = $this->formatValues( $field, $value );
			}

			if ( ! empty( $whereFields ) ) {
				return " WHERE " . implode( ' AND ', $whereFields );
			}
		}

		return false;
	}

	/**
	 * Sets the order sting for the SQL query based on an array or string
	 *
	 * @param array|string $order This should be either set to array('fieldName' => 'ASC/DESC') or RAND()
	 *
	 * @return string|false If the SQL query has an valid order by will return a string else returns false
	 */
	protected function orderBy( $order ) {
		if ( is_array( $order ) && ! empty( array_filter( $order ) ) ) {
			$string = [];

			foreach ( $order as $fieldOrder => $fieldValue ) {
				if ( ! empty( $fieldOrder ) && ! empty( $fieldValue ) ) {
					$string[] = sprintf( "`%s` %s", SafeString::makeSafe( $fieldOrder ), strtoupper( SafeString::makeSafe( $fieldValue ) ) );
				} elseif ( $fieldValue === 'RAND()' ) {
					$string[] = $fieldValue;
				}
			}

			return sprintf( " ORDER BY %s", implode( ", ", $string ) );
		} elseif ( $order == 'RAND()' ) {
			return " ORDER BY RAND()";
		}

		return false;
	}

	/**
	 * Build the field list for the query
	 *
	 * @param array   $records This should be an array listing all of the fields
	 * @param boolean $insert  If this is an insert statement should be set to true to create the correct amount of queries for the prepared statement
	 *
	 * @return string The fields list will be returned as a string to insert into the SQL query
	 */
	protected function fields( $records, $insert = false ) {
		$fields = [];

		foreach ( $records as $field => $value ) {
			if ( $insert === true ) {
				$fields[]        = sprintf( "`%s`", SafeString::makeSafe( $field ) );
				$this->prepare[] = '?';
			} else {
				$fields[] = sprintf( "`%s` = ?", SafeString::makeSafe( $field ) );
			}
			$this->values[] = $value;
		}

		return implode( ', ', $fields );
	}

	/**
	 * Returns the limit SQL for the current query as a string
	 *
	 * @param integer|array $limit This should either be set as an integer or should be set as an array with a start and end value
	 *
	 * @return string|false Will return the LIMIT string for the current query if it is valid else returns false
	 */
	protected function limit( $limit = 0 ) {
		if ( is_array( $limit ) && ! empty( array_filter( $limit ) ) ) {
			foreach ( $limit as $start => $end ) {
				return " LIMIT " . intval( $start ) . ", " . intval( $end );
			}
		} elseif ( (int) $limit > 0 ) {
			return " LIMIT " . intval( $limit );
		}

		return false;
	}

	/**
	 * Set the cache with a key and value
	 *
	 * @param string $key   The unique key to store the value against
	 * @param mixed  $value The value of the MYSQL query
	 */
	public function setCache( $key, $value ) {
		if ( $this->cacheEnabled ) {
			$this->cacheObj->save( $key, $value, $this->defaultCacheTime );
		}
	}

	/**
	 * Get the results for a given key
	 *
	 * @param string $key The unique key to check for stored variables
	 *
	 * @return mixed Returned the cached results from
	 */
	public function getCache( $key ) {
		if ( $this->modified === true || ! $this->cacheEnabled ) {
			return false;
		} else {
			$this->cacheValue = $this->cacheObj->fetch( $key );

			return $this->cacheValue;
		}
	}

	/**
	 * Delete the cache results for a given key
	 *
	 * @param string $key   The unique key to check for stored variables
	 * @param bool   $force Over-ride cacheEnabled key and attempt to remove cache.
	 *
	 * @return boolean Returns true on success or false on failure
	 */
	public function deleteCache( $key, bool $force = false ) {
		if ( ! $this->cacheEnabled ) {
			if ( $force !== true ) {
				return false;
			}
		}

		return $this->cacheObj->delete( $key );
	}

	/**
	 * Clears the cache
	 */
	public function flushDB() {
		$this->cacheObj->deleteAll();
	}

	/**
	 * Format the where queries and set the prepared values
	 *
	 * @param string $field This should be the field name in the database
	 * @param mixed  $value This should be the value which should either be a string or an array if it contains an operator
	 *
	 * @return string This should be the string to add to the SQL query
	 */
	protected function formatValues( $field, $value ) {
		if ( ! is_array( $value ) && Operators::isOperatorValid( $value ) && ! Operators::isOperatorPrepared( $value ) ) {
			return sprintf( "`%s` %s", SafeString::makeSafe( $field ), Operators::getOperatorFormat( $value ) );
		} elseif ( is_array( $value ) ) {
			if ( ! is_array( array_values( $value )[0] ) ) {
				$this->values[] = ( isset( $value[1] ) ? $value[1] : array_values( $value )[0] );
				$operator       = ( isset( $value[0] ) ? $value[0] : key( $value ) );
			} else {
				foreach ( array_values( $value )[0] as $op => $array_value ) {
					$this->values[] = $array_value;
				}
				$operator = key( $value );
			}

			return sprintf( "`%s` %s", SafeString::makeSafe( $field ), Operators::getOperatorFormat( $operator ) );

		}
		$this->values[] = $value;

		return sprintf( "`%s` = ?", SafeString::makeSafe( $field ) );
	}

	/**
	 * Band values to use in the query
	 *
	 * @param array $values This should be the values being used in the query
	 */
	protected function bindValues( $values ) {
		if ( is_array( $values ) ) {
			foreach ( $values as $i => $value ) {
				if ( is_numeric( $value ) && intval( $value ) == $value ) {
					$type  = PDO::PARAM_INT;
					$value = intval( $value );
				} elseif ( is_null( $value ) || $value === 'NULL' ) {
					$type  = PDO::PARAM_NULL;
					$value = null;
				} elseif ( is_bool( $value ) ) {
					$type = PDO::PARAM_BOOL;
				} else {
					$type = PDO::PARAM_STR;
				}
				$this->query->bindValue( intval( $i + 1 ), $value, $type );
			}
		}
	}
}
