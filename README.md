gipfl\\Curl
===========

Mostly async CURL implementation to allow for an easier migration to ReactPHP
for components depending on CURL features

Simple Example
--------------

```php
<?php

use gipfl\Curl\CurlAsync;

$loop = \React\EventLoop\Factory::create();
$curl = new CurlAsync($loop);
$curl->get('https://www.nytimes.com');
$loop->run();
```

Limitations
-----------

For now, as a low-level library this implementation wants to be as transparent
as possible. This means that there is no automagic handling of redirects. You
get every Response and must be prepared to deal with all kind of status codes.
