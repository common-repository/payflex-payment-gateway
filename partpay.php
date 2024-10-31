<?php
/*
 * Plugin Name: Payflex Payment Gateway
 * Description: Use Payflex as a credit card processor for WooCommerce.
 * Version: 2.6.3
 * Author: Payflex
 * WC requires at least: 6.0
 * WC tested up to: 9.3.3
*/


/**
 * Check if WooCommerce is activated
 */

if (!function_exists('is_plugin_active') || !function_exists('is_plugin_active_for_network'))
{
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}
$woocommerce_active = is_plugin_active('woocommerce/woocommerce.php') || is_plugin_active_for_network('woocommerce/woocommerce.php');
if (!$woocommerce_active) return;

function woocommerce_add_payflex_gateway($methods)
{
    $methods[] = 'WC_Gateway_PartPay';
    return $methods;
}

add_action('plugins_loaded', function(){
    // Base plugin url
    define('PAYFLEX_PLUGIN_URL', plugin_dir_url(__FILE__));
    // Base plugin directory
    define('PAYFLEX_PLUGIN_DIR', plugin_dir_path(__FILE__));

    require_once( plugin_basename( 'includes/class-wc-gateway-payflex.php' ) );

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_payflex_gateway');
}, 0);


/**
 * Check for the CANCELLED payment status
 * We have to do this before the gateway initialises because WC clears the cart before initialising the gateway
 *
 * @since 1.0.0
 */

add_action('template_redirect', function()
{
    // Check if the payment was cancelled
    if (isset($_GET['status']) && $_GET['status'] == "cancelled" && isset($_GET['key']) && isset($_GET['token']))
    {

        if(isset($gateway) && $gateway instanceof WC_Gateway_PartPay)
        {
            $gateway = WC_Gateway_PartPay::instance();
        }
        else
        {
            $gateway = new WC_Gateway_PartPay();
        }
        
        $key = sanitize_text_field($_GET['key']);
        $order_id = wc_get_order_id_by_order_key($key);

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        if ($order)
        {

            $partpay_order_id = $order->get_meta('_partpay_order_id');

            # Get the order id from the post meta if it's not found in the order meta, this is for legacy orders
            if(!$partpay_order_id)
                $partpay_order_id = get_post_meta($order_id, '_partpay_order_id', true);

            $obj = new WC_Gateway_PartPay();
            $ordUrl = $obj->getOrderUrl();
            $response = wp_remote_get($ordUrl . '/' . $partpay_order_id, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $obj->get_partpay_authorization_code() ,
                )
            ));
            $body = json_decode(wp_remote_retrieve_body($response));
            if ($body->orderStatus != "Approved" OR $gateway->get_payflex_workflow_status($order_id) != 'abandoned')
            {
                $gateway->log('Order ' . $order_id . ' payment cancelled by the customer while on the Payflex checkout pages.');
                $order->add_order_note(__('Payment cancelled by the customer while on the Payflex checkout pages.', 'woo_payflex'));

                $gateway->set_payflex_workflow_status($order_id, 'abandoned');

                if (method_exists($order, "get_cancel_order_url_raw"))
                {
                    wp_redirect($order->get_cancel_order_url_raw());
                }
                else
                {
                    wp_redirect($order->get_cancel_order_url());
                }
                exit;
            }
            $redirect = $order->get_checkout_payment_url(true);

            return array(
                'result' => 'success',
                'redirect' => $redirect
            );
        }
    }
});


/**
 * Custom function to declare compatibility with cart_checkout_blocks feature 
*/
function declare_cart_checkout_blocks_compatibility() {
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');


/**
 * Add block based checkout support assets/payflex-block-checkout.js
 */

// add_action('enqueue_block_assets', function(){
//     wp_enqueue_script('payflex-block-checkout', PAYFLEX_PLUGIN_URL . 'assets/payflex-block-checkout.js', array('wp-blocks', 'wp-element', 'wp-editor'), filemtime(PAYFLEX_PLUGIN_DIR . 'assets/payflex-block-checkout.js'));
// });

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action( 'woocommerce_blocks_loaded', 'oawoo_register_order_approval_payment_method_type' );
/**
 * Custom function to register a payment method type
 */
function oawoo_register_order_approval_payment_method_type() {
    // Check if the required class exists
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }
    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'includes/class-payflex-woocommerce-block-checkout.php';
    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            // Register an instance of WC_payflex_Blocks
            $payment_method_registry->register( new WC_Payflex_Blocks );
        }
    );
}

