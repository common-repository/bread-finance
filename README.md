=== Plugin Name ===
Contributors: breadpay
Tags: bread, finance, breadpay, woocommerce, financing
Requires at least: 4.9
Tested up to: 6.1.1
Stable tag: 3.5.7
Requires PHP: 5.6
WC requires at least: 3.0
WC tested up to: 7.3.0

License: GNU General Public License v3.0
License URI: http://www.gnu.org/licenses/gpl-3.0.html

Bread helps retailers offer pay-over-time solutions as a way to build stronger consumer connections, power sales, and improve brand loyalty.

== Description ==

##About Bread Pay
Bread Finance is now Bread Pay. 

Bread Pay is a full-funnel, white label financing solution that helps retailers acquire and convert more customers. Retailers who use Bread have seen an increase of 5-15% in sales, up to 120% higher AOV, and an 84% increase in email click-through-rates. 

Our tools have proven to reduce friction and improve conversion by engaging more shoppers throughout the funnel, and by creating a brand-consistent financing experience. With Bread, customers can apply and pre-qualify for financing earlier in the shopping journey, empowering them with transparent financing options that increase their purchasing power and drives more sales.

Let us help you sell more and grow faster with flexible pay-over-time solutions. To learn more, please visit [payments.breadfinancial.com](https://payments.breadfinancial.com/).

##Bread’s Features
#####Attract More Customers
* Full Funnel. Your shoppers can discover, pre-qualify, and check out from anywhere - your homepage, category page, product page, cart, or checkout. 
* Real-Time Decision. Pre-qualification is quick and easy. Let your customers learn about their purchase power in seconds without ever leaving your site.

#####Boost Your Conversions
* White Label. We're committed to enhancing your brand, not ours. Customize our tools to match the look and feel of your site.
* Flexible Financing Options. Offer terms and financing programs optimized for your products, your order values, and your customers.

#####Improve Marketing & Loyalty
* Actionable Data. Make informed decisions on how to improve customer loyalty using detailed insights available on our platform.
* Drive Retargeting. Take advantage of Bread's robust APIs to reach abandoned shoppers and recover lost revenue.

##Case Studies

**Bread Pay Financing Helped The RTA Store Increase Average Ticket Size by 130%**

By partnering with Bread Pay, furniture retailer The RTA Store saw an 8.5% incremental increase in year-over-year sales and 130% higher AOV for customers who used Bread financing compared to those who did not.

**EraGem Triples Financed Sales, Sees 17% Higher AOV with Bread**

Jeweler EraGem experienced 3X more in financed sales, 30% higher year-to-date revenue, and 17% higher AOV after switching to Bread from another financing provider.

##Seamless Integration

Bread's financing solutions can be easily integrated into your WooCommerce platform, making your integration fast and painless. Bread's robust API can be customized to fit your user experience and website.

Bread’s plugin also allows all authorizations, captures, and refunds to be processed through WooCommerce. For installation and setup instructions, please refer to our documentation site.
This plugin has been tested on WooCommerce versions 3.0 and above.


== Installation ==

Installation instructions can be found on our [documentation](https://docs.breadpayments.com/bread-classic/docs/woocommerce) site. 

Note: If your site utilizes any 3rd party optimizing/caching plugins, please exclude Bread's JavaScript and CSS files from these plugins. Also, because our plugin relies on the native jQuery version (1.12.4) provided by WordPress, please ensure your store does not pull in any other jQuery scripts, which may interfere with our plugin's functionality.

== Screenshots ==

1. Customers can pre-qualify for financing and checkout from product pages
2. Let your customers learn about their purchase power in seconds without ever leaving your site.
3. Customers can also checkout directly from the cart page
4. Pre-qualification is quick and easy
5. Sell more and grow faster with Bread’s full-funnel solution


== Changelog ==

= 3.5.7
* Current release
* Add HPOS compatibility

= 3.5.6
* Rename source field so it doesn't interfere with other plugins
* Update Bread placeholder
* Fix sandbox urls

= 3.5.5
* Fix admin cart

= 3.5.4
* Fix issue when using multiple coupons

= 3.5.3
* Check shipping method on page load

= 3.5.2
* Add support for WPCaptcha

= 3.5.1
* Fix RBC Checkout Block

= 3.5.0
* Add support for Checkout Blocks and BOPIS

= 3.4.3
* Fix duplicate placements on PDP

= 3.4.2
* Fix issue with placement not showing in PDP if "Redirect to the cart" is enabled

= 3.4.1
* Compatiblity with WooCommerce Price Based on Country

= 3.4.0
* Fix for variant pricing changes
* Fix for modal not showing up when cart is updated
* Compatiblity with Amasty One Step Checkout
* Unified codebase with automated packaging to make deploys faster and efficient

= 3.3.6
* Compatiblity with WooCommerce Composite Products and Product Bundles

= 3.3.5
* Added unit tests
* Added Embedded Checkout feature to display on same page instead of modal
* Remove healthcare mode from Bread 2.0

= 3.3.4
* Bread Admin carts support

= 3.3.3
* Bug fix for discount codes applied with Avatax enabled

= 3.3.2
* Added admin notices to notify merchants of re-configuration they need to make if upgrading to v3.3.0+
* Added local logging of data for Transaction service

= 3.3.1
* Woocommerce product add-ons plugin compatibility
* Shipping cost bug fix when there are no shipping options selected
* floatval conversion bug fix when converting dollars to cents
* postal code bug fix for shipping method when customer is completing checkout
* composite products order total fix on the pdp page

= 3.3.0
* Unified Woocommerce Bread platform & classic orders
* Float fix on conversion from dollar amount to cents

= 3.1.9
* Shipping address fix on Cart page checkout

= 3.1.8
* Bread button class styling fix
* Bread classic credentials bug fix

= 3.1.7
* Support for Bread classic orders management on Bread 2.0
* Shipping address fix on category & pdp pages
* Fetch payment_method on process_refund fix

= 3.1.6
* Variable & composite products incomplete products rendering
* Styling for Bread button 
* Woocommerce product option bug fix

= 3.1.5
* Bread cart expire cart bug fix
* Bread cancel transaction bug fix

= 3.1.4
* array_key_exists fix on error checking for php 8.* +
* Bread platform merchantOrderId sync to bread backend fix

= 3.1.3
* Bread Pay order confirmation fix

= 3.1.2
* US tax issue fix

= 3.1.1
* bread button not display with Wordpress 6.0.1 fix

= 3.1.0
* Bread classic split-pay shipping address issue fix

= 3.0.9
* Removed resize option on BreadPay logo

= 3.0.8
* Split-pay label toggle on Merchant settings for Bread classic checkout

= 3.0.7
* Apartment number not showing on breadcheckout
* Plugin uninstall script
* Minor bug fixes

= 3.0.6
* Admin bread carts bug fix on bread classic
* Settle a transaction when an order is completed

= 3.0.5
* Release date: June 02, 2022
* render button for incomplete products fix
* split pay bread integration error fix

= 3.0.4
* Release date: May 12, 2022
* Additional Bread Pay branding updates
* Compatibility with Woocommerce version 6.4.1

= 3.0.3
* Release date: April 10, 2022
* Bread Financial branding updates

= 3.0.2
* Release date: March 30, 2022
* Transaction authorize parameter bug fix
* Added transaction auto_settle merchant configuration

= 3.0.1
* Release date: February 7, 2022
* Composite products fix: Bread button correctly renders when composite product information is selected
* Variable products fix: Bread button correctly render when variable product information is selected
* Woocommerce is_shop() page type support

= 3.0.0
* Release date: November 1, 2021
* Adds support for Bread platform
* Bug fixes: Transaction settlement and refunds fix

= 2.0.4 =
* Release date: January 7, 2021
* Adds Split pay, a feature that allows your customers to make four equal payments of the checked out amount
* bug fixes: Intl Collator will no longer show plugin errors when the merchant does not have the intl package installed

= 2.0.3 =
* Release date: September 25, 2020
* Added Woocommerce Advanced Shipping Tracking plugin support
* Bug fixes: Avatax hc discounts fix

= 1.0.9 = 
* Release date: January 22, 2020
* Adds ability to disable the Bread button based on a product's ID
* Adds cartOrigin field for WC Bread cart orders
* Bug fixes: cancelling orders won't trigger a refund, better handling of composite products as low as pricing

= 1.0.8 =
* Release date: November 14, 2019
* Adds ability to apply targeted financing based on cart size
* Bug fixes: WC Bread carts, autoload path, conditional Sentry script loading

= 1.0.7 = 
* Release date: October 8th, 2019
* Adds more explicit path for requiring autoload.php
* Bug fixes: Order-pay flow

= 1.0.6 = 
* Release date: October 4th, 2019
* Adds Sentry logging to report preliminary Bread-related issues
* Adds support for WooCommerce's order-pay flow
* Various fixes: Avalara tax calculation, modifyOpts at checkout

= 1.0.5 = 
* Release date: September 27th, 2019
* Bug fixes: Composite and variable product page button display

= 1.0.4 = 
* Release date: September 24th, 2019
* Adds ability for as low as pricing to display on initial load for variable and composite products
* Adds setting to exclude composite products from the price threshold setting
* Various fixes: Polylang support

= 1.0.3 = 
* Release date: August 28th, 2019
* Adds ability to allow certain customizations to persist (using WordPress child themes)
* Adds advanced settings section, including a price threshold
* Various fixes: draft product issue, deprecated WC methods

= 1.0.2 =
* Release date: August 9th, 2019
* Accomodates modified healthcare restrictions
* Adds ability to auto-cancel failed credit card declined remainder-pay transactions

= 1.0.1 =
* Release date: July 26, 2019
* Adds ability to use Bread carts within the WooCommerce OMS
* Adds workaround for free shipping discount codes

= 1.0.0 =
* Release date: July 11, 2019
* Adds ability to handle Avalara tax calculations
* Adds checkout capability at cart and PDP when discount code(s) are applied
* Adds validation around Bread settings 

= 0.3.50 =
* Release date: April 15, 2019
* Adds better handling of cart page behavior when discount code(s) are applied
* Adds filter to add data-api-key attribute to the bread.js script tag

= 0.3.49 =
* Release date: March 14, 2019
* First version released to the WordPress App Store
