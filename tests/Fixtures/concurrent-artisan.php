#!/usr/bin/env php
<?php

$root = dirname(__DIR__, 2);

putenv('TESTBENCH_WORKING_PATH='.$root);
$_ENV['TESTBENCH_WORKING_PATH'] = $root;
$_SERVER['TESTBENCH_WORKING_PATH'] = $root;

require $root.'/vendor/orchestra/testbench-core/laravel/artisan';