/**
 * Call the cron task related methods in the gateway
 *
 * @since 1.0.0
 *
 */

add_action('payflex_do_cron_jobs', function (){

    # Make sure we're not running the cron job on the checkout page
    if(is_checkout()) return;

    $gateway = WC_Gateway_Partpay::instance();

    $gateway->check_pending_abandoned_orders();
    $gateway->update_payment_limits();
});

add_action('init', function ()
{
    # If cron "partpay_do_cron_jobs" still exists, delete it
    if(wp_next_scheduled('partpay_do_cron_jobs'))
        wp_clear_scheduled_hook('partpay_do_cron_jobs');

    # Check if the cron job is already scheduled
    if (wp_next_scheduled('payflex_do_cron_jobs')) return;

    # Make sure plugin is active
    if (!is_plugin_active('payflex-payment-gateway/partpay.php')) return;

    # Schedule the cron job
    wp_schedule_event(time() , 'twominutes', 'payflex_do_cron_jobs');
});

/* WP-Cron activation and schedule setup */

/**
 * Schedule Payflex WP-Cron job
 *
 * @since 1.0.0
 *
 */
function payflex_create_wpcronjob()
{
    $timestamp = wp_next_scheduled('payflex_do_cron_jobs');
    if ($timestamp == false)
    {
        wp_schedule_event(time() , 'twominutes', 'payflex_do_cron_jobs');
    }
}

register_activation_hook(__FILE__, 'payflex_create_wpcronjob');


/**
 * Delete PartPay WP-Cron job
 *
 * @since 1.0.0
 *
 */
function payflex_delete_wpcronjob()
{
    wp_clear_scheduled_hook('payflex_do_cron_jobs');
}

register_deactivation_hook(__FILE__, 'payflex_delete_wpcronjob');



/**
 * Add a new WP-Cron job scheduling interval of every 2 minutes
 *
 * @param  array $schedules
 * @return array Array of schedules with 2 minutes added
 * @since 1.0.0
 *
 */

add_filter('cron_schedules', function ($schedules)
{
    $schedules['twominutes'] = array(
        'interval' => 120, // seconds
        'display'  => __('Every 2 minutes', 'woo_payflex')
    );
    return $schedules;
});



// FUNCTION - Frontend show on single product page
function widget_content()
{
    $payflex_settings = get_option('woocommerce_payflex_settings');
    if($payflex_settings['enable_product_widget'] == 'yes'){
        echo woo_payflex_frontend_widget();
    }
}
global $wp_version;
if($wp_version >= 6.3){
    add_action('woocommerce_before_add_to_cart_form', 'widget_content', 0);
}else{
    add_action('woocommerce_single_product_summary', 'widget_content', 12);
}


function widget_shortcode_content()
{
    $payflex_settings = get_option('woocommerce_payflex_settings');
    if($payflex_settings['is_using_page_builder'] == 'yes'){
        return woo_payflex_frontend_widget();
    }
}

add_shortcode('payflex_widget', 'widget_shortcode_content');


