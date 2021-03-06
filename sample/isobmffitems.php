<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/ISOBMFF.php';
}

$options = getopt("f:i:hvtdR");

if ((isset($options['f']) === false) || (($options['f'] !== "-") && is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php isobmffitems.php -f <isobmff_file> [-htvd]\n");
    fprintf(STDERR, "ex) php isobmffitems.php -f test.heic -i <itemId> \n");
    fprintf(STDERR, "ex) php isobmffitems.php -f test.heic -h \n");
    fprintf(STDERR, "ex) php isobmffitems.php -f test.heic -t \n");
    exit(1);
}

$filename = $options['f'];
if ($filename === "-") {
    $filename = "php://stdin";
}
$isobmffdata = file_get_contents($filename);

$opts = array();

$itemID = isset($options['i'])?intval($options['i']):null;
$opts['hexdump'] = isset($options['h']);
$opts['typeonly'] = isset($options['t']);
$opts['verbose'] = isset($options['v']);
$opts['debug'] = isset($options['d']);
$opts['restrict'] = isset($options['r']);

$isobmff = new IO_ISOBMFF();
try {
    $isobmff->parse($isobmffdata, $opts);
} catch (Exception $e) {
    echo "ERROR: isobmffitems: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

$itemBoxes = $isobmff->getItemBoxesByItemID($itemID);

foreach ($itemBoxes as $box) {
    if ($opts['typeonly']) {
        $itemIDs = $isobmff->getItemIDs($box);
        $type = $box["type"];
        echo $type;
        switch ($type) {
        case "infe":
            echo "  type:".$box["itemType"];
            break;
        case "auxl":
            $ItemID = $itemIDs[0];
            $propBoxes = $isobmff->getPropBoxesByItemID($ItemID);
            foreach ($propBoxes as $propBox) {
                if ($propBox["type"] === "auxC") {
                    echo "  ".$propBox["auxType"];
                }
            }
            break;
        }
        echo "  itemID:".implode(", ", $itemIDs);
        echo PHP_EOL;
    } else {
        $opts['indent'] = 0;
        $isobmff->dumpBox($box, $opts);
    }
}
