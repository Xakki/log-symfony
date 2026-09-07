<?php

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('LOGGER_VER')) {
    define('LOGGER_VER', '0.3');
}

if (!defined('LOGGER_TIME')) {
    define('LOGGER_TIME', 'time');
}
if (!defined('LOGGER_MCTIME')) {
    define('LOGGER_MCTIME', 'mctime');// float seconds 1.000001 sec
}
if (!defined('LOGGER_MONITORING')) {
    define('LOGGER_MONITORING', 'monitoring');
}
if (!defined('LOGGER_STATUS')) {
    define('LOGGER_STATUS', 'status');
}
if (!defined('LOGGER_COUNT')) {
    define('LOGGER_COUNT', 'count');
}
if (!defined('LOGGER_SUM')) {
    define('LOGGER_SUM', 'sum');
}
if (!defined('LOGGER_MEMORY')) {
    define('LOGGER_MEMORY', 'memory_usage');
}
if (!defined('LOGGER_MEMORY_PEAK')) {
    define('LOGGER_MEMORY_PEAK', 'memory_peak');
}
if (!defined('LOGGER_TAG')) {
    define('LOGGER_TAG', 'tag');
}
if (!defined('LOGGER_SUCCESS')) {
    define('LOGGER_SUCCESS', 'success');
}

if (!defined('LOGGER_SKIP_TRACE')) {
    define('LOGGER_SKIP_TRACE', 'skipTrace');
}
