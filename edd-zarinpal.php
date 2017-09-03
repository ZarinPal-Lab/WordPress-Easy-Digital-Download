<?php
/**
 * Plugin Name: ZarinPal for EDD
 * Author: Ehsaan
 * Description: این افزونه، درگاه پرداخت آنلاین <a href="https://zarinpal.com">زرین‌پال</a> را برای افزونه‌ی EDD فعال می‌کند.
 * Version: 1.0
 * Author URI: http://ehsaan.me
 */

if ( ! class_exists( 'nusoap_client' ) )
    require 'includes/nusoap.php';

// Toman Currency
require 'includes/toman-currency.php';

// Include the main file
require 'gateways/zarinpal.php';