<?php
// debug_api_post.php

$url = 'http://localhost/test/api/typst/templates.php';

$data = [
    'title' => 'API Post Test ' . date('His'),
    'description' => 'API Post Description',
    'typst' => 'Hello World API Post',
    'seriesId' => 16,
    'lastPdfPath' => 'C:/laragon/www/test/public/storage/typst-pdfs/api_post_test.pdf'
];

$options = [
    'http' => [
        'header'  => "Content-type: application/json\r\n",
        'method'  => 'POST',
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];

$context  = stream_context_create($options);
$result = file_get_contents($url, false, $context);

echo "Response:\n";
echo $result . "\n";

$json = json_decode($result, true);
if (isset($json['data']['lastPdfPath']) && $json['data']['lastPdfPath'] === $data['lastPdfPath']) {
    echo "SUCCESS: API saved PDF path correctly.\n";
} else {
    echo "FAILURE: API did NOT save PDF path.\n";
}