function woo_payflex_frontend_widget($amount = false)
{
    global $product;
    if(!$product) return;

    $payflex_settings = get_option('woocommerce_payflex_settings');

    if ($product->get_type() === 'subscription') return;

    if(!$amount){

        $amount = wc_get_price_including_tax($product);
    }
    $amount_string = '&amount='.$amount;

    # Defaults
    $all_options        = '';
    $merchant_reference = false;
    $theme              = '';
    $widget_style       = '';
    $pay_type           = '';

    if(isset($payflex_settings['widget_style']) AND $payflex_settings['widget_style'])
        $widget_style = '&logo_type='.$payflex_settings['widget_style'];

    if(isset($payflex_settings['widget_theme']) AND $payflex_settings['widget_theme'])
        $theme = '&theme='.$payflex_settings['widget_theme'];

    if(isset($payflex_settings['pay_type']) AND $payflex_settings['pay_type'])
        $pay_type = '&pay_type='.$payflex_settings['pay_type'];

    if(isset($payflex_settings['merchant_widget_reference']) AND $payflex_settings['merchant_widget_reference'])
        # Make sure the merchant reference is set and is url freindly
        $merchant_reference = preg_replace('/[^a-zA-Z0-9_]/', '', $payflex_settings['merchant_widget_reference']);

    $all_options = $amount_string.$widget_style.$theme.$pay_type;
    
    if($merchant_reference){
        return '<script async src="https://widgets.payflex.co.za/'.$merchant_reference.'/payflex-widget-2.0.0.js?type=calculator'.$all_options.'" type="application/javascript"></script>';
    }
    return '<script async src="https://widgets.payflex.co.za/payflex-widget-2.0.0.js?type=calculator'.$all_options.'" type="application/javascript"></script>';
}


