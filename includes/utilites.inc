<?php

/**
 * @file
 * Utilities for the Islandora REST Ingester.
 */

use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Generates a checksum for comparison to the one reported by Islandora.
 *
 * @param $path_to_file string
 *   The path to the file.
 * @param $cmd object
 *   The Commando Command object.
 *
 * @return string|bool
 *   The checksum value, false if no checksum type is specified.
 */
function get_local_checksum($path_to_file, $cmd)
{
    switch ($cmd['c']) {
        case 'SHA-1':
            $checksum = sha1_file($path_to_file);
            break;
        // @todo: Add more checksum types.
        default:
            $checksum = false;
    }
    return $checksum;
}

/**
 * Sends a describe object request to the REST endpoint.
 *
 * @param $pid string
 *   The PID of the parent object.
 * @param $cmd object
 *   The Commando Command object.
 * @param $log object
 *   The Monolog logger.
 *
 * @return array
 *   The body of the describe request.
 */
function describe_object($pid, $cmd, $log)
{
    $client = new GuzzleHttp\Client();
    try {
        $response = $client->request('GET', $cmd['e'] . '/object/' .  $pid, [
           'headers' => [
                'Accept' => 'application/json',
                'X-Authorization-User' => $cmd['u'] . ':' . $cmd['t'],
            ]
        ]);
    } catch (Exception $e) {
        if ($e instanceof RequestException or $e instanceof ClientException or $e instanceof ServerException) {
            $log->addError(Psr7\str($e->getRequest()));
            if ($e->hasResponse()) {
                $log->addError(Psr7\str($e->getResponse()));
            }
            return;
        }
    }

    $response_body = $response->getBody();
    $response_body_array = json_decode($response_body, true);
    return $response_body_array;
}

/**
 * Sends a simple GET request to a REST endpoint (e.g. object or datastream).
 *
 * Useful for determining if an object or datastream exists (200)
 * or not (404).
 *
 * @param $url string
 *   The URL to ping.
 * @param $cmd object
 *   The Commando Command object.
 * @param $log object
 *   The Monolog logger.
 *
 * @return string|object
 *   The returned status code or an exception object if one is encountered.
 */
function ping_url($url, $cmd, $log)
{
    $client = new GuzzleHttp\Client(['http_errors' => false]);
    try {
        $response = $client->request('GET', $url, [
           'headers' => [
                'X-Authorization-User' => $cmd['u'] . ':' . $cmd['t'],
            ]
        ]);
        $status_code = $response->getStatusCode();
    } catch (Exception $e) {
        if ($e instanceof RequestException or $e instanceof ClientException or $e instanceof ServerException) {
            $log->addError(Psr7\str($e->getRequest()));
            if ($e->hasResponse()) {
                $log->addError(Psr7\str($e->getResponse()));
                print Psr7\str($e->getResponse()) . "\n";
            }
            return $e;
        }
    }

    return (string) $status_code;
}

/**
 * Downloads a datastream's content and save it to the system's temp directory.
 *
 * @param $pid string
 *   The PID of the object the datastream is part of.
  * @param $dsid string
 *   The DSID of the datastream.
 * @param $cmd object
 *   The Commando Command object.
 * @param $log object
 *   The Monolog logger.
 *
 * @return string|bool
 *   The file path to the downloaded content or false if there was an error.
 */
function download_datastream_content($pid, $dsid, $cmd, $log)
{
    $ds_url = $cmd['e'] . '/object/' . $pid . '/datastream/' . $dsid . '?content=true';
    $client = new GuzzleHttp\Client(['http_errors' => false]);
    try {
        $response = $client->request('GET', $ds_url, [
           'headers' => [
                'X-Authorization-User' => $cmd['u'] . ':' . $cmd['t'],
            ]
        ]);
        $status_code = $response->getStatusCode();
    } catch (Exception $e) {
        if ($e instanceof RequestException or $e instanceof ClientException or $e instanceof ServerException) {
            $log->addError(Psr7\str($e->getRequest()));
            if ($e->hasResponse()) {
                $log->addError(Psr7\str($e->getResponse()));
            }
            return false;
        }
    }

    if ($status_code !== 200) {
        $log->addWarning("Cannot retrieve datastream object $pid datastream $dsid, " .
            "HTTP response is " . $status_code);
        return false;
    }

    // Get content type from header, and get corresponding extension.
    $mimes = new \Mimey\MimeTypes;
    $content_type = $response->getHeader('content-type');
    $extensions = $mimes->getAllExtensions($content_type[0]);
    $uri_safe_pid = preg_replace('/:/', '_', $pid);
    $ds_content_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $uri_safe_pid .
        '_' . $dsid . '.' . $extensions[0];
    if (file_put_contents($ds_content_path, $response->getBody())) {
        return $ds_content_path;
    } else {
        $log->addWarning("Cannot save datastream content for object $pid datastream $dsid at $ds_content_path");
        return false;
    }
}

