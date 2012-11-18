<?php  if (!defined('BASEPATH')) exit('No direct script access allowed');
/**
 * @author 		Phil Sturgeon
 * @author 		Victor Michnowicz
 * @author		PyroCMS Dev Team
 * @package 	PyroCMS\Installer\Libraries
 */
class Installer_lib {

	private $ci;
	public $php_version;
	public $mysql_server_version;
	public $mysql_client_version;
	public $gd_version;

	function __construct()
	{
		$this->ci =& get_instance();
	}

	// Functions used in Step 1

	/**
	 * @return bool
	 *
	 * Function to see if the PHP version is acceptable (at least version 5)
	 */
	public function php_acceptable($version = NULL)
	{
		// Set the PHP version
		$this->php_version = phpversion();

		// Is this version of PHP greater than minimum version required?
		return (bool) version_compare(PHP_VERSION, $version, '>=');
	}

	/**
	 * @return 	bool
	 *
	 * Function to check that MySQL and its PHP module is installed properly
	 */
	public function check_db_extensions()
	{		
		$has_pdo = extension_loaded('pdo');

		return array(
			'mysql' => $has_pdo and extension_loaded('pdo_mysql'),
			'sqlite' => $has_pdo and extension_loaded('pdo_sqlite'),
			'pgsql' => $has_pdo and extension_loaded('pdo_pgsql'),
		);
	}

	/**
	 * @return string The GD library version.
	 *
	 * Function to retrieve the GD library version
	 */
	public function gd_acceptable()
	{
		// Homeboy is not rockin GD at all
		if ( ! function_exists('gd_info'))
		{
			return false;
		}

		$gd_info = gd_info();
		$this->gd_version = preg_replace('/[^0-9\.]/','',$gd_info['GD Version']);

		// If the GD version is at least 1.0 
		return ($this->gd_version >= 1);
	}

	/**
	 * @return bool
	 *
	 * Function to check if zlib is installed
	 */
	public function zlib_enabled()
	{
		return extension_loaded('zlib');
	}

	/**
	 * @param 	string $data The data that contains server related information.
	 * @return 	bool
	 *
	 * Function to validate the server settings.
	 */
	public function check_server($data)
	{
		// Check PHP
		if ( ! $this->php_acceptable($data->php_min_version))
		{
			return false;
		}

		if ($data->http_server->supported === false)
		{
			return false;
		}

		// If PHP, MySQL, etc is good but either server, GD, and/or Zlib is unknown, say partial
		if ($data->http_server->supported === 'partial' || $this->gd_acceptable() === false || $this->zlib_enabled() === false)
		{
			return 'partial';
		}

		// Must be fine
		return true;

	}

	/**
	 * @param	string $server_name The name of the HTTP server such as Abyss, Cherokee, Apache, etc
	 * @return	array
	 *
	 * Function to validate whether the specified server is a supported server. The function returns an array with two keys: supported and non_apache.
	 * The supported key will be set to TRUE whenever the server is supported. The non_apache server will be set to TRUE whenever the user is using a server other than Apache.
	 * This enables the system to determine whether mod_rewrite should be used or not.
	 */
	public function verify_http_server($server_name)
	{
		// Set all the required variables
		if ($server_name == 'other')
		{
			return 'partial';
		}

		$supported_servers = $this->ci->config->item('supported_servers');

		return array_key_exists($server_name, $supported_servers);
	}

	/**
	 * @return bool
	 * Make sure the database name is a valid mysql identifier
	 * 
	 */
	 public function validate_db_name($db_name)
	 {
	 	$expr = '/[^A-Za-z0-9_-]+/';
	 	return !(preg_match($expr,$db_name)>0);
	 }

