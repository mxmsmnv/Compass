<?php

$root = dirname(__DIR__);
$module = (string)file_get_contents($root . '/Compass.module.php');
$process = (string)file_get_contents($root . '/ProcessCompass.module.php');

$checks = [
	'upgrade avoids MySQL information_schema' => !str_contains($module, 'information_schema'),
	'upgrade uses ProcessWire column introspection' => str_contains($module, 'database->columnExists($table, $column)'),
	'upgrade uses ProcessWire index introspection' => str_contains($module, 'database->indexExists($table, $index)'),
	'release versions are synchronized' => substr_count($module . $process, "'version'  => 121") === 2,
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
