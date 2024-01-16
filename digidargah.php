<?php

/*
* Plugin Name: افزونه دیجی درگاه برای ایزی دی
* Description: افزونه درگاه پرداخت رمز ارزی <a href="https://digidargah.com"> دیجی درگاه </a> برای ووکامرس.
* Version: 1.1
* developer: Hanif Zekri Astaneh
* Author: DigiDargah.com
* Author URI: https://digidargah.com
* Author Email: info@digidargah.com
* Text Domain: DigiDargah_woo_payment_plugin
* Tested version up to: 6.1
* copyright (C) 2020 DigiDargah
* license http://www.gnu.org/licenses/gpl-3.0.html GPLv3 or later
*/

if (!defined('ABSPATH')) exit;

class EDD_DigiDargah_Gateway {
	
	//
	public $keyname;
	
	//
	public function __construct() {
		
		$this->keyname = 'digidargah';
		
		add_filter('edd_payment_gateways', array($this, 'add'));
		add_action($this->format('edd_{key}_cc_form'), array($this, 'cc_form'));
		add_action($this->format('edd_gateway_{key}'), array($this, 'process'));
		add_action($this->format('edd_verify_{key}'), array($this, 'verify'));
		add_filter('edd_settings_gateways', array($this, 'settings'));
		add_action('init', array($this, 'listen'));
	}
	
	//
	public function add($gateways) {
		global $edd_options;
		$gateways[$this->keyname] = array(
			'checkout_label' =>	isset($edd_options['digidargah_label'])?$edd_options['digidargah_label']:'پرداخت رمزارزی با دیجی درگاه',
			'admin_label' => 'دیجی درگاه'
		);
		return $gateways;
	}
	
	//
	public function cc_form() {
		return;
	}
	
	//
	public function process($purchase) {
		global $edd_options;
		@session_start();
		$payment = $this->insert_payment($purchase);
		
		if ($payment) {
			
			$api_key = (isset($edd_options[$this->keyname . '_api_key'])?$edd_options[$this->keyname . '_api_key']:'');
			$pay_currency = (isset($edd_options[$this->keyname . '_pay_currency'])?$edd_options[$this->keyname . '_pay_currency']:'');
			$desc = 'پرداخت شماره #' . $payment . ' | ' . $purchase['user_info']['first_name'] . ' ' . $purchase['user_info']['last_name'];
			$callback = add_query_arg('verify_' . $this->keyname, '1', get_permalink($edd_options['success_page']));

			$amount = intval($purchase['price']);
			$currency = edd_get_currency();
			
			if (strtolower($currency) == 'rial') {
				$amount /= 10;
				$currency = 'irt';
			}
			
			$params = array(
				'api_key' => $api_key,
				'amount_value' => $amount,
				'amount_currency' => $currency,
				'pay_currency' => $pay_currency,
				'order_id' => $payment,
				'desc' => $desc,
				'respond_type' => 'link',
				'callback' => $callback,
			);
			
			$ch = curl_init('https://digidargah.com/action/ws/request_create');
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

			$response = curl_exec($ch);
			$err = curl_error($ch);
			
			if ($err) {
				edd_insert_payment_note($payment, 'خطا در اتصال به درگاه : ' . $err);
				edd_update_payment_status($payment, 'failed');
				edd_set_error('digidargah_connect_error', 'در اتصال به درگاه مشکلی بوجود آمده است.');
				edd_send_back_to_checkout();
				return false;
			}

			$result = json_decode($response);
			curl_close($ch);

			if ($result->status == 'success') {
				edd_insert_payment_note($payment, 'کد درخواست دیجی درگاه : ' . $result->request_id);
				edd_update_payment_meta($payment, 'digidargah_request_id', $result->request_id);
				$_SESSION['digidargah_payment'] = $payment;
				edd_set_payment_transaction_id($payment->ID, $request_id);
				wp_redirect($result->respond);
			
			} else {
				edd_insert_payment_note($payment, 'کد درخواست دیجی درگاه : ' . $result->request_id);
				edd_insert_payment_note($payment, 'پاسخ درگاه : ' . $result->respond);
				edd_update_payment_status($payment, 'failed');

				edd_set_error('digidargah_connect_error', 'در اتصال به درگاه مشکلی بوجود آمده است. پاسخ درگاه : ' . $result->respond);
				edd_send_back_to_checkout();
			}
			
		} else {
			edd_send_back_to_checkout('?payment-mode=' . $purchase['post_data']['edd-gateway']);
		}
	}
	