/**
 * Queries the Solr endpoint.
 *
 * @param $query string
 *   A Solr query string.
 * @param $cmd object
 *   The Commando Command object.
 * @param $log object
 *   The Monolog logger.
 *
 * @return string|bool
 *   The raw JSON response, or false if there was an error.
 */
function query_solr($query, $cmd, $log)
{
    $url = $cmd['e'] . 'solr/' . $query;
    $client = new GuzzleHttp\Client(['http_errors' => false]);
    try {
        $response = $client->request('GET', $url, [
           'headers' => [
                'X-Authorization-User' => $cmd['u'] . ':' . $cmd['t'],
            ]
        ]);
        $status_code = $response->getStatusCode();
    } catch (Exception $e) {
        if ($e instanceof RequestException or $e instanceof ClientException or $e instanceof ServerException) {
            $log->addError(Psr7\str($e->getRequest()));
            if ($e->hasResponse()) {
                $log->addError(Psr7\str($e->getResponse()));
            }
            return false;
        }
    }

    if ($status_code === 200) {
        return $response->getBody();
    } else {
        $log->addWarning("Cannot query Solr, HTTP response is " . $status_code);
        return false;
    }
}

/**
 * Parses a MODS document (file) to get a node value.
 *
 * @param $path string
 *   The absolute path to the MODS XML file.
 * @param $xpath_expression string
 *   The XPath expression to the element whose value you want.
 *   Since we return a string, this expression should select
 *   the first instance if the element is repeatable.
 * @param $log object
 *   The Monolog logger.
 *
 * @return string|bool
 *   The node value, or false if it cannot be parsed.
 */
function get_value_from_mods($path, $xpath_expression, $log)
{
    if (file_exists($path)) {
        $dom = new DOMDocument();
        $dom->load($path);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('mods', 'http://www.loc.gov/mods/v3');
        $nodes = $xpath->query($xpath_expression);
        if ($nodes->length > 0) {
            $value = $nodes->item(0)->nodeValue;
            return $value;
        } else {
            $log->addWarning("Cannot parse value from MODS file at $path using $xpath");
            return false;
        }
    } else {
        $log->addWarning("Cannot get value from MODS file at " .
            "$path since file does not exist.");
        return false;
    }
}

/**
 * Parses object and datastream properties from FOXML.
 *
 * @param $path string
 *   The path to the FOXML file.
 *
 * @return array
 *   An array with two members, 'object' and 'datastreams':
 *     -'object' contains an array of object properties 'PID',
 *      'ownerId', 'label', 'state', 'createdDate', and 'lastModifiedDate'.
 *     -'datastreams' contains an array of arrays, one per datastream.
 *      The datastream ID is the key for each array, which in turn contains
 *      and array of datastream properties 'state', 'control_group', and
 *      'versionable'.
 */
function get_properties_from_foxml($path)
{
    $properties = array();
    $dom = new DOMDocument;
    $dom->load($path);

    $object = $dom->getElementsByTagNameNS("info:fedora/fedora-system:def/foxml#", 'digitalObject');
    $pid = $object->item(0)->getAttribute("PID");
    $properties['object']['PID'] = $pid;

    $object_properties = $dom->getElementsByTagNameNS("info:fedora/fedora-system:def/foxml#", 'property');
    foreach ($object_properties as $object_property) {
        $name = $object_property->getAttribute("NAME");
        preg_match("/#.*$/", $name, $matches);
        $short_name = $matches[0];
        $short_name = ltrim($short_name, '#');
        $value = $object_property->getAttribute("VALUE");
        $properties['object'][$short_name] = $value;

        // state values are stored in FOXML as words, but the REST
        // interface uses capitalized letters.
        if ($properties['object']['state'] == 'Active') {
            $properties['object']['state'] = 'A';
        }
        if ($properties['object']['state'] == 'Inactive') {
            $properties['object']['state'] = 'I';
        }
        if ($properties['object']['state'] == 'Deleted') {
            $properties['object']['state'] = 'D';
        }
    }

    $datastreams = $dom->getElementsByTagNameNS("info:fedora/fedora-system:def/foxml#", 'datastream');
    foreach ($datastreams as $datastream) {
        $id = $datastream->getAttribute("ID");
        $state = $datastream->getAttribute("STATE");
        $properties['datastreams'][$id]['state'] = $state;
        $control_group = $datastream->getAttribute("CONTROL_GROUP");
        $properties['datastreams'][$id]['control_group'] = $control_group;
        $versionable = $datastream->getAttribute("VERSIONABLE");
        $properties['datastreams'][$id]['versionable'] = $versionable;
    }

    return $properties;
}

/**
 * Checks if the given pid is valid.
 *
 * Adapted from the Islandora utilities.inc file.
 *
 * @param string $pid
 *   The object id to check.
 *
 * @return bool
 *   TRUE if valid, FALSE otherwise.
 */
