<?php
/**
 * ZarinPal Gateway for Easy Digital Downloads
 *
 * @author 				Ehsaan
 * @package 			EZP
 * @subpackage 			Gateways
 */
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'EDD_ZarinPal_Gateway' ) ) :

/**
 * Payline Gateway for Easy Digital Downloads
 *
 * @author 				Ehsaan
 * @package 			EZP
 * @subpackage 			Gateways
 */
class EDD_ZarinPal_Gateway {
	/**
	 * Gateway keyname
	 *
	 * @var 				string
	 */
	public $keyname;

	/**
	 * Initialize gateway and hook
	 *
	 * @return 				void
	 */
	public function __construct() {
		$this->keyname = 'zarinpal';

		add_filter( 'edd_payment_gateways', array( $this, 'add' ) );
		add_action( $this->format( 'edd_{key}_cc_form' ), array( $this, 'cc_form' ) );
		add_action( $this->format( 'edd_gateway_{key}' ), array( $this, 'process' ) );
		add_action( $this->format( 'edd_verify_{key}' ), array( $this, 'verify' ) );
		add_filter( 'edd_settings_gateways', array( $this, 'settings' ) );

		add_action( 'edd_payment_receipt_after', array( $this, 'receipt' ) );

		add_action( 'init', array( $this, 'listen' ) );
	}

	/**
	 * Add gateway to list
	 *
	 * @param 				array $gateways Gateways array
	 * @return 				array
	 */
	public function add( $gateways ) {
		global $edd_options;

		$gateways[ $this->keyname ] = array(
			'checkout_label' 		=>	isset( $edd_options['zarinpal_label'] ) ? $edd_options['zarinpal_label'] : 'پرداخت آنلاین زرین‌پال',
			'admin_label' 			=>	'زرین‌پال'
		);

		return $gateways;
	}

	/**
	 * CC Form
	 * We don't need it anyway.
	 *
	 * @return 				bool
	 */
	public function cc_form() {
		return;
	}

	/**
	 * Process the payment
	 * 
	 * @param 				array $purchase_data
	 * @return 				void
	 */
	public function process( $purchase_data ) {
		global $edd_options;
		@ session_start();
		$payment = $this->insert_payment( $purchase_data );

		if ( $payment ) {
			$server = ( isset( $edd_options[ $this->keyname . '_server' ] ) ? $edd_options[ $this->keyname . '_server' ] : 'ir' );
			$endpoint = sprintf( 'https://%s.zarinpal.com/pg/services/WebGate/wsdl', $server );

			$zaringate = ( isset( $edd_options[ $this->keyname . '_zaringate' ] ) ? $edd_options[ $this->keyname . '_zaringate' ] : false );
			if ( $zaringate )
				$redirect = 'https://www.zarinpal.com/pg/StartPay/%s/ZarinGate';
			else
				$redirect = 'https://www.zarinpal.com/pg/StartPay/%s';

			$merchant = ( isset( $edd_options[ $this->keyname . '_merchant' ] ) ? $edd_options[ $this->keyname . '_merchant' ] : '' );
			$desc = 'پرداخت شماره #' . $payment;
			$callback = add_query_arg( 'verify_' . $this->keyname, '1', get_permalink( $edd_options['success_page'] ) );

			$amount = intval( $purchase_data['price'] ) / 10;
			if ( edd_get_currency() == 'IRT' )
				$amount = $amount * 10; // Return back to original one.

			$client = new nusoap_client( $endpoint, 'wsdl' );
			$client->soap_defencoding = 'UTF-8';
			$result = $client->call( 'PaymentRequest', array( array(
				'MerchantID' 			=>	$merchant,
				'Amount' 				=>	$amount,
				'Description' 			=>	$desc,
				'Email' 				=>	$purchase_data['user_email'],
				'CallbackURL' 			=>	$callback
			) ) );

			if ( $result['Status'] == 100 ) {
				edd_insert_payment_note( $payment, 'کد تراکنش زرین‌پال: ' . $result['Authority'] );
				edd_update_payment_meta( $payment, 'zarinpal_authority', $result['Authority'] );
				$_SESSION['zp_payment'] = $payment;

				wp_redirect( sprintf( $redirect, $result['Authority'] ) );
			} else {
				edd_insert_payment_note( $payment, 'کدخطا: ' . $result['Status'] );
				edd_insert_payment_note( $payment, 'علت خطا: ' . $this->error_reason( $result['Status'] ) );
				edd_update_payment_status( $payment, 'failed' );

				edd_set_error( 'zarinpal_connect_error', 'در اتصال به درگاه مشکلی پیش آمد. علت: ' . $this->error_reason( $result['Status'] ) );
				edd_send_back_to_checkout();
			}
		} else {
			edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
		}
	}

