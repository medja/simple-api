<?php

namespace api
{
	class method
	{
		private $info, $method = true;

		public function __construct($method)
		{
			if (is_string($method) && ($i = strpos($method, '::')) !== false)
				$this->info = new \ReflectionMethod(substr($method, 0, $i), substr($method, $i + 2));
			else if (is_array($method))
				$this->info = new \ReflectionMethod($method[0], $method[1]);
			else {
				$this->info = new \ReflectionFunction($method);
				$this->method = false;
			}
		}

		public function parameters()
		{
			return $this->info->getParameters();
		}

		public function invoke($args)
		{
			if ($this->method)
				return $this->info->invokeArgs(null, $args);
			return $this->info->invokeArgs($args);
		}
	}
}

namespace
{
	if (!function_exists('http_response_code'))
	{
		function http_response_code($new = null)
		{
			static $code = 200;
			if($new !== null && !headers_sent())
			{
				$code = $new;
				header('X-PHP-Response-Code: '. $code, true, $code);
			}
			return $code;
		}
	}

	class api
	{
		private $regex, $data, $defaults, $callback, $middleware, $where = array(), $keys = array();
		
		private function __construct($regex, $callback, $middleware = array())
		{
			$this->regex = $regex;
			if (preg_match_all('/\{([\w\_]+)[\?]?\}/', $regex, $matches)) {
				foreach ($matches[1] as $match) {
					$this->keys[] = $match;
					$this->where[$match] = '.+';
				}
			}
			$this->callback = new api\method($callback);
			$this->middleware = $middleware;
		}
		
		public function where($name, $regex)
		{
			if (!isset($this->where[$name]))
				throw new Exception('Parameter not defined!');
			$this->where[$name] = $regex;
			return $this;
		}
		
		private function preg_callback($matches)
		{
			return ($matches[1] != '' ? ('(' . $matches[1] . '|' . $matches[1]) : ($matches[1] . '('))
				. '(' . $this->where[$matches[2]]. '))?';
		}

		public function match($url)
		{
			if ($this->regex == '*') $matches = array(array());
			else if (!preg_match_all('/^' .  preg_replace_callback('/(\\\?.)\\\{([^\}]+)\\\}/i',
				array($this, 'preg_callback'), preg_quote($this->regex, '/')) . '$/i',
				$url, $matches, PREG_SET_ORDER)) return false;
			$this->data = array();
			$this->defaults = array();
			foreach ($this->callback->parameters() as $key => $parameter)
			{
				$i = 2 * (array_search($parameter->name, $this->keys) + 1);
				if (empty($matches[0][$i])) {
					if (!$parameter->isDefaultValueAvailable()) return false;
					$this->defaults[] = $parameter->name;
					$this->data[] = $parameter->getDefaultValue();
				} else $this->data[] = $matches[0][$i];
			}
			return true;
		}
		
		private function invoke($function)
		{
			$args = array();
			foreach ($function->parameters() as $parameter)
			{
				$i = array_search($parameter->name, $this->keys);
				if ($i === false) throw new Exception('Parameter not defined!');
				if (in_array($parameters->name, $this->defaults) && $parameter->isDefaultValueAvailable())
					$args[] = $parameter->getDefaultValue();
				else $args[] = $this->data[$i];
			}
			return $function->invoke($args);
		}
		
		public function execute()
		{
			foreach ($this->middleware as $middleware)
			{
				$result = $this->invoke(new api\method($middleware));
				if (self::$response != null || $result === false || $result && $result !== true) {
					if (self::$response == null) self::$response = 400;
					return $result;
				}
			}
			return $this->callback->invoke($this->data);
		}
		
		private static $listeners = array(), $response = null;
		
		public static function __callStatic($name, $args)
		{
			$cargs = count($args);
			if ($cargs < 2) throw new Exception('Invlaid argument format!');
			$listener = new self($args[0], $args[$cargs - 1], array_slice($args, 1, -1));
			self::$listeners[strtolower($name)][] = $listener;
			return $listener;
		}
		
		private static function make($data)
		{
			if ($data === null || is_bool($data)) return array();
			if (is_array($data) || is_object($data)) return $data;
			return array($data);
		}
		
		private static function emit($url, $method)
		{
			if (isset(self::$listeners[$method]))
				foreach(self::$listeners[$method] as $listener)
					if ($listener->match($url))
						return self::make($listener->execute());
			self::$response = '404';
			if ($method == 'any') return self::make(null);
			return self::emit($url, 'any');
		}
		
		private static $paths = array('PATH_INFO', 'ORIG_PATH_INFO', 'REQUEST_URI', 'SCRIPT_NAME'
			, 'SCRIPT_NAME', 'PATH_TRANSLATED', 'PHP_SELF', 'QUERY_STRING');
		
		private static function url()
		{
			foreach (self::$paths as $path) if (!empty($_SERVER[$path])) return $_SERVER[$path];
		}
		
		public static function process($url = null)
		{
			static $called = false;
			if ($called) return;
			$called = true;
			if ($url == null) $url = self::url();
			$output = self::emit(trim($url, '/'), strtolower($_SERVER['REQUEST_METHOD']));
			if (self::$response == null) http_response_code(200);
			else if (is_numeric(self::$response)) http_response_code(self::$response);
			else header(self::$response);
			header('Access-Control-Allow-Origin: *');
			if (empty($_GET['callback']))
			{
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode($output);
			}
			else
			{
				header('Content-Type: application/javascript; charset=utf-8');
				echo $_GET['callback'] . '(' . json_encode($output) . ')';
			}
		}
		
		public static function response($code)
		{
			self::$response = $code;
		}

		public static function body()
		{
			static $body = false;
			if (!$body)
			{
				parse_str(file_get_contents("php://input"), $body);
				if (empty($body))
				{
					$body = $_GET; unset($body['callback']);
				}
			}
			return $body;
		}
	}

	register_shutdown_function(array('api', 'process'));
}

?>
