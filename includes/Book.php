<?php

namespace islandora_rest_client\ingesters;

/**
 * Islandora REST Ingester Book (islandora:bookCModel) class.
 */
class Book extends Ingester
{
    /**
     * @param object $log
     *    The Monolog logger.
     * @param object $command
     *    The Commando command used in ingest.php.
     */
    public function __construct($log, $command)
    {
        parent::__construct($log, $command);
    }

    public function packageObject($dir)
    {
        // Get the book object's label from the MODS.xml file. If there
        // is no MODS.xml file in the input directory, move on to the
        // next directory.
        $mods_path = realpath($dir) . DIRECTORY_SEPARATOR . 'MODS.xml';
        if (!$label = get_label_from_mods($mods_path, $this->log)) {
            $this->log->addWarning(realpath($dir) . " appears to be empty, skipping.");
            return;
        }

        $book_pid = $this->ingestObject($dir, $label);

        $cmodel_params = array(
            'uri' => 'info:fedora/fedora-system:def/model#',
            'predicate' => 'hasModel',
            'object' => $this->command['m'],
            'type' => 'uri',
        );
        $this->addRelationship($book_pid, $cmodel_params);

        $parent_params = array(
            'uri' => 'info:fedora/fedora-system:def/relations-external#',
            'predicate' => $this->command['r'],
            'object' => $this->command['p'],
            'type' => 'uri',
        );
        $this->addRelationship($book_pid, $parent_params);

        // Ingest MODS.xml.
        $this->ingestDatastreams($book_pid, $dir);

        if ($book_pid) {
            $message = "Object $book_pid ingested from " . realpath($dir);
            $this->log->addInfo($message);
            print $message . "\n";
        }

        // Get the child (page) directories and ingest each one.
        $page_pids = array();
        $page_ingester = new \islandora_rest_client\ingesters\Single($this->log, $this->command);
        $page_dirs = new \FilesystemIterator(realpath($dir));
        foreach ($page_dirs as $page_dir) {
            $page_dir = $page_dir->getPathname();

            if (!is_dir($page_dir)) {
                continue;
            }

            // Get page/sequence number from directory name.
            // @todo: If there's a MODS file, get the label from it?
            $page_dir_name = pathinfo($page_dir, PATHINFO_FILENAME);
            $page_label = 'Page ' . $page_dir_name;
            $page_pid = $page_ingester->ingestObject($page_dir, $page_label);

            // If $page_pid is FALSE, log error and continue.
            if (!$page_pid) {
                $this->log->addError("Page object at " . realpath($page_dir) . " not ingested");
                continue;
            }

            // Keep track of page PIDS so we can get the first one's TN later.
            array_push($page_pids, $page_pid);

            $cmodel_params = array(
                'uri' => 'info:fedora/fedora-system:def/model#',
                'predicate' => 'hasModel',
                'object' => 'islandora:pageCModel',
                'type' => 'uri',
            );
            $page_ingester->addRelationship($page_pid, $cmodel_params);

            // ingestDatastreams() must come after the object's content
            // model is set in order to derivatives to be generated.
            $page_ingester->ingestDatastreams($page_pid, $page_dir);

            $parent_params = array(
                'uri' => 'info:fedora/fedora-system:def/relations-external#',
                'predicate' => 'isMemberOf',
                'object' => $book_pid,
                'type' => 'uri',
            );
            $page_ingester->addRelationship($page_pid, $parent_params);

            $is_page_of_params = array(
                'uri' => 'http://islandora.ca/ontology/relsext#',
                'predicate' => 'isPageOf',
                'object' => $book_pid,
                'type' => 'uri',
            );
            $page_ingester->addRelationship($page_pid, $is_page_of_params);

            $is_sequence_number_params = array(
                'uri' => 'http://islandora.ca/ontology/relsext#',
                'predicate' => 'isSequenceNumber',
                'object' => $page_dir_name,
                'type' => 'none',
            );
            $page_ingester->addRelationship($page_pid, $is_sequence_number_params);

            $is_page_number_params = array(
                'uri' => 'http://islandora.ca/ontology/relsext#',
                'predicate' => 'isPageNumber',
                'object' => $page_dir_name,
                'type' => 'none',
            );
            $page_ingester->addRelationship($page_pid, $is_page_number_params);

            $is_section_params = array(
                'uri' => 'http://islandora.ca/ontology/relsext#',
                'predicate' => 'isSection',
                'object' => '1',
                'type' => 'none',
            );
            $page_ingester->addRelationship($page_pid, $is_section_params);

            if ($page_pid) {
                $message = "Object $page_pid ingested from " . realpath($page_dir);
                $this->log->addInfo($message);
                print $message . "\n";
            }
        }

        // Give the book object the TN from its first page.
        if ($path_to_tn_file = download_datastream_content($page_pids[0], 'TN', $this->command, $this->log)) {
            $this->ingestDatastreams($book_pid, $dir, 'TN', $path_to_tn_file);
            unlink($path_to_tn_file);
        } else {
            $this->log->addWarning("TN for book object $book_pid not replaced with TN for first page");
        }
    }
}
