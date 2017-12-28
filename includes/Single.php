<?php

namespace islandora_rest\ingesters;

/**
 * Islandora REST Single (e.g. basic image, PDF, etc.) ingester class.
 */
class Single extends Ingester
{
    /**
     * @param object $log
     *    The Monolog logger.
     * @param object $command
     *    The command used to invoke ingest.php.
     */
    public function __construct($log, $command)
    {
        parent::__construct($log, $command);
    }

    public function packageObject($dir) {
        // Get the object's label from the MODS.xml file. If there is
        // no MODS.xml file in the input directory, move on to the
        // next directory.
        $mods_path = realpath($dir) . DIRECTORY_SEPARATOR . 'MODS.xml';
        if (file_exists($mods_path)) {
            $mods_xml = file_get_contents($mods_path);
            $xml = simplexml_load_string($mods_xml);
            $label = (string) current($xml->xpath('//mods:title'));
        }
        else {
            $this->log->addWarning(realpath($dir) . " appears to be empty, skipping.");
            return;
        }

        $pid = $this->ingestObject($dir, $label);

        if ($pid) {
            $message = "Object $pid ingested from " . realpath($dir);
            $this->log->addInfo($message);
            print $message . "\n";
        }
    }
}
