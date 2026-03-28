<?php
// Look for wp-load.php up several levels
$wp_load = null;
$dir = __DIR__;
for ($i = 0; $i < 5; $i++) {
    if (file_exists($dir . '/wp-load.php')) {
        $wp_load = $dir . '/wp-load.php';
        break;
    }
    $dir = dirname($dir);
}

if (!$wp_load) {
    die("Could not find wp-load.php\n");
}
require_once $wp_load;

$url = '2026/03/27/ai-text/';
$post_id = url_to_postid(home_url($url));

echo "URL: $url\n";
echo "Resolved Post ID: $post_id\n";

if ($post_id) {
    $post = get_post($post_id);
    echo "Post Type: " . ($post->post_type ?? 'N/A') . "\n";
    echo "Post Status: " . ($post->post_status ?? 'N/A') . "\n";
} else {
    echo "Could not resolve URL to Post ID.\n";
}

$detector = new AiTamer\Detector();
$agent = $detector->classify('GPTBot');
echo "Detector Result for 'GPTBot': " . json_encode($agent) . "\n";

$settings = get_option('aitamer_settings', array());
echo "Active Defense Strategy: " . ($settings['active_defense'] ?? 'block') . "\n";
echo "Enable Micropayments: " . ($settings['enable_micropayments'] ? 'yes' : 'no') . "\n";
