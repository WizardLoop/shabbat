<?php declare(strict_types=1);

require 'vendor/autoload.php';

$f = `find examples -name '*.php'`."\n".`find docs/docs -name '*.md'`;

$out = [];
foreach (explode("\n", $f) as $line) {
    if (!$line) {
        continue;
    }
    $out []= ['text' => file_get_contents($line)];
}

file_put_contents('madeline_pre.json', json_encode($out, JSON_PRETTY_PRINT));

$out = [];
foreach (glob("train/*php") as $fileName) {
    $file = file_get_contents($fileName);
    preg_match('|^// (.+)|m', $file, $matches);
    $prompt = $matches[1];
    $file = str_replace("// $prompt", '', $file);
    $out []= [
        'id' => $fileName,
        'conversations' => [
            ['from' => 'user', 'value' => $prompt],
            ['from' => 'assistant', 'value' => "Sure! Here's the code: \n```\n$file\n```\n"],
        ],
    ];
}

file_put_contents('madeline_finetune.json', json_encode($out, JSON_PRETTY_PRINT));

file_put_contents('dataset_info.json', json_encode([
    'madeline_finetune' => [
        'file_name' => 'madeline_finetune.json',
        "columns" => [
            "prompt" => "prompt",
            "query" => "input",
            "response" => "output",
            "system" => "system",
            "history" => "history",
        ],
    ],
    'madeline_pre' => [
        'file_name' => 'madeline_pre.json',
        "columns" => [
            "prompt" => "text",
        ],
    ],
], JSON_PRETTY_PRINT));
