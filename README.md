# php-steamid
SteamID class for PHP. The class is documented with phpdoc, just read it to find out how to use it.

Works on both 32 and 64-bit PHP.

[node.js version is also available](https://www.npmjs.com/package/steamid), which can be browserified pretty easily.

# Example

```php
<?php
require_once 'vendor/autoload.php';

use SteamID\SteamID;

$steamid = new SteamID('76561197960435530');

echo "SteamID2: " . $steamid->getSteam2RenderedID() . PHP_EOL;
echo "SteamID3: " . $steamid->getSteam3RenderedID() . PHP_EOL;
echo "SteamID64: " . $steamid->getSteamID64() . PHP_EOL;
```