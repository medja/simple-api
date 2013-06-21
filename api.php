<?php

class api
{
	private $regex, $data, $defaults, $callback, $info, $middleware, $where = array(), $keys;
	
	public function __construct($regex, $callback, $middleware = array())
	{
		$this->regex = $regex;
		if (preg_match_all("/\{([\w\_]+)[\?]?\}/", $regex, $matches))
			foreach ($matches[1] as $match)
				$this->where[$match] = ".+";
		$this->callback = $callback;
		$this->middleware = $middleware;
	}
	
	public function where($name, $regex)
	{
		if (!isset($this->where[$name]))
			throw new Exception("Parameter not defined!");
		$this->where[$name] = $regex;
		return $this;
	}
	
	public function match($url)
	{
		if (!preg_match_all("/^" .  preg_replace_callback("/(\\\?.)\\\{([^\}]+)\\\}/i", function($matches) {
			return ($matches[1] != "?" ? ("(" . $matches[1] . "|" . $matches[1]) : ($matches[1] . "("))
				. "(" . $this->where[$matches[2]]. "))?";
		}, "\/?" . preg_quote($this->regex, "/")) . "\/?$/i", $url, $matches, PREG_SET_ORDER)) return false;
		$this->data = array();
		$this->defaults = array();
		$this->info = new ReflectionFunction($this->callback);
		$this->keys = array_keys($this->where);
		foreach ($this->info->getParameters() as $key => $parameter)
		{
			$i = 2 * (array_search($parameter->name, $this->keys) + 1);
			if (empty($matches[0][$i])) {
				if (!$parameter->isDefaultValueAvailable())
					return false;
				$this->defaults[] = $this->keys[$key];
				$this->data[] = $parameter->getDefaultValue();
			}
			else $this->data[] = $matches[0][$i];
		}
		return true;
	}
	
	private function invoke($function)
	{
		$info = new ReflectionFunction($function);
		$args = array();
		foreach ($info->getParameters() as $parameter)
		{
			$i = array_search($parameter->name, $this->keys);
			if ($i === false) throw new Exception("Parameter not defined!");
			if (in_array($parameters->name, $this->defaults) && $parameter->isDefaultValueAvailable())
				$args[] = $parameter->getDefaultValue();
			else $args[] = $this->data[$i];
		}
		return $info->invokeArgs($args);
	}
	
	public function execute()
	{
		foreach ($this->middleware as $middleware)
		{
			$result = $this->invoke($middleware);
			if (self::$response != 200 || $result && $result !== true) return $result;
		}
		return $this->info->invokeArgs($this->data);
	}
	
	private static $listeners = array(), $response = null, $called = false;
	
	public static function &__callStatic($name, $args)
	{
		$cargs = count($args);
		if ($cargs < 2)
			throw new Exception("Invlaid argument format!");
		$listener = new self($args[0], $args[$cargs - 1], array_slice($args, 1, -1));
		self::$listeners[strtolower($name)][] = $listener;
		return $listener;
	}
	
	private static function make($data)
	{
		if ($data === null || is_bool($data)) return null;
		if (is_array($data) || is_object($data)) return $data;
		return array($data);
	}
	
	private static function emit($url, $method)
	{
		if (isset(self::$listeners[$method]))
			foreach(self::$listeners[$method] as $listener)
				if ($listener->match($url))
					return self::make($listener->execute());
		self::$response = "404";
	}
	
	private static $paths = array("PATH_INFO", "ORIG_PATH_INFO", "REQUEST_URI", "SCRIPT_NAME"
		, "SCRIPT_NAME", "PATH_TRANSLATED", "PHP_SELF", "QUERY_STRING");
	
	private static function url()
	{
		foreach (self::$paths as $path) if (!empty($_SERVER[$path])) return $_SERVER[$path];
	}
	
	public static function process($url = null, $method = null)
	{
		if (self::$called) return;
		self::$called = true;
		if ($url == null) $url = self::url();
		if ($method == null) $method = $_SERVER["REQUEST_METHOD"];
		$method = strtolower($method);
		$output = self::emit($url, $method);
		if (self::$response == null) http_response_code(200);
		else if (is_numeric(self::$response)) http_response_code(self::$response);
		else header(self::$response);
		header("Content-Type: application/json; charset=utf-8");
		if ($output != null) echo json_encode($output);
	}
	
	public static function response($code)
	{
		self::$response = $code;
	}
}

register_shutdown_function(array("api", "process"));

?>