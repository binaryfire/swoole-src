--TEST--
swoole_server: retained server connection iterator is invalidated with its owner
--SKIPIF--
<?php require __DIR__ . '/../include/skipif.inc'; ?>
--FILE--
<?php
require __DIR__ . '/../include/bootstrap.php';

use Swoole\Server;

$server = new Server('127.0.0.1', 0, SWOOLE_BASE);
$connections = $server->connections;
$server->connections = null;

unset($server);
gc_collect_cycles();

count($connections);
?>
--EXPECTF--
Fatal error: Swoole\Connection\Iterator::count(): Invalid instance of Swoole\Connection\Iterator in %s on line %d
--EXPECTF_85--
Fatal error: Swoole\Connection\Iterator::count(): Invalid instance of Swoole\Connection\Iterator in %s on line %d
Stack trace:
#0 %s(%d): Swoole\Connection\Iterator->count()
#1 {main}
