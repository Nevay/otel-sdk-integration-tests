<?php declare(strict_types=1);

$autoloadPath = $argv[1] ?? throw new RuntimeException('No autoload path provided');
$serialized = $argv[2] ?? throw new RuntimeException('No closure provided');

require_once $autoloadPath;

\Opis\Closure\unserialize($serialized)();