function woo_payflex_frontend_widget_old()
{		
    // Early exit if frontend is disabled in settings:
    $payflex_settings = get_option('woocommerce_payflex_settings');
    $payflex_frontend = $payflex_settings['enable_product_widget'];
    $payflex_frontend_page_builder = $payflex_settings['is_using_page_builder'];
    if ($payflex_frontend == 'no' && $payflex_frontend_page_builder == 'no'){ return; }   
    global $product;
    if(!$product){ return; }
    // Early exit if product is a WooCommerce Subscription type product: This throws a linting error, but it is correct.
    if ($product->get_type() === 'subscription'){
        return;
    }
    // Early exit if product has no price:
    $noprice =  wc_get_price_including_tax($product);
    if (!$noprice){  return; }
    // $payflexprice = wc_get_price_including_tax($product);
    // $payflexnowprice = wc_get_price_including_tax( $product ); 
    //Variable product data saved for updating amount when selection is made
    $variations_data = [];
    if ($product->is_type('variable')) {
        foreach ($product->get_available_variations() as $variation) {
            $varprice = ($variation['display_price'] * 100 / 4) / 100;
            $variations_data[$variation['variation_id']]['amount'] = $variation['display_price'];
            $variations_data[$variation['variation_id']]['installment'] = $varprice;
        }
    }
    ?>
    <script>
        jQuery(function($) {
            var product_type = '<?php echo $product->is_type('variable');?>';
            if(product_type == ''){
                var installmentValule = getInstallmentAmount(<?php echo $noprice;?>);
                textBasedOnAmount('<?php echo $noprice;?>',installmentValule); 
            }
            $('.partPayCalculatorWidget1').on('click', function(ev) {
                    ev.preventDefault();
                    var $body = $('body');
                    var $dialog = $('#partPayCalculatorWidgetDialog').show();
                    $body.addClass("partPayWidgetDialogVisible");
                    var $button = $dialog
                        .find("#partPayCalculatorWidgetDialogClose")
                        .on('click', function(e) {
                            e.preventDefault();
                            $dialog.hide();
                            $body.removeClass("partPayWidgetDialogVisible");
                            // Put back on the widget.
                            $('#partPayCalculatorWidgetDialog').append($dialog);
                        });
                    // Move to the body element.
                    $body.append($dialog);
                    $body.animate({ scrollTop: 0 }, 'fast');
            });
            var jsonData = <?php echo json_encode($variations_data); ?> ,
            inputVID = 'input.variation_id';
            $('input.variation_id').change(function() {  
                if ('' != $(inputVID).val()) {
                    var vid = $(inputVID).val(),
                        installmentPayflex = '',
                        amountPayflex = '';
                    $.each(jsonData, function(index, data) {
                        if (index == vid) {
                            installmentPayflex = data['installment'];
                            amountPayflex = data['amount'];
                        }
                    });
                    
                    textBasedOnAmount(amountPayflex,installmentPayflex);
                }
            });
            function getInstallmentAmount(value) {  
                value = + value;
                if(isNaN(value) || value < 0 ) {
                    return 0;
                }
                var result = Math.floor(value * 100 / 4) / 100;
                return endsWithZeroCents(result) ? result.toFixed(0) : result.toFixed(2);
            }
            function textBasedOnAmount(amount,installmentAmount) {
                var rangeMin = <?php echo $payflex_settings['partpay-amount-minimum'];?>,
                    rangeMax = <?php echo $payflex_settings['partpay-amount-maximum'];?>;
                if(rangeMin < 10 || rangeMax < 25 || rangeMax > 2000001 ) {  
                    rangeMin = 50;
                    rangeMax = 20000;
                } else if (rangeMax < rangeMin) {
                    var x = rangeMax;
                    rangeMax = rangeMin;
                    rangeMin = x;
                }
                var installmentAmount = getInstallmentAmount(amount);
                var html = '';
                if (amount > 10000) {
                    // if heavy basket
                    html = '';
                    $('.paypercentage').html('');
                    $('#heavybasketnote').html('* Higher first payment may apply on large purchases')
                    $('.heavyBasketText').html("Payflex lets you get what you need now, but pay for it over four interest-free instalments. " +
                        "You pay a larger amount upfront with the remainder spread across three payments over the following six weeks.");
                }else{
                    $('#heavybasketnote').html('');
                }
                if (amount < rangeMin) {
                    html = 'of 25% on orders over <br> R' + rangeMin;
                } else if (amount > rangeMax) {
                    html = 'of 25% on orders  R' + rangeMin + ' - R' + rangeMax;
                } else if(amount < 10001) {
                    html = 'of <span>R' + installmentAmount + '</span>';
                }
                $('.partPayCalculatorWidgetTextFromCopy').html(html);
            }
            function endsWithZeroCents(value) {
                value = Number(value); 
                var fixed = value.toFixed(2);
                var endsWith = fixed.lastIndexOf(".00") != -1;
                return endsWith ;
            }
        });   
    </script>
    <?php
    
    $css = '/* Widget */ @font-face { font-family: \'Montserrat\'; font-style: normal; font-weight: 400; src: local(\'Montserrat Regular\'), local(\'Montserrat-Regular\'), url(https://fonts.gstatic.com/s/montserrat/v13/JTUSjIg1_i6t8kCHKm459Wlhzg.ttf) format(\'truetype\'); } @font-face { font-family: \'Montserrat\'; font-style: normal; font-weight: 500; src: local(\'Montserrat Medium\'), local(\'Montserrat-Medium\'), url(https://fonts.gstatic.com/s/montserrat/v13/JTURjIg1_i6t8kCHKm45_ZpC3gnD-w.ttf) format(\'truetype\'); } @font-face { font-family: \'Montserrat\'; font-style: normal; font-weight: 700; src: local(\'Montserrat Bold\'), local(\'Montserrat-Bold\'), url(https://fonts.gstatic.com/s/montserrat/v13/JTURjIg1_i6t8kCHKm45_dJE3gnD-w.ttf) format(\'truetype\'); } body.partPayWidgetDialogVisible { overflow: hidden; } .partPayCalculatorWidgetDialogHeadingLogo {padding:10px} .partPayCalculatorWidget1 { margin: 0; padding: 2px; background-color: #FFFFFF; /*background-image: url(\'Payflex_Widget_BG.png\');*/ color: #002751;; cursor: pointer; text-transform: none; -webkit-border-radius: 10px; -moz-border-radius: 10px; border-radius: 10px; position: relative; margin-bottom: 10px} .partPayCalculatorWidgetDialogHeadingLogo img{ background-color : #c8a6fa;padding:10px;border-radius:100px} .partPayCalculatorWidget1 #freetext { font-weight: bold; color: #002751; } .partPayCalculatorWidget1 #partPayCalculatorWidgetLogo {width: 125px; top: 0;bottom: 0; margin: auto 0; right: 0; position: absolute; background-color: transparent; } .partPayCalculatorWidget1 #partPayCalculatorWidgetText { font-size: 15px; width: 60%; position: relative; top: 0; bottom: 0; margin: auto 0px } .partPayCalculatorWidget1 #partPayCalculatorWidgetText .partPayCalculatorWidgetTextFromCopy > span { font-weight: bold; } .partPayCalculatorWidget1 #partPayCalculatorWidgetText #partPayCalculatorWidgetLearn { text-decoration: underline; font-size: 12px; font-style: normal; color: #0086EF; } .partPayCalculatorWidget1 #partPayCalculatorWidgetText #partPayCalculatorWidgetSlogen { font-size: 12px; font-style: normal; } #partPayCalculatorWidgetDialog { box-sizing: border-box; } #partPayCalculatorWidgetDialog *, #partPayCalculatorWidgetDialog *:before, #partPayCalculatorWidgetDialog *:after { box-sizing: inherit; } #partPayCalculatorWidgetDialog { z-index: 999999; font-family: \'Arial\', \'Helvetica\'; font-size: 14px; display: none; color: #002751; position: fixed; bottom: 0; left: 0; right: 0; top: 0; } .partPayCalculatorWidgetDialogOuter { background-color: rgba(0, 0, 0, 0.2); height: 100%; left: 0; position: absolute; text-align: center; top: 0; vertical-align: middle; width: 100%; z-index: 999999; overflow-x: hidden; overflow-y: auto; } .partPayCalculatorWidgetDialogInner { background-color: white; border: solid 1px rgba(0, 0, 0, 0.2); -webkit-box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5); box-shadow: 0 5px 15px rgba(0, 0, 0, 0.5); max-width: 900px; margin: auto; position: relative; font-family: "Montserrat", sans-serif; margin: 30px auto; -webkit-border-radius: 30px; -moz-border-radius: 30px; border-radius: 30px; } .partPayCalculatorWidgetDialogInner #partPayCalculatorWidgetDialogClose { position: absolute; margin-right: 8px; margin-top: 8px; cursor: pointer; max-height: 28px; max-width: 28px; right: 0; top: 0; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading { padding: 50px; display: flex; /*border-bottom: dotted 1px #CECFD1;*/ padding-bottom: 24px; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading .partPayCalculatorWidgetDialogHeadingLogo { /*width: 300px; height: 64px; max-width: 300px; max-height: 64px; padding-top: 10px; flex: 1;background-color:#c8a6fa;border-radius:100px;  */} .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading .partPayCalculatorWidgetDialogHeadingLogo img { /*max-width: 300px; max-height: 64px;*/   display: inline-block; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading .partPayCalculatorWidgetDialogHeadingTitle { font-size: 32px; text-align: left; flex: 1; margin-left: 2rem; font-style: italic; color: #002751; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorksTitle { padding: 10px 50px 0 50px; font-size: 28px; padding-top: 20px; text-align: left; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorksDesc { padding: 10px 50px 0 50px; font-size: 17px; text-align: left; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorks { display: flex; justify-content: space-between; padding: 30px 50px 0 50px; } @media (max-width: 768px) { .partPayCalculatorWidget1{min-height:100px; } #partPayCalculatorWidgetLogo{float:left !important;top:0 !important;margin-top:8px;padding-bottom:15px;} .partPayCalculatorWidgetDialogHeadingLogo{padding:0}  #partPayCalculatorWidgetText {clear:both} .partPayCalculatorWidget1{width:100%} .partPayCalculatorWidgetDialogHeadingTitle{margin-top:40px;} .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorks { flex-direction: column; } } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorks .partPayCalculatorWidgetDialogHowItWorksBody { flex: 1; display: block; padding-top: 20px; /*padding-bottom: 20px;*/ } .nuumberingarea{margin:0 0 10px;padding:0;float:left;width:100%;text-align:center}.nuumberingarea strong{margin:0;padding:15px 0;width:100px;float:none;display:inline-block;border:none;border-radius:100%;font-size:50px;font-weight:700;color:#002751;background-color:#c8a6fa}.partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorks .partPayCalculatorWidgetDialogHowItWorksBody div img { max-width: 120px; max-height: 120px; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHowItWorks .partPayCalculatorWidgetDialogHowItWorksBody p { display: block; font-size: 15px; padding: 5px 15px; color: #002751; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter { /*background-color: #E5E5E6;*/ margin-top: 20px; border-top: solid 2px #002751; padding-bottom: 25px; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterTitle { width: 100%; font-size: 15px; font-weight: 500; padding: 10px 0 0 15px; text-align: left; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterBody { display: block; padding-top: 20px; /* padding-left: 50px; padding-right: 50px;*/ } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterBody > ul { padding: 0; width: 100%; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterBody > ul > li { display: inline-block; padding: 0 34px 0 30px; margin-left: 3px; margin-bottom: 13px; text-align: left; list-style: none; background-repeat: no-repeat; background-image: url(https://widgets.payflex.co.za/assets/tick.png); background-position: left center; background-size : 25px } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterLinks { padding-top: 10px; font-size: 15px; font-style: italic; color: #002751; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterLinks a { text-decoration: underline; color: #002751; } @media only screen and (max-width: 915px) { .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading { display: block; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading .partPayCalculatorWidgetDialogHeadingLogo { padding-top: 0; width: 100%; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading .partPayCalculatorWidgetDialogHeadingLogo img { max-width: 100%; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogHeading .partPayCalculatorWidgetDialogHeadingTitle { margin-left: 0; font-size: 24px; } } @media only screen and (max-width: 710px) { .partPayCalculatorWidgetDialogInner { max-width: 350px; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter { display: block; width: 100%; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterBody > ul { width: 100%; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterBody > ul > li { display: block; } .partPayCalculatorWidgetDialogInner .partPayCalculatorWidgetDialogFooter .partPayCalculatorWidgetDialogFooterLinks { padding-top: 10px; font-size: 10px; } } /* iPhone 5 in portrait & landscape: */ /* iPhone 5 in portrait: */ /* iPhone 5 in landscape: */ /* Explicit */';
    echo '<style type="text/css">' . $css . '</style>';
    return '<div class="partPayCalculatorWidget1"><div id="partPayCalculatorWidgetText">Or split into 4x <span id="freetext">interest-free</span> payments  <span class="partPayCalculatorWidgetTextFromCopy"></span> <span id="partPayCalculatorWidgetLearn">Learn&nbsp;more</span></div><img id="partPayCalculatorWidgetLogo" src="https://widgets.payflex.co.za/assets/partpay_new.png"><div id="partPayCalculatorWidgetDialog" role="dialog"><div class="partPayCalculatorWidgetDialogOuter"><div class="partPayCalculatorWidgetDialogInner"><img id="partPayCalculatorWidgetDialogClose" src="https://widgets.payflex.co.za/assets/cancel-icon.png" alt="Close"><div class="partPayCalculatorWidgetDialogHeading"><div class="partPayCalculatorWidgetDialogHeadingLogo"><img src="https://widgets.payflex.co.za/assets/Payflex_purple.png"></div><div class="partPayCalculatorWidgetDialogHeadingTitle"><div>No interest, no fees,</div><div>4x instalments over 6 weeks</div></div></div><div class="partPayCalculatorWidgetDialogHowItWorksTitle">How it works</div><div class="partPayCalculatorWidgetDialogHowItWorksDesc"><span class="heavyBasketText">Payflex lets you get what you need now, but pay for it over four interest-free instalments. You pay 25% upfront, then three payments of 25% over the following six weeks.</div></span><div class="partPayCalculatorWidgetDialogHowItWorks"><div class="partPayCalculatorWidgetDialogHowItWorksBody"><div><div class="nuumberingarea"><strong>1</strong></div></div><p>Shop Online<br>and fill your cart</p></div><div class="partPayCalculatorWidgetDialogHowItWorksBody"><div><div class="nuumberingarea"><strong>2</strong></div></div><p>Choose Payflex at checkout</p></div><div class="partPayCalculatorWidgetDialogHowItWorksBody"><div><div class="nuumberingarea"><strong>3</strong></div></div><p>Get approved and <br> pay <span class="paypercentage">25% </span>today <br> with your debit <br> or credit card </p><div id="heavybasketnote" style="font-size:13px"></div></div><div class="partPayCalculatorWidgetDialogHowItWorksBody"><div><div class="nuumberingarea"><strong>4</strong></div></div><p>Pay the remainder <br> over 6-weeks.<br> No interest. <br> No fees.</p></div></div><br style="border-bottom: dotted 1px #CECFD1"><div class="partPayCalculatorWidgetDialogFooter"><div class="partPayCalculatorWidgetDialogFooterBody"><ul><li>You must be over<br>18 years old</li><li>You must have a valid<br>South African ID</li><li>You must have a debit or credit card<br>issued by Mastercard, Visa or Amex </li></ul></div><div class="partPayCalculatorWidgetDialogFooterLinks">Still want more information? <a href="http://www.payflex.co.za/#howitworks/" target="_blank">Click here</a></div></div></div></div></div></div>';
}


