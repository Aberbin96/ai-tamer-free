<?php
require_once __DIR__ . '/vendor/autoload.php';

if (! defined('AITAMER_PLUGIN_DIR')) {
    define('AITAMER_PLUGIN_DIR', __DIR__ . '/');
}

if (! defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Mock some things maybe needed
function get_option($o, $d) { return $d; }

require_once __DIR__ . '/includes/class-detector.php';

$detector = new AiTamer\Detector();
$bots = $detector->get_bots();

echo "AITAMER_PLUGIN_DIR: " . AITAMER_PLUGIN_DIR . "\n";
echo "Bots count: " . count($bots) . "\n";
if (count($bots) > 0) {
    echo "First bot: " . $bots[0]['name'] . "\n";
} else {
    echo "Error: No bots loaded.\n";
    $file = AITAMER_PLUGIN_DIR . 'data/bots.json';
    echo "File path: $file\n";
    echo "File exists: " . (file_exists($file) ? 'yes' : 'no') . "\n";
    if (file_exists($file)) {
        $json = file_get_contents($file);
        echo "JSON length: " . strlen($json) . "\n";
        $data = json_decode($json, true);
        if ($data === null) {
            echo "JSON Error: " . json_last_error_msg() . "\n";
        } else {
            echo "Data exists, bots key: " . (isset($data['bots']) ? 'yes' : 'no') . "\n";
        }
    }
}
