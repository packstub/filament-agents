<?php

use Packstub\Agents\Tests\HeadlessTestCase;
use Packstub\Agents\Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->extend(HeadlessTestCase::class)->in('Headless');
