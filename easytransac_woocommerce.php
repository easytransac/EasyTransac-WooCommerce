<?php

require __DIR__ . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Plugin Name: EasyTransac for WooCommerce
 * Plugin URI: https://www.easytransac.com
 * Description: Payment Gateway for EasyTransac. Create your account on <a href="https://www.easytransac.com">www.easytransac.com</a> to get your application key (API key) by following the steps on <a href="https://fr.wordpress.org/plugins/easytransac/installation/">the installation guide</a> and configure this plugin <a href="../wp-admin/admin.php?page=wc-settings&tab=checkout&section=easytransacgateway">here</a>
 * Version: 2.4
 *
 * Text Domain: easytransac_woocommerce
 * Domain Path: /i18n/languages/
 *
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

function init_easytransac_gateway() {

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
							'subscriptions'
							);

			$this->title = $this->get_option('title');

			// @deprecated since version 1.3 because EasyTransac API doesn't support partial refund nor WooCommerce supports full refund only.
			//			$this->supports = array(
			//				'refunds'
			//			);

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
					'default' => 'yes'
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
			$order = new WC_Order($order_id);
			$order = wc_get_order( $order_id );

			$total_subscription = 0;
			// Iterating through each "line" items in the order
			// Count the total price of subscription product
			foreach ($order->get_items() as $item_id => $item_data) {

				// Get an instance of corresponding the WC_Product object
				$product = $item_data->get_product();
				$product_type = $product->get_type(); // Get the type of product
				$item_total = $item_data->get_total(); // Get the item line total

				// If the product is a subscription product, add to the total
				if ($product_type == 'subscription') {
					$total_subscription += $item_total;
					$product_subscription = WC_Subscriptions_Product::get_period($product);
				}
			}

			// If OneClick button has been clicked && the ,order isn't a subscription order.
			$is_oneclick = isset($_POST['is_oneclick']) && !empty($_POST['oneclick_alias']) && !wcs_order_contains_subscription($order);

			$api_key = $this->get_option('api_key');
			$dsecure3 = $this->get_option('3dsecure');

			$address = $order->get_address();

			$return_url = add_query_arg('wc-api', 'easytransac', home_url('/'));
			$cancel_url = wc_get_cart_url();

			// Requirements.
			$curl_info_string = function_exists('curl_version') ? 'enabled' : 'not found';
			$openssl_info_string = OPENSSL_VERSION_NUMBER >= 0x10001000 ? 'TLSv1.2' : 'OpenSSL version deprecated';
			$https_info_string = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'S' : '';

			$version_string = sprintf('WooCommerce 2.4 [cURL %s, OpenSSL %s, HTTP%s]', $curl_info_string, $openssl_info_string, $https_info_string);
			$language = get_locale() == 'fr_FR' ? 'FRE' : 'ENG';

			// If Debug Mode is enabled
			EasyTransac\Core\Logger::getInstance()->setActive($this->get_option('debug_mode')=='yes');
			EasyTransac\Core\Logger::getInstance()->setFilePath(__DIR__ . '/logs/');

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

				// If the order contain subscription product
				if (wcs_order_contains_subscription($order)) {
					$transaction = (new EasyTransac\Entities\PaymentPageTransaction())
						->setRebill('yes')
						->setDownPayment(100 * $order->get_total())
						->setAmount(100 * $total_subscription)
						->setCustomer($customer)
						->setOrderId($order_id)
						->setReturnUrl($return_url)
						->setCancelUrl($cancel_url)
						->setSecure($dsecure3)
						->setVersion($version_string)
						->setLanguage($language);

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
					// If the order contain only "normal" products
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
				$request = new EasyTransac\Requests\PaymentPage();

				/* @var $response \EasyTransac\Entities\PaymentPageInfos */
				try {
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

			// Reduce stock levels
			if (function_exists('wc_reduce_stock_levels')) {
				// WooCommerce v3
				wc_reduce_stock_levels($order);
			} else {
				$order->reduce_order_stock();
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
			if (!$clientId)
			die(json_encode(array()));

			EasyTransac\Core\Services::getInstance()->provideAPIKey($this->get_option('api_key'));
			$customer = (new EasyTransac\Entities\Customer())->setClientId($clientId);

			$request = new EasyTransac\Requests\CreditCardsList();
			$response = $request->execute($customer);

			if ($response->isSuccess()) {
				$buffer = array();
				foreach ($response->getContent()->getCreditCards() as $cc) {
					/* @var $cc EasyTransac\Entities\CreditCard */
					$buffer[] = array('Alias' => $cc->getAlias(), 'CardNumber' => $cc->getNumber());
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
			EasyTransac\Core\Logger::getInstance()->setFilePath(__DIR__ . '/logs/');

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

			if($is_https || (!$is_https && !empty($received_data))) {
				// FIX : HTTPS return or notification + HTTP api call
				try {
					$response = \EasyTransac\Core\PaymentNotification::getContent($_POST, $api_key);

					if(!$response) throw new Exception ('empty response');
				}
				catch (Exception $exc) {
					// Log error
					EasyTransac\Core\Logger::getInstance()->write('Payment error: ' . $exc->getErrorCode() . ' (' . $exc->getMessage().') - ' . ($response instanceof EasyTransac\Responses\StandardResponse ? $response->getErrorMessage():''));

					error_log('EasyTransac error: ' . $exc->getMessage());
					header('Location: ' . home_url('/'));
					die;
				}
			}
			EasyTransac\Core\Logger::getInstance()->write('Received POST: ' . var_export($_POST, true));

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

			$order = new WC_Order($response->getOrderId());

			// Save transaction ID
			update_post_meta($response->getOrderId(), 'ET_Tid', $response->getTid());

			switch ($response->getStatus()) {
				case 'failed':
					// Log error
					EasyTransac\Core\Logger::getInstance()->write('Payment error: ' . $response->getErrorCode() . ' - ' . $response->getErrorMessage());

					$order->update_status('failed', $response->getMessage());
					wc_add_notice(__('Payment error:', 'easytransac_woocommerce') . $response->getMessage(), 'error');
				break;

				case 'captured':
					// Saves ClientId
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

			header('Location: ' . $this->get_return_url());
		}

		/**
		* Process EasyTransac response and saves order.
		*
		* @global type $woocommerce
		* @param EasyTransac\Entities\DoneTransaction $received_data
		*
		* @todo Use in check_callback_response() which is payment-page-logic only.
		*/
		function process_response($received_data) {
			$order = new WC_Order($received_data);

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
					// Nothing to do
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
		//		public function process_refund($order_id, $amount = null, $reason = '')
		//		{
		//			$et_transaction_id = get_post_meta($order_id, 'ET_Tid', true);
		//
		//			$easytransac = new EasyTransacApi();
		//
		//			$data	= array('Tid' => $et_transaction_id);
		//			$api_key = $this->get_option('api_key');
		//
		//			$response = $easytransac->easytransac_refund($data, $api_key);
		//
		//			if (empty($response))
		//			{
		//				return new WP_Error('easytransac-refunds', 'Empty Response');
		//			}
		//
		//			if ($response['Result']['Status'] === 'refunded')
		//			{
		//				return true;
		//			}
		//		}

		/**
		* Get gateway icon.
		* @return string
		*/
		public function get_icon() {
			$icon_url = plugin_dir_url(__FILE__) . '/includes/icon.jpg';
			$icon_html = '<img src="' . esc_attr($icon_url) . '" alt="' . esc_attr__('EasyTransac', 'easytransac_woocommerce') . '" style="max-height:52px;" />';

			// Injects OneClick if enabled.
			$oneclick = $this->get_option('oneclick');

			if($oneclick == 'yes')
				$icon_html .= '<script type="text/javascript" src="' . plugin_dir_url(__FILE__) . '/includes/oneclick.js"></script>';

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
load_plugin_textdomain('easytransac_woocommerce', false, dirname(plugin_basename(__FILE__)) . '/i18n/languages/');
?>
