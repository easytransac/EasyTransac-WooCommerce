<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Plugin Name: EasyTransac for WooCommerce
 * Plugin URI: https://www.easytransac.com
 * Description: Payment Gateway for EasyTransac. Create your account on <a href="https://www.easytransac.com">www.easytransac.com</a> to get your application key (API key) by following the steps on <a href="https://fr.wordpress.org/plugins/easytransac/installation/">the installation guide</a> and configure the settings.<strong>EasyTransac needs the Woocomerce plugin.</strong>
 * Version: 2.54
 *
 * Text Domain: easytransac_woocommerce
 * Domain Path: /i18n/languages/
 * WC requires at least: 3.0.0
 * WC tested up to: 4.3.1
 */
if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly.
}

// Requirements errors messages
function easytransac__curl_error() {
	$class = 'notice notice-error';
	$message = 'Easytransac: PHP cURL extension missing';
	printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
}

function easytransac__openssl_error() {
	$message = 'EasyTransac: OpenSSL version not supported "' . OPENSSL_VERSION_TEXT . '" < 1.0.1';
	$class = 'notice notice-error';
	printf('<div class="%1$s"><p>%2$s</p></div>', $class, $message);
}

function use_jquery() {
	wp_enqueue_script('jquery');
}

function init_easytransac_gateway() {

	if(!class_exists('WC_Payment_Gateway')) return;

	class EasyTransacGateway extends WC_Payment_Gateway {

		function __construct() {

			$this->id = 'easytransac';
			$this->icon = '';
			$this->has_fields = false;
			$this->method_title = 'EasyTransac';
			$this->method_description = __('EasyTransac online payment service', 'easytransac_woocommerce');
			$this->description = __('Pay with your credit card.', 'easytransac_woocommerce');
			$this->init_form_fields();
			$this->init_settings();
			$this->settings['notifurl'] = get_site_url() . '/wc-api/easytransac';
			$this->supports = array(
							'products',
							'subscriptions',
							'refunds'
						);

			$this->title = $this->get_option('title');

			// Settings JQuery
			add_action('wp_enqueue_scripts', 'use_jquery');

			// Settings save hook
			add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

			// Register EasyTransac callback handler
			add_action('woocommerce_api_easytransac', array($this, 'check_callback_response'));

			// Requirements.
			$openssl_version_supported = OPENSSL_VERSION_NUMBER >= 0x10001000;
			$curl_activated = function_exists('curl_version');

			if (!$openssl_version_supported) {
				add_action('admin_notices', 'easytransac__openssl_error');
			}

			if (!$curl_activated) {
				add_action('admin_notices', 'easytransac__curl_error');
			}
		}

		// Settings form
		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'easytransac_woocommerce') ,
					'type' => 'checkbox',
					'label' => __('Enable EasyTransac payment', 'easytransac_woocommerce') ,
					'default' => 'yes',
					'desc_tip' => true,
				) ,
				'title' => array(
					'title' => __('Title', 'easytransac_woocommerce') ,
					'type' => 'text',
					'description' => __('This controls the title which the user sees during checkout.', 'easytransac_woocommerce') ,
					'default' => __('EasyTransac', 'easytransac_woocommerce') ,
					'desc_tip' => true,
				) ,
				'api_key' => array(
					'title' => __('API Key', 'easytransac_woocommerce') ,
					'type' => 'text',
					'description' => __('Your EasyTransac application API Key.', 'easytransac_woocommerce') ,
					'default' => '',
					'desc_tip' => true,
					'css' => 'width: 800px;',
				) ,
				'3dsecure' => array(
					'title' => __('Enable/Disable 3D Secure payments', 'easytransac_woocommerce') ,
					'type' => 'checkbox',
					'label' => __('Enable 3D Secure', 'easytransac_woocommerce') ,
					'default' => 'yes'
				) ,
				'oneclick' => array(
					'title' => __('Enable/Disable One Click payments', 'easytransac_woocommerce') ,
					'type' => 'checkbox',
					'label' => __('Enable One Click payments', 'easytransac_woocommerce') ,
					'default' => 'no'
				) ,
				'disable_stock' => array(
					'title' => __('Disable order stock level reduce', 'easytransac_woocommerce') ,
					'type' => 'checkbox',
					'label' => __('Makes orders not reduce stock level', 'easytransac_woocommerce') ,
					'default' => 'no',
					'desc_tip' => true,
				) ,
				'notifemail' => array(
					'title' => __('Notification e-mail for missing order notification', 'easytransac_woocommerce') ,
					'type' => 'text',
					'label' => __('Comma separated e-mail list to notify when an EasyTransac notification references a missing order ID, useful with bank transfers.', 'easytransac_woocommerce') ,
					'default' => '',
					'desc_tip' => true,
				) ,
				'notifurl' => array(
					'title' => __('Notification URL', 'easytransac_woocommerce') ,
					'type' => 'text',
					'css' => 'width: 500px;',
					'default' => get_site_url() . '/wc-api/easytransac',
				) ,
				'debug_mode' => array(
					'title' => __('Enable/Disable debug mode', 'easytransac_woocommerce') ,
					'type' => 'checkbox',
					'label' => __('Enable/Disable debug mode', 'easytransac_woocommerce') ,
					'default' => 'no'
				)
			);
		}

		/**
		* Returns EasyTransac's ClientId.
		* @return string
		*/
		function getClientId() {
			return get_user_meta(get_current_user_id(), 'easytransac-clientid', 1);
		}

		/**
		* Process payment.
		* @param int $order_id
		*/
		function process_payment($order_id) {
			
			$order = wc_get_order( $order_id );
			
			if (!$order) {
				// Payment failed : Show error to end user.
				wc_add_notice(__('Payment failed: ', 'easytransac_woocommerce') . 'order not found', 'error');

				return array(
					'result' => 'error',
				);
			}

			$total_subscription = 0;
			// Iterating through each "line" items in the order
			// Count the total price of subscription product
			$subscriptions_counter = 0;
			foreach ($order->get_items() as $item_id => $item_data) {

				// Get an instance of corresponding the WC_Product object
				$product = $item_data->get_product();
				$product_type = $product->get_type(); // Get the type of product
				$item_quantity = $item_data->get_quantity(); // Get the item quantity
				$item_total = $item_data->get_total(); // Get the item line total

				// If the product is a subscription product, add to the total
				if ($product_type == 'subscription') {
					$total_subscription += $item_total;
					$product_subscription = WC_Subscriptions_Product::get_period($product);

					// print_r($product_subscription);
					// echo "\r\n - Price:";
					// print_r(WC_Subscriptions_Product::get_price($product));
					// echo "\r\n -  Regular price: ";
					// print_r(WC_Subscriptions_Product::get_regular_price($product));
					// echo "\r\n - Sale price:";
					// print_r(WC_Subscriptions_Product::get_sale_price($product));
					// echo "\r\n";
					// echo "\r\n - Length:";
					// print_r(WC_Subscriptions_Product::get_length($product));
					// echo "\r\n";
					// echo "\r\n - Sign up fee:";
					// print_r(WC_Subscriptions_Product::get_sign_up_fee($product));
					$subscriptions_counter += $item_quantity;
					
					if(WC_Subscriptions_Product::get_trial_length($product) >0)
					{
						wc_add_notice(__('Payment failed: ', 'easytransac_woocommerce') . 'free trial not handled', 'error');
						return array(
							'result' => 'error',
						);
					}
				}
			}

			// Coupons recurring percent discount for subscriptions.
			$discount_type = null;
			$recurring_discount_amount = 0;

			try {
				foreach( $order->get_coupon_codes() as $coupon_code ){
					// Retrieving the coupon ID.
					$coupon_post_obj = get_page_by_title($coupon_code, OBJECT, 'shop_coupon');
					$coupon_id       = $coupon_post_obj->ID;

					// Get an instance of WC_Coupon object in an array(necessary to use WC_Coupon methods)
					$coupon = new WC_Coupon($coupon_id);

					$discount_type = $coupon->get_discount_type();
				}
				
				// Get the Coupon discount amounts in the order
				if($discount_type == 'recurring_percent'){
					$recurring_discount_amount = $order->get_discount_total();
					$recurring_discount_tax = $order->get_discount_tax();
					$get_total = $order->get_total();
					// $msg_debug = sprintf("Discount TYPE: %s - DISCOUNT [ %s ] - DISCOUNT TAX [ %s ]  - ORDER TOTAL [ %s ]",
					// 						$discount_type,
					// 						$recurring_discount_amount,
					// 						$recurring_discount_tax,
					// 						$get_total);
					// error_log('DEBUG: '.$msg_debug);
					$recurring_discount_amount += $recurring_discount_tax;
				}
			} catch (Exception $exc) {
				$discount_type = null;
				$recurring_discount_amount = 0;
				error_log('Easytransac discount exception: '.$exc->getMessage());
			}

			// -----------------------------------

			if($subscriptions_counter > 1)
			{
				wc_add_notice(__('Payment failed: ', 'easytransac_woocommerce') . 'only one subscription handled', 'error');
				return array(
					'result' => 'error',
				);
			}

			// If OneClick button has been clicked && the order isn't a subscription order.
			$is_oneclick = isset($_POST['is_oneclick']) && !empty($_POST['oneclick_alias']) && (!function_exists('wcs_order_contains_subscription') || !wcs_order_contains_subscription($order));

			$api_key = $this->get_option('api_key');
			$dsecure3 = $this->get_option('3dsecure');

			$address = $order->get_address();

			$return_url = add_query_arg('wc-api', 'easytransac', home_url('/'));
			$cancel_url = wc_get_cart_url();

			// Requirements.
			$curl_info_string = function_exists('curl_version') ? 'enabled' : 'not found';
			$openssl_info_string = OPENSSL_VERSION_NUMBER >= 0x10001000 ? 'TLSv1.2' : 'OpenSSL version deprecated';
			$https_info_string = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'S' : '';

			$version_string = sprintf('WooCommerce 2.52 [cURL %s, OpenSSL %s, HTTP%s]', $curl_info_string, $openssl_info_string, $https_info_string);
			$language = get_locale() == 'fr_FR' ? 'FRE' : 'ENG';

			// If Debug Mode is enabled
			EasyTransac\Core\Logger::getInstance()->setActive($this->get_option('debug_mode')=='yes');
			EasyTransac\Core\Logger::getInstance()->setFilePath(__DIR__ . DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR);

			EasyTransac\Core\Services::getInstance()->provideAPIKey($api_key);

			if ($is_oneclick) {
				// SDK OneClick
				$transaction = (new EasyTransac\Entities\OneClickTransaction())
				->setAlias(strip_tags($_POST['oneclick_alias']))
				->setAmount(100 * $order->get_total())
				->setOrderId($order_id)
				->setClientId($this->getClientId());


				$dp = new EasyTransac\Requests\OneClickPayment();

				try {
					$response = $dp->execute($transaction);
				}
				catch(Exception $exc) {
					EasyTransac\Core\Logger::getInstance()->write('Payment Exception: ' . $exc->getMessage());
				}

				if ($response->isSuccess()) {
					/* @var $doneTransaction \EasyTransac\Entities\DoneTransaction */
					$doneTransaction = $response->getContent();

					$this->process_response($doneTransaction);

					if (in_array($doneTransaction->getStatus(), array('captured', 'pending'))) {
						// Payment is processed / captured
						return array(
							'result' => 'success',
							'redirect' => $this->get_return_url(),
							);
					} else {
						// Log error
						EasyTransac\Core\Logger::getInstance()->write('Payment failed: ' . $response->getErrorCode() . ' - ' . $response->getErrorMessage());

						// Payment failed : Show error to end user.
						wc_add_notice(__('Payment failed: ', 'easytransac_woocommerce') . $response->getContent()->getError(), 'error');

						return array(
							'result' => 'error',
							);
					}
				} else {
					// Log error
					EasyTransac\Core\Logger::getInstance()->write('Payment failed: ' . $response->getErrorCode() . ' - ' . $response->getErrorMessage());

					// Payment failed : Show error to end user.
					wc_add_notice(__('Payment failed: ', 'easytransac_woocommerce') . $response->getErrorMessage(), 'error');

					return array(
						'result' => 'error',
						);
				}
			} else {
				// Phone number traitement of '+'
				if (!preg_match("/^[0-9]{7,15}$/", $address['phone'])) {
					$address['phone'] = str_replace("+", "00", $address['phone']);
					if (!preg_match("/^[0-9]{7,15}$/", $address['phone'])) {
						return wc_add_notice(__('Billing phone is not valid phone number.', 'easytransac_woocommerce'), 'error');
					}
				}

				// SDK Payment Page
				$customer = (new EasyTransac\Entities\Customer())
					->setEmail($address['email'])
					->setUid($order->get_user_id())
					->setFirstname($address['first_name'])
					->setLastname($address['last_name'])
					->setAddress($address['address_1'] . ' ' . $address['address_2'])
					->setZipCode($address['postcode'])
					->setCity($address['city'])
					->setBirthDate('')
					->setNationality('')
					->setCallingCode('')
					->setPhone($address['phone']);

				// If the order contains a subscription product.
				if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order)) {

					$transaction = (new EasyTransac\Entities\PaymentPageTransaction())
						->setRebill(WC_Subscriptions_Product::get_length($product) == 0 ? 'yes' : 'no')// If expire date is never (0) = yes
						->setCustomer($customer)
						->setOrderId($order_id)
						->setReturnUrl($return_url)
						->setCancelUrl($cancel_url)
						->setSecure($dsecure3)
						->setVersion($version_string)
						->setLanguage($language);

					// EU VAT assistant.
					$has_vat_number = false;
					try{
						$vn = get_post_meta($order->get_id(), 'vat_number', true);
						$has_vat_number = !empty($vn);
						unset($vn);
					}catch(Exception $e){
						error_log('Easytransac vat_number exception: '.$e->getMessage());
					}

					# Subscription product price.
					if( ! $has_vat_number){
						$product_price = wc_get_price_including_tax($product);
					}else{
						$product_price = wc_get_price_excluding_tax($product);
					}

					# Fee
					$signup_fee_inc_tax = wc_get_price_including_tax($product, ['price' =>  WC_Subscriptions_Product::get_sign_up_fee($product)] );
					$signup_fee_exc_tax = wc_get_price_excluding_tax($product, ['price' =>  WC_Subscriptions_Product::get_sign_up_fee($product)] );
					if( ! $has_vat_number){
						$signup_fee = $signup_fee_inc_tax;
					}else{
						$signup_fee = $signup_fee_exc_tax;
					}


					if(WC_Subscriptions_Product::get_length($product) > 0)
					{
						$transaction->setMultiplePayments(WC_Subscriptions_Product::get_length($product) > 0 ? 'yes' : 'no');
						// If expire date is a number (value>0) of days = yes

						$amount = 100 * ($product_price - $recurring_discount_amount)
								  * WC_Subscriptions_Product::get_length($product) 
								  + (100 * $signup_fee);
						$transaction->setAmount($amount);

						$transaction->setMultiplePaymentsRepeat(WC_Subscriptions_Product::get_length($product));

						# Minimum initial payment of 20%.
						$initial = intval(ceil(0.20 * $amount));
						if($initial > ($amount / WC_Subscriptions_Product::get_length($product) )){
							$transaction->setDownPayment($initial);
						}
					}
					else
					{
						$transaction->setRebill('yes');
						$transaction->setAmount( 100 * 
												 ($product_price - $recurring_discount_amount));
												 // Amount per period
						if(WC_Subscriptions_Product::get_sign_up_fee($product) > 0)
						{
							// Subscription fee added on firstpayment
							$transaction
							->setDownPayment(100 * ($product_price - $recurring_discount_amount + $signup_fee));
						}
					}

					switch ($product_subscription) {
						case 'day':
							$transaction->setRecurrence('daily');
						break;
						case 'week':
							$transaction->setRecurrence('weekly');
						break;
						case 'month':
							$transaction->setRecurrence('monthly');
						break;
						case 'year':
							$transaction->setRecurrence('yearly');
						break;
						case '':
							$transaction->setRecurrence('monthly');
						break;
					}
				} else {
					// If the order contains only "normal" products.
					$transaction = (new EasyTransac\Entities\PaymentPageTransaction())
						->setAmount(100 * $order->get_total())
						->setCustomer($customer)
						->setOrderId($order_id)
						->setReturnUrl($return_url)
						->setCancelUrl($cancel_url)
						->setSecure($dsecure3)
						->setVersion($version_string)
						->setLanguage($language);
				}

				/* @var $response \EasyTransac\Entities\PaymentPageInfos */
				try {
					$request = new EasyTransac\Requests\PaymentPage();
					$response = $request->execute($transaction);
				}
				catch (Exception $exc) {
					EasyTransac\Core\Logger::getInstance()->write('Payment Exception: ' . $exc->getMessage());
				}
			}

			$_SESSION['easytransac_order_id'] = $order_id;

			if (!$response->isSuccess()) {
				// Log error
				EasyTransac\Core\Logger::getInstance()->write('Payment error: ' . $response->getErrorCode() . ' - ' . $response->getErrorMessage());

				// Show error to end user.
				wc_add_notice(__('Payment error:', 'easytransac_woocommerce') . ' ' . $response->getErrorCode() . ' - ' .$response->getErrorMessage(), 'error');

				// Returns error.
				return array(
					'result' => 'error',
					);
			}

			// Reduce stock levels if not disabled by option.
			
			if($this->get_option('disable_stock') == 'no'){
				if (function_exists('wc_reduce_stock_levels')) {
					// WooCommerce v3
					wc_reduce_stock_levels($order);
				} else {
					$order->reduce_order_stock();
				}
			}

			// Redirect to EasyTransac Payment page
			return array(
				'result' => 'success',
				'redirect' => $response->getContent()->getPageUrl(),
			);
		}

		/**
		* Listcards AJAX callback.
		*/
		function listcards() {
			$clientId = $this->getClientId();
			if (!$clientId || empty($clientId))
				die(json_encode(array()));

			EasyTransac\Core\Services::getInstance()->provideAPIKey($this->get_option('api_key'));
			$customer = (new EasyTransac\Entities\Customer())->setClientId($clientId);

			$request = new EasyTransac\Requests\CreditCardsList();
			$response = $request->execute($customer);

			if ($response->isSuccess()) {
				$buffer = array();
				foreach ($response->getContent()->getCreditCards() as $cc) {
					/* @var $cc EasyTransac\Entities\CreditCard */
					$year = substr($cc->getYear(), -2, 2);
					$buffer[] = array('Alias' => $cc->getAlias(), 'CardNumber' => $cc->getNumber(), 'Month' => $cc->getMonth(), 'Year' => $year);
				}
				$output = array('status' => !empty($buffer), 'packet' => $buffer);
				echo json_encode($output);
			}
		}

		/**
		* Debug function.
		*/
		function _debug($var) {
			file_put_contents(__DIR__ . DIRECTORY_SEPARATOR . 'dump', $var);
		}

		/**
		* EasyTransac's callback handler // OneClick handler.
		*
		* Example: http://yoursite.com/wc-api/easytransac
		*/
		function check_callback_response() {
			EasyTransac\Core\Logger::getInstance()->setActive($this->get_option('debug_mode')=='yes');
			EasyTransac\Core\Logger::getInstance()->setFilePath(__DIR__ . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR);

			// OneClick handlers.
			if (isset($_GET['listcards'])) {
				$this->listcards();
				die;
			}

			$received_data = array_map('stripslashes_deep', $_POST);

			$api_key = $this->get_option('api_key');
			if (empty($api_key)) {
				header('Location: ' . home_url('/'));
				exit;
			}

			$is_https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';

			if(isset($received_data['data']))
				unset($received_data['data']);
			
			EasyTransac\Core\Logger::getInstance()->write('Received POST: ' . var_export($received_data, true));
			
			if($is_https || (!$is_https && !empty($received_data))) {
				// FIX : HTTPS return or notification + HTTP api call
				try {
					$response = \EasyTransac\Core\PaymentNotification::getContent($received_data, $api_key);

					if(!$response) throw new Exception ('empty response');
				}
				catch (Exception $exc) {
					// Log error
					EasyTransac\Core\Logger::getInstance()->write('Payment error: ' . $exc->getCode() . ' (' . $exc->getMessage().') ');

					error_log('EasyTransac error: ' . $exc->getMessage().' debug: '.$this->get_option('debug_mode'));
					header('Location: ' . home_url('/'));
					die;
				}
			}

			// On non-HTTPS sites, simply redirects and wait for the notification the update the status.
			if (empty($received_data) && !$is_https) {
				// FIX : On HTTP sites received_data must be empty or its the API call.
				header('Location: ' . $this->get_return_url());
				exit;
			}

			if (empty($received_data)) {
				header('Location: ' . home_url('/'));
				exit;
			}

			$notificationMessages = [];

			$invalidOrderIdFormat = false;
			
			// Bank transfer notification
			if( $response->getOperationType() == 'credit' ){

				$invalidOrderIdFormat = true;

				// Extract possible order id from description.
				// $text = $response->getDescription();// TODO SDK caveat
				$text = '';

				if(!empty($received_data['Description'])){
					$text = $received_data['Description'];
				}

				if(empty($text)){
					error_log('EasyTransac debug: error : missing description for credit decode');
				}

				preg_match_all("/[0-9]+/", $text, $matches);

				if(!empty($matches)){
					$matches = end($matches);
				}

				foreach($matches as $possible_id){
					try {
						$possible_id = intval($possible_id);

						$order = new WC_Order($possible_id);

						if($order && $order->get_total() > 0){

							// Check amount match.

							if($response->getAmount() == $order->get_total()){
								$invalidOrderIdFormat = false;
								$response->setOrderId($possible_id);

								if($response->getOrderId() != $possible_id){
									error_log('EasyTransac debug: error : set id doesnt match the new id: '.$response->getOrderId().' != '.$possible_id);
								}

								break;
							}
						}
					} catch (\Throwable $th) {
					}
				}
			}elseif(preg_match('/ /', $response->getOrderId())){
				// $invalidOrderIdFormat = true;
				error_log('EasyTransac debug: invalid order id containing a space format that is not a credit type'.$response->getOrderId());
			}
			
			$order_id_info = $response->getOrderId();
			if($response->getOperationType() == 'credit' && !empty($received_data['Description'])){
				$order_id_info = $received_data['Description'];
			}
			if($invalidOrderIdFormat || ! ($order = new WC_Order($response->getOrderId())) || 0)
			{
				$notificationMessages[] =  
					sprintf('La commande "%s" de %s EUR pour laquelle un %s a été reçu sur EasyTransac n\'a pas été trouvée.',
						$order_id_info, 
						$response->getAmount(),
						$response->getOperationType() === 'payment' ? 'paiement' : 'virement'
					);

				$errMsg = 'EasyTransac: Order ID not found: '.$order_id_info;
				error_log($errMsg);
				EasyTransac\Core\Logger::getInstance()->write('Order ID missing: '. $errMsg);

			}
			elseif($response->getAmount() != $order->get_total() || 0){
				$notificationMessages[] =  
				sprintf('La commande "%s" de %s EUR ne correspond pas au %s de %s EUR reçu par EasyTransac.',
					$order_id_info, 
					$order->get_total(),
					$response->getOperationType() === 'payment' ? 'paiement' : 'virement',
					$response->getAmount()
				);

				$order->add_order_note( end($notificationMessages) );

				$errMsg = 'EasyTransac: amounts mismatch for order: '.$order_id_info;
				error_log($errMsg);
				EasyTransac\Core\Logger::getInstance()->write('Amounts mismatch: '. $errMsg);
			}

			if(!empty($notificationMessages)){

				if(empty($this->get_option('notifemail'))){
					die('Integrity error but no notification mail set.');
				}
				if(!isset($_GET['wc-api'])){
					// E-mail notification triggered by EMS only.
					$subject = 'EasyTransac notification';
					$message = implode("\n", $notificationMessages);
					$emails = preg_split('/[,;]/', $this->get_option('notifemail'));
					$emails = array_filter($emails);
					
					foreach ($emails as $destEmail) {
						$destEmail = trim($destEmail);
						if(filter_var($destEmail, FILTER_VALIDATE_EMAIL)){
							wp_mail( $destEmail, $subject, $message );
						}
					}
					die('Order missing or amount mismatch. Notification sent.');
				}
				header('Location: ' . $this->get_return_url());
				exit;
			}


			if(!isset($_GET['wc-api']) && $order->get_status() == 'processing'){
				// EMS response.
				die('Order status already processing no status change');
			}

			// Save transaction ID
			
			if($order->get_status() != 'processing'){
				// Not changing processing status.
				update_post_meta($response->getOrderId(), 'ET_Tid', $response->getTid());
				switch ($response->getStatus()) {
					case 'failed':
						EasyTransac\Core\Logger::getInstance()->write('Payment error: ' . $response->getError() . ' - ' . $response->getMessage());
						$order->update_status('failed', $response->getMessage());
						wc_add_notice(__('Payment error:', 'easytransac_woocommerce') . $response->getMessage(), 'error');
					break;
					
					case 'captured':
					// Saves ClientId
					if($response->getClient())
						add_user_meta($order->get_user_id(), 'easytransac-clientid', $response->getClient()->getId());
					$order->payment_complete();
					// Empty cart
					global $woocommerce;
					$woocommerce->cart->empty_cart();
					break;
					
					case 'pending':
						// Nothing to do
					break;
					
					case 'refunded':
						$order->update_status('refunded', $response->getMessage());
					break;
				}
			}
			if(!isset($_GET['wc-api'])){
				// EMS response.
				die('Order status received');
			}

			header('Location: ' . $this->get_return_url());
		}

		/**
		* Process EasyTransac response and saves order only used by oneclick response yet.
		*
		* @global type $woocommerce
		* @param EasyTransac\Entities\DoneTransaction $received_data
		*
		* @todo Use in check_callback_response() which is payment-page-logic only.
		*/
		function process_response($received_data) {
			$order = new WC_Order($received_data);

			if($order->get_status() == 'processing'){
				return;
			}
			// Saves transaction ID in the order object.
			update_post_meta($received_data->getOrderId(), 'ET_Tid', $received_data->getTid());

			switch ($received_data->getStatus()) {
				case 'failed':
					$order->update_status('failed', $received_data->getMessage());
				break;

				case 'captured':
					add_user_meta($order->get_user_id(), 'easytransac-clientid', $received_data->getClient()->getId());
					$order->payment_complete();
					// Empty cart
					global $woocommerce;
					$woocommerce->cart->empty_cart();
				break;

				case 'pending':
					// Waiting
				break;

				case 'refunded':
					$order->update_status('refunded', $received_data->getMessage());
				break;
			}
		}

		/**
		* Refund process
		*
		* @param int $order_id
		* @param float $amount
		* @param string $reason
		* @return bool|WP_Error True or false based on success, or a WP_Error object.
		*
		* @deprecated since version 1.3 because EasyTransac API doesn't support partial refund nor WooCommerce supports full refund only.
		*/
		public function process_refund($order_id, $amount = null, $reason = '')
		{
			EasyTransac\Core\Logger::getInstance()->setActive($this->get_option('debug_mode')=='yes');
			EasyTransac\Core\Logger::getInstance()->setFilePath(__DIR__ . DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR);
			$api_key = $this->get_option('api_key');
			EasyTransac\Core\Services::getInstance()->provideAPIKey($api_key);

			$order = wc_get_order($order_id);

			if ($order->get_total() != $amount) {
				return new WP_Error('easytransac-refunds', __('EasyTransac support full refund only.', 'easytransac_woocommerce'));
			}
			$refund = (new \EasyTransac\Entities\Refund)
					->setTid(get_post_meta($order_id, 'ET_Tid', true));

			$request = (new EasyTransac\Requests\PaymentRefund);
			$response = $request->execute($refund);

			if (empty($response)) {
				return new WP_Error('easytransac-refunds', __('Empty Response', 'easytransac_woocommerce'));
			}
			else if (!$response->isSuccess()) {
				return new WP_Error('easytransac-refunds', $response->getErrorMessage());
			}
			else {
				return true;
			}
		}

		/**
		* Get gateway icon.
		* @return string
		*/
		public function get_icon() {
			$icon_url = plugin_dir_url(__FILE__) . '/includes/icon.png';
			$icon_html = "<script type=\"text/javascript\">function usingGateway(){console.log(jQuery(\"input[name='payment_method']:checked\").val()),\"easytransac\"==jQuery('form[name=\"checkout\"] input[name=\"payment_method\"]:checked').val()?document.getElementById(\"easytransac-icon\").style.visibility=\"visible\":document.getElementById(\"easytransac-icon\").style.visibility=\"hidden\"}jQuery(function(){jQuery(\"body\").on(\"updated_checkout\",function(){usingGateway(),jQuery('input[name=\"payment_method\"]').change(function(){console.log(\"payment method changed\"),usingGateway()})})});</script>";
			$icon_html .= sprintf( '<br><a href="%1$s" class="about_easytransac" onclick="javascript:window.open(\'%1$s\',\'WIEasyTransac\',\'toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=yes, resizable=yes, width=1060, height=700\'); return false;">' . esc_attr__( 'What is EasyTransac?', 'easytransac_woocommerce' ) . '</a>', esc_url('https://www.easytransac.com/fr/support'));
			$icon_html .= '<img id="easytransac-icon" src="' . esc_attr($icon_url) . '" alt="' . esc_attr__('EasyTransac', 'easytransac_woocommerce') . '" style="max-height:52px;display:inline-block;margin-top:55px;" />';
			// Injects OneClick if enabled.
			$oneclick = $this->get_option('oneclick');
			if($oneclick == 'yes') {
				$icon_html .= '<script type="text/javascript">var chooseCard = "';
				$icon_html .= __('Choose a card:', 'easytransac_woocommerce');
				$icon_html .= '"; var payNow = "';
				$icon_html .= __('Pay now', 'easytransac_woocommerce') . '";</script>';
				$icon_html .= '<script type="text/javascript" src="' . plugin_dir_url(__FILE__) . '/includes/oneclick.js"></script>';
			}
			return apply_filters('woocommerce_gateway_icon', $icon_html, $this->id);
		}
	}
}

