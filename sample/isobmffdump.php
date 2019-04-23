<?php

if (is_readable('vendor/autoload.php')) {
    require 'vendor/autoload.php';
} else {
    require_once 'IO/ISOBMFF.php';
}

$options = getopt("f:hvtdR");

if ((isset($options['f']) === false) || (($options['f'] !== "-") && is_readable($options['f']) === false)) {
    fprintf(STDERR, "Usage: php isobmffdump.php -f <isobmff_file> [-htvd]\n");
    fprintf(STDERR, "ex) php isobmffdump.php -f test.heic -t \n");
    exit(1);
}

$filename = $options['f'];
if ($filename === "-") {
    $filename = "php://stdin";
}
$isobmffdata = file_get_contents($filename);

$opts = array(
    'hexdump'  => isset($options['h']),
    'typeonly' => isset($options['t']),
    'verbose'  => isset($options['v']),
    'debug'    => isset($options['d']),
    'restrict' => isset($options['r'])
);


$isobmff = new IO_ISOBMFF();
try {
    $isobmff->parse($isobmffdata, $opts);
} catch (Exception $e) {
    echo "ERROR: isobmffdump: $filename:".PHP_EOL;
    echo $e->getMessage()." file:".$e->getFile()." line:".$e->getLine().PHP_EOL;
    echo $e->getTraceAsString().PHP_EOL;
    exit (1);
}

$isobmff->dump($opts);