	/**
	 * Verify the payment
	 *
	 * @return 				void
	 */
	public function verify() {
		global $edd_options;

		if ( isset( $_GET['Authority'] ) ) {
			$authority = sanitize_text_field( $_GET['Authority'] );
			@ session_start();
			$payment = edd_get_payment( $_SESSION['zp_payment'] );
			if ( ! $payment ) {
				wp_die( 'رکورد پرداخت موردنظر وجود ندارد!' );
			}

			if ( $_GET['Status'] == 'OK' ) {
				$amount = intval( edd_get_payment_amount( $payment->ID ) ) / 10;
				if ( edd_get_currency() == 'IRT' )
					$amount = $amount * 10; // Return back to original one.

				$merchant = ( isset( $edd_options[ $this->keyname . '_merchant' ] ) ? $edd_options[ $this->keyname . '_merchant' ] : '' );

				$server = ( isset( $edd_options[ $this->keyname . '_server' ] ) ? $edd_options[ $this->keyname . '_server' ] : 'ir' );
				$endpoint = sprintf( 'https://%s.zarinpal.com/pg/services/WebGate/wsdl', $server );

				$client = new nusoap_client( $endpoint, 'wsdl' );
				$client->soap_defencoding = 'UTF-8';
				$result = $client->call( 'PaymentVerification', array( array(
					'MerchantID' 			=>	$merchant,
					'Amount' 				=>	$amount,
					'Authority' 			=>	$authority
				) ) );

				edd_empty_cart();

				if ( version_compare( EDD_VERSION, '2.1', '>=' ) )
					edd_set_payment_transaction_id( $payment->ID, $authority );

				if ( $result['Status'] == 100 ) {
					edd_insert_payment_note( $payment->ID, 'شماره تراکنش بانکی: ' . $result['RefID'] );
					edd_update_payment_meta( $payment->ID, 'zarinpal_refid', $result['RefID'] );
					edd_update_payment_status( $payment->ID, 'publish' );
					edd_send_to_success_page();
				} else {
					edd_update_payment_status( $payment->ID, 'failed' );
					wp_redirect( get_permalink( $edd_options['failure_page'] ) );
				}

			} else {
				edd_update_payment_status( $payment->ID, 'failed' );
				edd_insert_payment_note( $payment->ID, 'تراکنش توسط کاربر کنسل شد.' );

				wp_redirect( get_permalink( $edd_options['failure_page'] ) );
			}
		}
	}

	/**
	 * Receipt field for payment
	 *
	 * @param 				object $payment
	 * @return 				void
	 */
	public function receipt( $payment ) {
		$refid = edd_get_payment_meta( $payment->ID, 'zarinpal_refid' );
		if ( $refid ) {
			echo '<tr class="zarinpal-ref-id-row ezp-field ehsaan-me"><td><strong>شماره تراکنش بانکی:</strong></td><td>' . $refid . '</td></tr>';
		}
	}