	/**
	 * @return 	mixed
	 *
	 * Make sure we can connect to the database
	 */
	public function create_db_connection()
	{
		$driver   = $this->ci->session->userdata('db.driver');
		$port     = $this->ci->session->userdata('db.port');
		$hostname = $this->ci->session->userdata('db.hostname');
		$location = $this->ci->session->userdata('db.location');
		$username = $this->ci->session->userdata('db.username');
		$password = $this->ci->session->userdata('db.password');
		$database = $this->ci->session->userdata('db.database');

		switch ($driver)
		{
			case 'mysql':
				$dsn = "{$driver}:host={$hostname};port={$port};charset=utf8;";
			break;
			case 'pgsql':
				$dsn = "{$driver}:host={$hostname};port={$port};";
			break;
			case 'sqlite':
				$dsn = "sqlite:{$location}";
			break;
			default:
				show_error('Unknown driver type: '.$driver);
		}

		// Try the connection
		try
		{
			$pdo = new PDO($dsn, $username, $password, array(
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
			));
		}
		catch (PDOException $e)
		{
		    $this->_error_message = $e->getMessage();
		    return false;
		}

		return array(
			'conn' => $pdo,
			'dsn' => $dsn,
		);
	}

	/**
	 * @param 	string $data The data from the form
	 * @return 	array
	 *
	 * Install the PyroCMS database and write the database.php file
	 */
	public function install($user, $db)
	{
		// Retrieve the database server, username and password from the session
		$server 	= "{$db['hostname']}:{$db['port']}";
		$username 	= $db['username'];
		$password 	= $db['password'];
		$database 	= $db['database'];

		// User settings
		$user_salt = substr(md5(uniqid(rand(), true)), 0, 5);
		$user['password'] = sha1($user['password'] . $user_salt);

		// Include migration config to know which migration to start from
		include '../system/cms/config/migration.php';

		// Create a connection
		if ( ! $pdo = $this->create_db_connection())
		{
			return array('status' => false,'message' => 'The installer could not connect to the database, be sure to enter the correct information.');
		}

		$this->ci->load->model('install_m');

		// Basic installation done with this PDO connection
		$this->ci->install_m->set_default_structure($pdo['conn'], array_merge($user, $db));

		// We didn't neccessairily have the DB at connection time
		if ($db['database'])
		{
			$pdo['dsn'] .= "dbname={$db['database']};";
		}

		// Write the database file
		if ( ! $this->write_db_file($pdo['dsn'], $db['database'], $db['username'], $db['password']))
		{
			return array(
				'status'	=> false,
				'message'	=> '',
				'code'		=> 105
			);
		}

		// Write the config file.
		if ( ! $this->write_config_file())
		{
			return array(
				'status'	=> false,
				'message'	=> '',
				'code'		=> 106
			);
		}

		return array(
			'status' => true,
			'dsn' => $pdo['dsn'],
			'username' => $db['username'],
			'password' => $db['password'],
		);
	}
	

	/**
	 * @param 	string $database The name of the database
	 *
	 * Writes the database file based on the provided database settings
	 */
	public function write_db_file($dsn, $database, $username, $password)
	{
		// Open the template file
		$template 	= file_get_contents('./assets/config/database.php');

		// We didn't neccessairily have the DB at connection time
		if ($database)
		{
			$dsn .= "dbname={$database};";
		}

		// Replace the __ variables with the data specified by the user
		$new_file  	= str_replace(array(
			'{dsn}', 
			'{username}', 
			'{password}'
		), array(
			$dsn,
			$username,
			$password,
		), $template);

		// Open the database.php file, show an error message in case this returns false
		$handle 	= @fopen('../system/cms/config/database.php','w+');

		// Validate the handle results
		if ($handle !== FALSE)
		{
			return @fwrite($handle, $new_file);
		}

		return FALSE;
	}

	/**
	 * @return bool
	 *
	 * Writes the config file.n
	 */
	function write_config_file()
	{
		// Open the template
		$template = file_get_contents('./assets/config/config.php');

		$server_name = $this->ci->session->userdata('http_server');
		$supported_servers = $this->ci->config->item('supported_servers');

		// Able to use clean URLs?
		if ($supported_servers[$server_name]['rewrite_support'] !== FALSE)
		{
			$index_page = '';
		}

		else
		{
			$index_page = 'index.php';
		}

		// Replace the {index} with index.php or an empty string
		$new_file = str_replace('{index}', $index_page, $template);

		// Open the database.php file, show an error message in case this returns false
		$handle = @fopen('../system/cms/config/config.php','w+');

		// Validate the handle results
		if ($handle !== FALSE)
		{
			return fwrite($handle, $new_file);
		}

		return FALSE;
	}

	public function curl_enabled()
	{
		return (bool) function_exists('curl_init');
	}

	public function get_error()
	{
		return $this->_error_message;
	}
}

/* End of file installer_lib.php */
