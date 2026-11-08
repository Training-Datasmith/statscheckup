<?php

date_default_timezone_set('UTC');

define('_PS_VERSION_', '1.7.1.0');
define('_DB_PREFIX_', 'ps_');
define('_PS_USE_SQL_SLAVE_', false);

require_once dirname(__FILE__) . '/harness.php';
require_once dirname(__FILE__) . '/ScoreReport.php';
require_once dirname(__FILE__) . '/StatscheckupTestCase.php';

statscheckup_install_schema();

require_once dirname(__FILE__) . '/../../statscheckup.php';
require_once dirname(__FILE__) . '/../../upgrade/upgrade-2.0.2.php';
