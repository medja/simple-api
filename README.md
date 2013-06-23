# Simple API

A fast, simple and reliable API microframework for PHP 5.3+

## Setup

1. Make sure all requests point to your main .php file
2. Just **include** or **require** `api.php` in your project

## Example

```PHP
<?php

require 'api.php';

api::get('hello/{name}', function($name = 'world') {
	return array(
		'message' => 'Hello ' . $name;
	);
});

?>
```

This is a basic hello world example.
Getting `/hello/example` will return this:

```JSON
{
	"message": "Hello example"
}
```

And getting `/hello/` or even `/hello` will return this:

```JSON
{
	"message": "Hello world"
}
```

## Usage

### Helper methods

```PHP
api::response($code); // sets the response code or status
api::process($path, $method); // starts execution manually; both parameters are optional
```

### Listeners

All requests are handled by listeners which are called in the order they are defined.
A listener is defined by using the static method corresponding to the request method.

```PHP
api::get(...);	// Responds to GET requests
api::post(...);	// Responds to POST requests
api::put(...);	// Responds to PUT requests
...
```

### Routes

All listener routes must match the full length of the request and may be defined without the leading and trailing slashes.  
So the routes `/home/`, `/home`, `home/` and `home` are the same.

```PHP
api::get('home', ...);
```

This example will only match `/home` and `/home/`.

#### Parameters

Routes may contain named parameters which are passed on to all of the listeners callbacks.
Parameters will by default match any number of characters to match the full length of the request.

```PHP
api::get('user/{name}', ...);
```

This example will match any request of this form `/user/...`.  
Such as:
* `/user/admin`
* `/user/example`
* `/user/lorem/ipsum`

##### Default values

Routes may also contain parameters with default values.
These are defined with the main callback.
When a default value is defined the parameter along with the leading delimiter becomes optional.

```PHP
api::get('user:{id}', ...); // $id has a default value
```

This example will respond to `/user`, `/user:` and `/user:...`. The first two request will use the default value.

##### Regular Expressions

A parameter may be forced to match a regex by using the chainable `where` method on the listener.

```PHP
api::get('user:{id}', ...)->where('id', '[\d]+');
```

This example will only match routes that contain a number for `id`.

### Callbacks

All listeners must constain a main callback and optional middleware callbacks.
The main function or method is always passed as the last argument of the listener.

A callback must be a PHP [callable](http://www.php.net/manual/en/language.types.callable.php) (anonymous function, function name, array).

When a callback is called the route parameters are maped to its parameters by name.
This means all of its parameter names must match a route parameter.

##### Return value

Any non-null and non-boolean value returned by a callback is passed to the user as JSON. Else the response is an empty array.
If the returned value is not an array or object it is wrapped with an array.

#### Main callback

The main callback is used for defining optional route parameters and their default values.
Middleware callbacks may override default values for optional parameters.

```PHP
api::get('user:{id}', ..., function($id = null) {
	if ($id == null) $id = auth::id(); // get current user id
	$user = db::query('select * from user where id = ?', $id)->fetch(); // get user from db
	if ($user) {
		unset($user->password);
		return $user;
	} api::response(404); // set response code or message
}
})->where('id', '[\d]+');
```

This example will return a user by id. The `id` route parameter is optional.
When it isn't passed the current user id is used instead.

```PHP
api::get('user:{id}/{action}', ..., function($id = null, $action) {
	if ($id == null) $id = auth::id();
	if ($action == 'find') {
		$user = db::query('select * from user where id = ?', $id)->fetch();
		if ($user) {
			unset($user->password);
			return $user;
		} api::response(404);
	} else api::response(400);
}
})->where('id', '[\d]+');
```

Here the request must also contain an "action".
The order on which the parameters of the function are defined is not important, as the values are mapped by their names.

NOTE: Parameters with default values may also be on the left side of any non-default parameters.

#### Middleware callbacks

Middleware callbacks are optional and may be passed between the route and the main callback.
They are called in order and can stop a listeners execution by chaning the response code/status via `api::response(...)` or returning **false** or any other value that is neither **null** nor **true**.

When a middleware stops execution the default response becomes 400.

```PHP
api::get('user:{id}/{action}', "auth::check", function($id) {
	if (!auth::admin() && $id != null && $id != auth::id())
		api::response(401);
}, function($id = null, $action) {
	if ($id == null) $id = auth::id();
	...	
})->where('id', '[\d]+');
```

This example will only run if the user is logged in according to `auth::check()` and is either requesting himself/herself or is an admin.
