<?php

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Compass.module.php');
$process = (string)file_get_contents($root . '/ProcessCompass.module.php');
$api = (string)file_get_contents($root . '/CompassAPI.php');

$checks = [
	'upgrade avoids MySQL information_schema' => !str_contains($module, 'information_schema'),
	'upgrade uses ProcessWire column introspection' => str_contains($module, 'database->columnExists($table, $column)'),
	'upgrade uses ProcessWire index introspection' => str_contains($module, 'database->indexExists($table, $index)'),
	'release versions are synchronized' => substr_count($module . $process, "'version'  => 124") === 2,
	'minimum ProcessWire version matches schema API availability' => str_contains($module, "'ProcessWire>=3.0.182'"),
	'device statistics use conditional aggregates' => substr_count($api, 'SUM(CASE WHEN device_type =') === 3
		&& !str_contains($api, 'SUM(device_type ='),
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