	//
	public function verify() {
		
		global $edd_options;
		
		@session_start();
		$payment = edd_get_payment($_SESSION['digidargah_payment']);
		unset($_SESSION['digidargah_payment']);
		
		if (!$payment) wp_die('اختلالی در روند پرداخت بوجود آمده و داده های پرداخت از دست رفتند.');
		if ($payment->status == 'complete') return false;
		
		$request_id = edd_get_payment_meta($payment->ID, 'digidargah_request_id');		
		$api_key = (isset($edd_options[$this->keyname . '_api_key']) ? $edd_options[$this->keyname . '_api_key'] : '');
		
		$params = array(
			'api_key' => $api_key,
			'order_id' => $order_id,
			'request_id' => $request_id,
		);
		
		$ch = curl_init('https://digidargah.com/action/ws/request_status');
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($ch);
		curl_close($ch);
		$result = json_decode($response);

		edd_empty_cart();

		if ($result->status == 'success') {
			edd_update_payment_status($payment->ID, 'publish');
			edd_send_to_success_page();
			
		} else {
			edd_update_payment_status($payment->ID, 'failed');
			wp_redirect(get_permalink($edd_options['failure_page']));
			exit;
		}
	}
	
	//
	public function settings($settings) {
		return array_merge($settings, array(
			$this->keyname . '_label' => array(
				'id' => $this->keyname . '_label',
				'name' => 'عنوان نمایشی',
				'type' => 'text',
				'size' => 'regular',
				'desc' => '<small> به صورت پیش فرض عبارت "پرداخت رمز ارزی با دیجی درگاه" در صفحه پرداخت به مشتری نمایش داده می شود. در صورتی که تمایل دارید عبارت دیگری نمایش داده شود، می توانید از این گزینه استفاده نمایید. </small>',
			),
			$this->keyname . '_api_key' => array(
				'id' => $this->keyname . '_api_key',
				'name' => 'کلید API',
				'type' => 'text',
				'size' => 'regular',
				'desc' => '<small> کلید API دیجی درگاه را در فیلد بالا وارد نمایید تا درگاه پرداخت فعال شود. این کلید را می توانید پس از ثبت وب سایت تان در دیجی درگاه، دریافت نمایید. </small>'
			),
			$this->keyname . '_pay_currency' => array(
				'id' => $this->keyname . '_pay_currency',
				'name' => 'ارزهای قابل انتخاب',
				'type' => 'text',
				'size' => 'regular',
				'desc' => '<small> به صورت پیش فرض کاربر امکان پرداخت از طریق تمامی <a href="https://digidargah.com/sub/process/cryptolist" target="_blank"> ارزهای فعال </a> در درگاه را دارد اما در صورتی که تمایل دارید مشتری را محدود به پرداخت از طریق یک یا چند ارز خاص کنید، می توانید از طریق این متغییر نام ارز و یا ارزها را اعلام نمایید. در صورت تمایل به اعلام بیش از یک ارز، آنها را توسط خط تیره ( dash ) از هم جدا کنید. مثال: bitcoin-dogecoin</small>',
			)
		));
	}
	
	//
	private function format($string) {
		return str_replace('{key}', $this->keyname, $string);
	}
	
	//
	private function insert_payment($purchase) {
		global $edd_options;
		$payment_data = array(
			'price' => $purchase['price'],
			'date' => $purchase['date'],
			'user_email' => $purchase['user_email'],
			'purchase_key' => $purchase['purchase_key'],
			'currency' => $edd_options['currency'],
			'downloads' => $purchase['downloads'],
			'user_info' => $purchase['user_info'],
			'cart_details' => $purchase['cart_details'],
			'status' => 'pending'
		);
		
		$payment = edd_insert_payment($payment_data);
		return $payment;
	}
	
	//
	public function listen() {
		if (isset($_GET['verify_' . $this->keyname]) && $_GET['verify_' . $this->keyname]) {
			do_action('edd_verify_' . $this->keyname);
		}
	}
}

new EDD_DigiDargah_Gateway;
