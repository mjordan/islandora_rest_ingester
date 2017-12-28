<?php

/**
 * @file
 * Script to ingest objects via Islandora's REST interface.
 *
 * Requires https://github.com/discoverygarden/islandora_rest and
 * https://github.com/mjordan/islandora_rest_authen on the target
 * Islandora instance.
 *
 * Usage information is available by running 'php ingest.php --help'.
 *
 * See README.md for additional information.
 */

require_once 'vendor/autoload.php';
use Monolog\Logger;

require_once 'includes/utilites.inc';

$cmd = new Commando\Command();
$cmd->option()
    ->require(true)
    ->describedAs('Ablsolute or relative path to a directory containing Islandora import packages. ' .
        'Trailing slash is optional.')
    ->must(function ($dir_path) {
        if (file_exists($dir_path)) {
            return true;
        } else {
            return false;
        }
    });
$cmd->option('m')
    ->aka('cmodel')
    ->require(true)
    ->describedAs("PID of the object's content model.");
$cmd->option('p')
    ->aka('parent')
    ->require(true)
    ->describedAs("PID of the object's parent collection, book, newspaper issue, compound object, etc.");
$cmd->option('n')
    ->aka('namespace')
    ->describedAs("Object's namespace. If you provide a full PID, it will be used. If you do not include this option, the ingester assumes that each object-level input directory encodes the object PIDs, and will ingest objects using those PIDs.");
$cmd->option('o')
    ->aka('owner')
    ->require(true)
    ->describedAs("Object's owner.");
$cmd->option('r')
    ->aka('relationship')
    ->default('isMemberOfCollection')
    ->describedAs('Predicate describing relationship of object to its parent. Default is isMemberOfCollection.');
$cmd->option('c')
    ->aka('checksum_type')
    ->default('SHA-1')
    ->describedAs('Checksum type to apply to datastreams. Use "none" to not apply checksums. Default is SHA-1.');
$cmd->option('e')
    ->aka('endpoint')
    ->default('http://localhost/islandora/rest/v1')
    ->describedAs('Fully qualified REST endpoing for the Islandora instance. Default is http://localhost/islandora/rest/v1.');
$cmd->option('u')
    ->aka('user')
    ->require(true)
    ->describedAs('REST user name.');
$cmd->option('t')
    ->aka('token')
    ->require(true)
    ->describedAs('REST authentication token.');
$cmd->option('l')
    ->aka('log')
    ->describedAs('Path to the log. Default is ./ingester.log')
    ->default('./ingester.log');

$path_to_log = $cmd['l'];
$log = new Monolog\Logger('Ingest via REST');
$log_stream_handler= new Monolog\Handler\StreamHandler($path_to_log, Logger::INFO);
$log->pushHandler($log_stream_handler);

$log->addInfo("ingest.php (endpoint " . $cmd['e'] . ") started at ". date("F j, Y, g:i a"));

switch ($cmd['m']) {
    case 'single':
        $ingester = new \islandora_rest\ingesters\Single($log, $cmd);
        break;
    case 'newspapers':
        $ingester = new \islandora_rest\ingesters\Newspaper($log, $cmd);
        break;
    case 'books':
        $ingester = new \islandora_rest\ingesters\Book($log, $cmd);
        break;
    case 'compound':
        $ingester = new \islandora_rest\ingesters\Compound($log, $cmd);
        break;
    default:
        exit("Sorry, the content model " . $cmd['m'] . " is not recognized ." . PHP_EOL );
}

$object_dirs = new FilesystemIterator($cmd[0]);
foreach($object_dirs as $object_dir) {
    $ingester->ingestObject($object_dir->getPathname());
}

$log->addInfo("ingest.php finished at ". date("F j, Y, g:i a"));