// Register support page. This needs to be outside the class otherwise it won't be called soon enough
add_action('admin_menu', ['WC_Gateway_PartPay', 'register_support_page']);




// Payflex JS payflexBlockVars
function payflex_block_vars() {
    $payflex_block_vars = [
        'pluginUrl' => PAYFLEX_PLUGIN_URL,
        'payflex_widget' => woo_payflex_frontend_widget(),
    ];
    wp_localize_script('payflex-widget-block', 'payflexBlockVars', $payflex_block_vars);
}
add_action('enqueue_block_editor_assets', 'payflex_block_vars');



// Register the block
function register_payflex_widget_block() {
    wp_register_script(
        'payflex-widget-block',
        plugins_url('assets/block.js', __FILE__),
        array('wp-blocks', 'wp-element', 'wp-editor'),
        filemtime(plugin_dir_path(__FILE__) . 'assets/block.js')
    );

    register_block_type('payflex/widget', array(
        'editor_script' => 'payflex-widget-block',
        'render_callback' => 'render_payflex_widget_block',
    ));
}
add_action('init', 'register_payflex_widget_block');

// Render the block
function render_payflex_widget_block($attributes) {
    ob_start();
    // If were in the page builder, just show an image, if were rendering the block on the front end, show the widget
    if (is_admin()) {
        echo '<img src="' . plugins_url('assets/widget-icon.png', __FILE__) . '" alt="Payflex Widget" />';
    } else {
        echo woo_payflex_frontend_widget();
    }
    return ob_get_clean();
}