// Load plugin
add_action('plugins_loaded', 'init_easytransac_gateway');

function add_easytransac_gateway($methods) {
	$methods[] = 'EasyTransacGateway';
	return $methods;
}

// Register gateway in WooCommerce
add_filter('woocommerce_payment_gateways', 'add_easytransac_gateway');

// Internationalization
load_plugin_textdomain('easytransac_woocommerce', false, dirname(plugin_basename(__FILE__)) . DIRECTORY_SEPARATOR.'i18n'.DIRECTORY_SEPARATOR.'languages'.DIRECTORY_SEPARATOR);

// Settings quick link.
add_filter( 'plugin_action_links_' . plugin_basename(__FILE__), 'add_action_links' );
function add_action_links ( $links ) {
	$mylinks = array(
	'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=easytransacgateway' ) . '">'.__('Settings').'</a>',
	);
   return array_merge( $links, $mylinks );
}

// Stock level reduce option.
function processing_easytransac_stock_not_reduced( $reduce_stock, $order ) {
    if ($order->get_payment_method() == 'easytransac' ) {
		if(($options = get_option('woocommerce_easytransac_settings'))){
			if(isset($options['disable_stock']) && $options['disable_stock'] == 'yes'){
				return false;
			}
		}
    }
    return $reduce_stock;
}
add_filter( 'woocommerce_can_reduce_order_stock', 'processing_easytransac_stock_not_reduced', 20, 2 );


