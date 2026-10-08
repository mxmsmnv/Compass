<?php

namespace ProcessWire;

class WireData {}
interface Module {}
interface ConfigurableModule {}

require_once dirname(__DIR__) . '/Compass.module.php';

$method = new \ReflectionMethod(Compass::class, 'cspNonceFromHeaders');
$resolve = static fn(array $headers): string => (string)$method->invoke(null, $headers);

$checks = [
	'script-src nonce' => $resolve(["Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-alpha+/=='"]) === 'alpha+/==',
	'script-src-elem precedence' => $resolve(["Content-Security-Policy: script-src 'nonce-wrong'; script-src-elem 'nonce-right'"]) === 'right',
	'default-src fallback' => $resolve(["Content-Security-Policy: default-src 'nonce-fallback'"]) === 'fallback',
	'report-only does not grant execution' => $resolve(["Content-Security-Policy-Report-Only: script-src 'nonce-report'"]) === '',
	'multiple policies require a common nonce' => $resolve([
		"Content-Security-Policy: script-src 'nonce-shared' 'nonce-first'",
		"Content-Security-Policy: script-src-elem 'nonce-shared' 'nonce-second'",
	]) === 'shared',
	'conflicting policies fail closed' => $resolve([
		"Content-Security-Policy: script-src 'nonce-one'",
		"Content-Security-Policy: script-src 'nonce-two'",
	]) === '',
	'malformed nonce is rejected' => $resolve(["Content-Security-Policy: script-src 'nonce-bad\"value'"]) === '',
];

$failed = [];
foreach($checks as $label => $passed) {
	echo ($passed ? 'PASS' : 'FAIL') . ' ' . $label . PHP_EOL;
	if(!$passed) $failed[] = $label;
}
exit($failed ? 1 : 0);