// Variation price update
add_action( 'woocommerce_after_single_product', 'payflex_update_price_on_variation' );
function payflex_update_price_on_variation() {
    global $product;

    if(!$product){ return; }
    if ($product->is_type('variable')) {
        ?>
        <script>
            // We need to get the price from ".woocommerce-variation-price .price" after the dropdown has changed it, not before
            jQuery('input.variation_id').change(function() {
                var price = jQuery('.woocommerce-variation-price .price').text();

                // Remove anything that's not a number, this is to prevent any issues with currency symbols or commas
                price = price.replace(/[^0-9]/g, '');

                // Re-add cent separator using a dot 2 spaces from the end.
                price = price.slice(0, -2) + '.' + price.slice(-2);

                // Make sure we have a valid whole number above 0
                if(isNaN(price) || price < 0 ) return;

                // Make sure PayflexWidget is defined
                if(typeof PayflexWidget !== 'undefined')
                {     
                    PayflexWidget.update(price);
                }
            });
        </script>
        <?php
    }
}

/**
 * All functions related to Woocommerce orders are handled through Woocommerce hooks, meaning we support Woocommerces HPOS and Custom Order Tables.
 * We just need to declare compatibility with the custom order tables feature.
 */
add_action('before_woocommerce_init', function(){
    if ( !class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) return;

    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
});