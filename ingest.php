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
use Monolog\Handler\IngestErrorNotifier;

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
    ->describedAs("Object's namespace. If you provide a full PID, it will be used. " .
        "If you do not include this option, the ingester assumes that each object-level " .
        "input directory encodes the object PIDs, and will ingest objects using those PIDs.");
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
    ->describedAs('Fully qualified REST endpoing for the Islandora instance. Default is ' .
        'http://localhost/islandora/rest/v1.');
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
$cmd->option('s')
    ->aka('state')
    ->describedAs('Object state. Default is A (active). Allowed values are I (inactive) and D (deleted).')
    ->default('A');
$cmd->option('g')
    ->aka('plugins')
    ->describedAs('A comma-separated list of plugin names.');
$cmd->option('d')
    ->aka('delete_input')
    ->describedAs('Whether or not to delete the input files for an object after they have been successfully ingested.')
    ->boolean()
    ->default(false);
$cmd->option('z')
    ->aka('max_file_size')
    ->describedAs('Maximum size, in MiB, of datastream files to ingest. If a file is larger than this, ' .
        ' its datastream is not ingested. Default is 500 MiB.')
    ->default('500');

$path_to_log = $cmd['l'];
$log = new Monolog\Logger('Islandora REST Ingester');
$log_stream_handler = new Monolog\Handler\StreamHandler($path_to_log, Logger::INFO);
$log->pushHandler($log_stream_handler);
$error_notifier_handler = new Monolog\Handler\IngestErrorNotifier($log_stream_handler);

function exception_handler($exception)
{
     print "Uncaught exception: " . $exception->getMessage() . "\n";
}

set_exception_handler('exception_handler');

$log->addInfo("ingest.php (endpoint " . $cmd['e'] . ") started at ". date("F j, Y, g:i a"));

switch ($cmd['m']) {
    case 'islandora:sp_basic_image':
    case 'islandora:sp_large_image_cmodel':
    case 'islandora:sp_pdf':
    case 'islandora:sp-audioCModel':
    case 'islandora:sp_videoCModel':
    case 'ir:citationCModel':
    case 'ir:thesisCModel':
    case 'islandora:pageCModel':
    case 'islandora:collectionCModel':
    case 'islandora:entityCModel':
    case 'islandora:eventCModel':
    case 'islandora:placeCModel':
    case 'islandora:sp_disk_image':
    case 'islandora:personCModel':
    case 'islandora:organizationCModel':
    case 'islandora:sp_web_archive':
        $ingester = new \islandora_rest_ingester\ingesters\Single($log, $cmd);
        break;
    case 'islandora:newspaperIssueCModel':
        $ingester = new \islandora_rest_ingester\ingesters\NewspaperIssue($log, $cmd);
        break;
    case 'islandora:bookCModel':
        $ingester = new \islandora_rest_ingester\ingesters\Book($log, $cmd);
        break;
    case 'islandora:compoundCModel':
        $ingester = new \islandora_rest_ingester\ingesters\Compound($log, $cmd);
        break;
}

// Get any custom cmodel -> class mappings.
if (file_exists('cmodel_classmap.txt')) {
    if ($class = get_ingester($cmd)) {
        $class_path = '\\islandora_rest_ingester\\ingesters\\' . $class;
        $ingester = new $class_path($log, $cmd);
    }
}

if (!isset($ingester)) {
    $message = "Error: Cannot find an ingester class associated with content model " . $cmd['m'];
    $log->addError($message);
    print $message . "\n";
    exit;
}

$parent_url = $cmd['e'] . '/object/' . $cmd['p'];
$parent_pid = ping_url($parent_url, $cmd, $log);
if ($parent_pid != '200') {
    $message = "Error: Object specified as --parent (" . $cmd['p'] . ") is not accessible (HTTP response code " .
        $parent_pid . ")";
    $log->addError($message);
    print $message . "\n";
    exit;
}

$object_dirs = new FilesystemIterator($cmd[0]);
foreach ($object_dirs as $object_dir) {
    $are_errors = false;
    try {
        $pid = $ingester->packageObject(rtrim($object_dir->getPathname(), DIRECTORY_SEPARATOR));
        if ($cmd['delete_input']) {
            if (rm_tree($object_dir->getPathname())) {
                $log->addInfo("Input files for object $pid (including all children files) deleted from " .
                    $object_dir->getPathname());
            }
        }
    } catch (Exception $e) {
        if ($log->pushHandler($error_notifier_handler)) {
            $are_errors = true;
        }
        if (isset($pid) && $pid) {
            if ($pid) {
                $log->addError("Error with object $pid from input directory " . $object_dir->getPathname() .
                    "; more information may be available in the log.");
            } else {
                $log->addError("Error with object $pid from input directory " . $object_dir->getPathname() .
                    " (PID not available) ; more information may be available in the log.");
            }
        } else {
            $log->addError("Error with object from input directory " . $object_dir->getPathname() .
                " (PID not available) ; more information may be available in the log.");
        }
        continue;
    }
}

if (!isset($are_errors)) {
    print "No input directories or files found. Please check " . $cmd[0] . ".\n";
    $log->addInfo("ingest.php finished at ". date("F j, Y, g:i a"));
    exit;
}

$log->addInfo("ingest.php finished at ". date("F j, Y, g:i a"));
if ($are_errors) {
    print "There are one or more errors in your log at " . $cmd['l'] .".\n";
}
