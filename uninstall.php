<?php
/**
 * 卸载清理。
 * @package Woo_Landed_Price
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

delete_option('wlp_settings');
