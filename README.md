# === EasyTransac-Woocomerce ===
Contributors: EasyTransac
Tags: payment,checkout,payment pro,encaissement,moyen de paiement,paiement,bezahlsystem,purchase,online payment,easytransac
Requires at least: 4.1
Tested up to: 4.8.3
Stable tag: 2.3
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

EasyTransac payment gateway for WooCommerce.

## == Description ==

### = Introduction =

Easy payment solution for your Wordpress WooCommerce website:

 * Accept all CB, VISA, and Mastercard payments in euro
 * EasyTransac provides turnkey secure payment at very low charge rates

### = Easy install step by step =

Please check the following points in order to install this module:

 * Check that your webserver has the minimum requirements (PHP Curl, OpenSSL 1.0.1)
 * Ensure you have the WooCommerce plugin enabled first
 * Download and install this plugin
 * Create your EasyTransac account on <a href="https://www.easytransac.com">www.easytransac.com</a>
 * Configure the EasyTransac plugin by following the **FAQ** and the **Installation guide**


**Please read the installation guide before using this plugin and follow the steps in the FAQ to create your EasyTransac account.**

**Requirements**: PHP Curl extension and OpenSSL version 1.0.1 (visible in your phpinfo).

See our website for <a href="https://www.easytransac.com/en/e-comerce">a complete list of our other E-comerce modules</a>

## == Requirements ==

Please check your phpinfo for the following requirements:

 * PHP >= 5.5
 * OpenSSL version 1.0.1
 * Works on WooCommerce v2.8 and v3+

## == Installation ==

1. Create account on https://www.easytransac.com, then log in and got to 'Applications' and add a new application.
On the application creation page, you'll have to give a name to the application that will allow you to filter the transactions if you have many uses of EasyTransac.
2. You also get the key on this page that you need to paste on the WooCommerce payment gateway configuration page (Step 8).
3. You can chose the application type: 'Test' means that payments aren't real, whereas Live is for production use.
4. Please enter the IP address of your server hosting your e-commerce website in the allowed IP address section.
In the notification URL enter your website's URI followed by '/wc-api/easytransac'. Example: http://yoursite.com/wc-api/easytransac
5. Install the WooCommerce plugin or check it is enable if you already got it.
6. Upload the plugin files to the `/wp-content/plugins/easytransac_woocommerce` directory, or install the plugin through the WordPress plugins screen directly.
7. Activate the plugin through the 'Plugins' screen in WordPress
8. Use the WooCommerce->Settings->Checkout->EasyTransac screen to configure your EasyTransac API key.


## == Frequently Asked Questions ==

### = How to get my EasyTransac API Key? =

* First of all, create an account on https://www.easytransac.com, then log in and got to 'Applications' and add a new application.
On the application creation page, you'll have to give a name to the application that will allow you to filter the transactions if you have many uses of EasyTransac.
* You also get the key on this page that you need to paste on the WooCommerce payment gateway configuration page (Installation->Step 3).
* You can chose the application type: 'Test' means that payments aren't real, whereas Live is for production use.
* Please enter the IP address of your server hosting your e-commerce website in the allowed IP address section.
In the notification URL enter your website's URI followed by '/wc-api/easytransac'. Example: http://yoursite.com/wc-api/easytransac
 

## == Changelog ==

<<<<<<< HEAD
### = 2.4 =
* Debug Mode
=======
### = 2.5 =
* New subscriptions possibilities
* New refunds possibilities

### = 2.4 =
* New debug mode for problem troubleshooting.
>>>>>>> upstream/master

### = 2.3 =
* EasyTransac SDK update to v1.0.10

### = 2.2 =
* WooCommerce v3 compatibility.
* HTTP notification issue fix for HTTP only websites.

### = 2.1 =
* OneClick can be disabled.

### = 2.0 =
* Easytransac SDK integration. New requirement : PHP >= 5.5.

### = 1.9 =
* Language of payment page is set.

### = 1.8 =
* OneClick payments.

### = 1.7 =
* cURL fallback.
* Notification URL helper on settings page.

### = 1.6 =
* New logo.

### = 1.5 =
* TLSv1.1 Fallback instead of TLSv1 which is not working correctly on certain systems.

### = 1.4 =
* Non-HTTPS websites hotfix.

### = 1.3 =
* Cancel button redirects back to the cart.
* The cart is only emptied when the payment is completed.
* Refund support removed: EasyTransac API doesn't support partial refund nor WooCommerce supports full refund only. Refund can still be done via the EasyTransac back office.

### = 1.2 =
* Support for non-HTTPS websites.
* Adds system requirements checks.

### = 1.1 =
* French translations.

### = 1.0 =
* WooCommerce Payment Gateway.
