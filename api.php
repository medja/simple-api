<?php

class api
{
	private $regex, $data, $callback, $middleware, $where = array(), $keys = null;
	
	public function __construct($regex, $callback, $middleware = array())
	{
		$this->regex = $regex;
		if (preg_match_all('/\{([\w\_]+)[\?]?\}/', $regex, $matches))
			foreach ($matches[1] as $match)
				$this->where[$match] = '.+';
		$this->callback = $callback;
		$this->middleware = $middleware;
	}
	
	public function where($name, $regex)
	{
		if (!isset($this->where[$name]))
			throw new Exception('Parameter not defined!');
		$this->where[$name] = $regex;
		return $this;
	}
	
	public function match($url)
	{
		if (!preg_match_all('/^' .  preg_replace_callback('/(\\\?.)\\\{([^\}]+)\}/i', function($matches) {
			$parts = explode('\\', substr($matches[2], 0, -1));
			$optional = isset($parts[1]);
			return ($optional && $matches[1] != '?' ? ('(' . $matches[1] . '|' . $matches[1]) : ($matches[1] . '('))
				. '(' . $this->where[$parts[0]]. '))' . ($optional ? '?' : '');
		}, '\/?' . preg_quote($this->regex, '/')) . '\/?$/i', $url, $matches, PREG_SET_ORDER)) return false;
		$this->data = array();
		$count = count($matches[0]);
		for ($i = 2; $i < $count; $i += 2)
			$this->data[] = $matches[0][$i];
		return true;
	}
	
	private function invoke($function)
	{
		$info = new ReflectionFunction($function);
		if ($this->keys == null)
			$keys = array_keys($this->where);
		$args = array();
		foreach ($info->getParameters() as $parameter)
		{
			$i = array_search($parameter->name, $keys);
			if ($i === false) throw new Exception('Parameter not defined!');
			if (empty($this->data[$i])) {
				if (!$parameter->isDefaultValueAvailable())
					throw new Exception('Parameter not defined!');
				$args[] = $parameter->getDefaultValue();
			} else $args[] = $this->data[$i];
		}
		return $info->invokeArgs($args);
	}
	
	public function execute()
	{
		foreach ($this->middleware as $middleware)
		{
			if (is_bool($middleware)) {
				if (!$middleware) return array('status' => 401);
			} else if (!$this->invoke($middleware))
				return array('status' => 401);
		}
		return self::make($this->invoke($this->callback));
	}
	
	private static $listeners = array(), $called = false;
	
	public static function &__callStatic($name, $args)
	{
		$cargs = count($args);
		if ($cargs < 2 || !is_string($args[0]) || !is_callable($args[$cargs - 1]))
			throw new Exception('Invlaid argument format!');
		for ($i = 0; $i < $cargs - 2; $i++)
			if (!(is_bool($args[$i] || is_callable($args[$i]))))
				throw new Exception('Invlaid argument format!');
		$listener = new self($args[0], $args[$cargs - 1], array_slice($args, 1, -1));
		self::$listeners[strtolower($name)][] = $listener;
		return $listener;
	}
	
	private static function make($data)
	{
		if (is_int($data) && $data > 399 && $data < 416) return array('status' => $data);
		if (is_object($data)) $data = (array)$data;
		if (!is_array($data)) $data = array($data);
		else if (isset($data['status']) && isset($data['response'])) return $data;
		return array('status' => 200, 'response' => $data);
	}
	
	private static function emit($url, $method)
	{
		if (!isset(self::$listeners[$method]) || !is_array(self::$listeners[$method]))
			return array('status' => 404);
		foreach(self::$listeners[$method] as $listener)
			if ($listener->match($url))
				return $listener->execute();
		return array('status' => 404);
	}
	
	private static $paths = array('PATH_INFO', 'ORIG_PATH_INFO', 'REQUEST_URI', 'SCRIPT_NAME'
		, 'SCRIPT_NAME', 'PATH_TRANSLATED', 'PHP_SELF', 'QUERY_STRING');
	
	private static function url()
	{
		foreach (self::$paths as $path) if (!empty($_SERVER[$path])) return $_SERVER[$path];
	}
	
	public static function process($url = null, $method = null)
	{
		if (self::$called) return;
		self::$called = true;
		if ($url == null) $url = self::url();
		if ($method == null) $method = $_SERVER['REQUEST_METHOD'];
		$method = strtolower($method);
		$output = self::emit($url, $method);
		http_response_code($output['status']);
		header('Content-Type: application/json; charset=utf-8');
		if (isset($output['response']))
			echo json_encode($output['response']);
	}
}

register_shutdown_function(array('api', 'process'));

?>