function is_valid_pid($pid)
{
    return strlen(trim($pid)) <= 64 &&
        preg_match('/^([A-Za-z0-9]|-|\.)+:(([A-Za-z0-9])|-|\.|~|_|(%[0-9A-F]{2}))+$/', trim($pid));
}

/**
 * Determines content model based on file extension.
 *
 * Note: not all Islandora content models can be mapped
 * using the OBJ extension, e.g., islandora:sp_web_archive,
 * islandora:binaryObjectCModel.
 *
 * @param string $extension
 *   The file extension to look up.
 *
 * @return string|bool
 *   The corresponding content model, false if none can be determined.
 */
function get_cmodel_from_extension($extension)
{
    $cmodels = array(
      'jpeg' => 'islandora:sp_basic_image',
      'jpg' => 'islandora:sp_basic_image',
      'gif' => 'islandora:sp_basic_image',
      'png' => 'islandora:sp_basic_image',
      'tif' => 'islandora:sp_large_image_cmodel',
      'tiff' => 'islandora:sp_large_image_cmodel',
      'jp2' => 'islandora:sp_large_image_cmodel',
      'pdf' => 'islandora:sp_pdf',
      'mp3' => 'islandora:sp-audioCModel',
      'mp4a' => 'islandora:sp-audioCModel',
      'm4a' => 'islandora:sp-audioCModel',
      'oga' => 'islandora:sp-audioCModel',
      'ogg' => 'islandora:sp-audioCModel',
      'flac' => 'islandora:sp-audioCModel',
      'wav' => 'islandora:sp-audioCModel',
      'mp4' => 'islandora:sp_videoCModel',
      'm4v'  => 'islandora:sp_videoCModel',
      'mkv'  => 'islandora:sp_videoCModel',
      'mpeg' => 'islandora:sp_videoCModel',
      'mpe' => 'islandora:sp_videoCModel',
      'mpg' => 'islandora:sp_videoCModel',
      'qt' => 'islandora:sp_videoCModel',
      'mov' => 'islandora:sp_videoCModel',
      'ogv' => 'islandora:sp_videoCModel',
      'warc' => 'islandora:sp_web_archive',
    );
    if (!in_array($extension, array_keys($cmodels))) {
        return false;
    } else {
        return $cmodels[$extension];
    }
}

/**
 * Reads 'cmodel.txt' in an object directory and return the contents.
 *
 * @param string $dir
 *   The absolute path to the object's input directory.
 *
 * @return string|bool
 *   The corresponding content model, false if none can be determined.
 */
function get_cmodel_from_cmodel_txt($dir)
{
    $path = $dir . DIRECTORY_SEPARATOR . 'cmodel.txt';
    if (file_exists($path)) {
        $cmodel_pid = trim(file_get_contents($path));
        if (is_valid_pid($cmodel_pid)) {
            return $cmodel_pid;
        } else {
            return false;
        }
    } else {
        return false;
    }
}

/**
 * Recursively delete a directory, or delete a file.
 *
 * @param $dir string
 *   Path to the directory or to a file.
 *
 * @return bool
 *   True if successful, false if not.
 */
function rm_tree($path)
{
    if (is_dir($path)) {
        $files = array_diff(scandir($path), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$path/$file")) ? rm_tree("$path/$file") : unlink("$path/$file");
        }
        return rmdir($path);
    }
    if (is_file($path)) {
        return unlink($path);
    }
}

/**
 * Get custom content model -> Ingester class mappings.
 *
 * @param $cmd object
 *   The Commando Command object.
 *
 * @return string|bool
 *   The class name of the Ingester, false if none is found.
 */
function get_ingester($cmd)
{
    $mappings = file('cmodel_classmap.txt');
    foreach ($mappings as &$mapping) {
        $mapping = trim($mapping);
        $cmodel_mapping = explode("\t", $mapping);
        if ($cmd['m'] == $cmodel_mapping[0]) {
            return $cmodel_mapping[1];
        }
    }
    return false;
}

/**
 * Gets a book or newspaper isssue page's label.
 *
 * @param string $page_dir
 *    The absolute path to the page's input directory.
 * @param $log object
 *   The Monolog logger.
 *
 * @return string
 *    The label.
 */
function get_page_label($page_dir, $log)
{
    // Get page/sequence number from directory name.
    $page_dir_name = pathinfo($page_dir, PATHINFO_FILENAME);

    // If there's a MODS file, get the label from it. Othewise, use the page directory name.
    $page_mods_path = $page_dir . DIRECTORY_SEPARATOR . 'MODS.xml';
    if ($label = get_value_from_mods($page_mods_path, '//mods:titleInfo/mods:title', $log)) {
        $page_label = $label;
    } else {
        $page_label = 'Page ' . $page_dir_name;
    }

    return $page_label;
}