	/**
	 * Gateway settings
	 *
	 * @param 				array $settings
	 * @return 				array
	 */
	public function settings( $settings ) {
		return array_merge( $settings, array(
			$this->keyname . '_header' 		=>	array(
				'id' 			=>	$this->keyname . '_header',
				'type' 			=>	'header',
				'name' 			=>	'<strong>درگاه زرین‌پال</strong> توسط <a href="http://ehsaan.me">Ehsaan</a>'
			),
			$this->keyname . '_merchant' 		=>	array(
				'id' 			=>	$this->keyname . '_merchant',
				'name' 			=>	'مرچنت‌کد',
				'type' 			=>	'text',
				'size' 			=>	'regular'
			),
			$this->keyname . '_zaringate' 		=>	array(
				'id' 			=>	$this->keyname . '_zaringate',
				'name' 			=>	'استفاده از زرین‌گیت',
				'type' 			=>	'checkbox',
				'desc' 			=>	'استفاده از درگاه مستقیم زرین‌پال (زرین‌گیت)'
			),
			$this->keyname . '_server' 		=>	array(
				'id' 			=>	$this->keyname . '_server',
				'name' 			=>	'مکان سرور',
				'type' 			=>	'radio',
				'options' 		=>	array( 'ir' => 'ایران', 'de' => 'آلمان' ),
				'std' 			=>	'ir',
				'desc' 			=>	'مکان سرور مقصد زرین‌پال'
			),
			$this->keyname . '_ip' 		=>	array(
				'id' 			=>	$this->keyname . '_ip',
				'name' 			=>	'آی‌پی سرور شما',
				'type' 			=>	'text',
				'readonly' 		=>	true,
				'std' 			=>	$_SERVER['SERVER_ADDR']
			),
			$this->keyname . '_label' 	=>	array(
				'id' 			=>	$this->keyname . '_label',
				'name' 			=>	'نام درگاه در صفحه پرداخت',
				'type' 			=>	'text',
				'size' 			=>	'regular',
				'std' 			=>	'پرداخت آنلاین زرین‌پال'
			)
		) );
	}

	/**
	 * Format a string, replaces {key} with $keyname
	 *
	 * @param 			string $string To format
	 * @return 			string Formatted
	 */
	private function format( $string ) {
		return str_replace( '{key}', $this->keyname, $string );
	}

	/**
	 * Inserts a payment into database
	 *
	 * @param 			array $purchase_data
	 * @return 			int $payment_id
	 */
	private function insert_payment( $purchase_data ) {
		global $edd_options;

		$payment_data = array( 
			'price' => $purchase_data['price'], 
			'date' => $purchase_data['date'], 
			'user_email' => $purchase_data['user_email'],
			'purchase_key' => $purchase_data['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase_data['downloads'],
			'user_info' => $purchase_data['user_info'],
			'cart_details' => $purchase_data['cart_details'],
			'status' => 'pending'
		);

		// record the pending payment
		$payment = edd_insert_payment( $payment_data );

		return $payment;
	}

	/**
	 * Listen to incoming queries
	 *
	 * @return 			void
	 */
	public function listen() {
		if ( isset( $_GET[ 'verify_' . $this->keyname ] ) && $_GET[ 'verify_' . $this->keyname ] ) {
			do_action( 'edd_verify_' . $this->keyname );
		}
	}

	/**
	 * Error reason for ZarinPal
	 *
	 * @param 			int $error_id
	 * @return 			string
	 */
	public function error_reason( $error_id ) {
		$message = 'خطای ناشناخته';

		switch( $error_id ) {
			case '-1':
				$message = 'اطلاعات ارسال‌شده ناقص است.';
				break;
			case '-2':
				$message = 'IP یا کد پذیرنده صحیح نیست.';
				break;
			case '-3':
				$message = 'رقم پرداخت بالای 100 تومان باید باشد.';
				break;
			case '-4':
				$message = 'پذیرنده معتبر نیست.';
				break;
		}

		return $message;
	}
}

endif;

new EDD_ZarinPal_Gateway;
