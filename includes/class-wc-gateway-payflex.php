<?php if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Payflex Payment Gateway
 * 
 * Creates the Payflex Payment Gateway for WooCommerce.
 */
class WC_Gateway_PartPay extends WC_Payment_Gateway
{
    protected array  $environments = [];
    protected string $configurationUrl = '';
    protected string $orderurl = '';

    private $version = '2.6.3';

    /**
        * @var $_instance WC_Gateway_PartPay The reference to the singleton instance of this class
        */
    private static $_instance = NULL;

    /**
        * @var boolean Whether or not logging is enabled
        */
    public static $log_enabled = false;

    /**
        * @var WC_Logger Logger instance
        */
    public static $log = false;
    
    public $random_id = false;

    public $base_plugin_url = '';

    public $base_plugin_dir = '';

    /**
        * @var WC_Order Order instance
        */
    public $WC_Order = false;

    /**
        * @var WC_Order Order ID
        */
    public $WC_Order_ID = false;

    public $current_order_proccessed = false;

    public $payflex_worfklow_status = false;

    /**
        * Main WC_Gateway_PartPay Instance
        *
        * Used for WP-Cron jobs when
        *
        * @since 1.0
        * @return WC_Gateway_PartPay Main instance
        */
    public static function instance()
    {
        if (is_null(self::$_instance))
        {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public function __construct()
    {
        # Generate random ID for instance
        $this->random_id = uniqid();

        # Base plugin URL for assets, this is the root url to the plugin directory
        $this->base_plugin_url = (defined('PAYFLEX_PLUGIN_URL')) ? PAYFLEX_PLUGIN_URL : plugin_dir_url(__FILE__.'/../');

        # Base plugin directory for assets, this is the root directory to the plugin directory
        $this->base_plugin_dir = (defined('PAYFLEX_PLUGIN_DIR')) ? PAYFLEX_PLUGIN_DIR : plugin_dir_path(__FILE__.'/../');

        $this->id = 'payflex';
        $this->method_title = __('Payflex', 'woo_payflex');
        $this->method_description = __('Use Payflex as a credit card processor for WooCommerce.', 'woo_payflex');
        $this->icon = $this->plugin_url('Checkout.png');

        $this->supports = array(
            'products',
            'refunds'
        );

        // Load the form fields.
        $this->init_environment_config();

        // Load the form fields.
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        // Load the frontend scripts.
        $this->init_scripts_js();
        $this->init_scripts_css();
        $settings = get_option('woocommerce_payflex_settings');
        $api_url = '';
        $this->configurationUrl = '';
        if (false !== $settings)
        {
            $api_url = $this->environments[$this->settings['testmode']]['api_url'];
            $this->orderurl = $api_url . '/order';
            $this->configurationUrl = $api_url . '/configuration';
        }
        else
        {
            $api_url = '';
        }
        // Define user set variables
        $this->title = '';
        if (isset($this->settings['title']))
        {
            $this->title = $this->settings['title'];
        }
        $this->description = __('Pay for your order in either 4 interest-free payments over 6 weeks OR 3 interest-free payments over 3 paydays.', 'woo_payflex');

        self::$log_enabled = true;

        // Hooks
        add_action('woocommerce_receipt_' . $this->id, array(
            $this,
            'receipt_page'
        ));

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
            $this,
            'process_admin_options'
        ));
        
        // Update function on save settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(
            $this,
            'on_save_settings'
        ));

        //add_filter( 'woocommerce_thankyou_order_id',array($this,'payment_callback'));
        add_action('woocommerce_order_status_refunded', array(
            $this,
            'create_refund'
        ));

        # Log that the class was instantiated and by what hook
        // $this->log('Payflex Class instantiated by ' . current_filter());

        // Don't enable PartPay if the amount limits are not met
        add_filter('woocommerce_available_payment_gateways', array(
            $this,
            'check_cart_within_limits'
        ) , 99, 1);

        add_action('woocommerce_settings_start', array(
            $this,
            'update_payment_limits'
        ));

        // Payment listener/API hook
        add_action('woocommerce_api_wc_gateway_partpay', array(
            $this,
            'payment_callback'
        ));

    }
    public function getOrderUrl(){
        return $this->orderurl;
    }

    /**
     * Get the plugin root URL
     *
     * @param  string $path  The file location and name relative to the plugin root
     * @return string
     */
    public function plugin_url($path = '')
    {
        return $this->base_plugin_url . ltrim($path, '/');
    }
    
    /**
     * Get the plugin root directory
     *
     * @param  string $path The file location and name relative to the plugin root
     * @return string
     */
    public function plugin_dir($path = '')
    {
        return $this->base_plugin_dir . ltrim($path, '/');
    }


    /**
        * Initialise Gateway Settings Form Fields
        *
        * @since 1.0.0
        */
    public function init_form_fields()
    {

        $env_values = array();
        foreach ($this->environments as $key => $item)
        {
            $env_values[$key] = $item["name"];
        }

        $widget_types = [
            'purple' => 'Purple',
            'navy'   => 'Navy',
        ];

        $widget_themes =[ 
            ''     => 'Default',
            'dark' => 'Dark',
        ];
        $pay_type = [
            '4' => 'Pay in 4',
            '3' => 'Pay in 3'
        ];

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'woo_payflex') ,
                'type' => 'checkbox',
                'label' => __('Enable Payflex', 'woo_payflex') ,
                'default' => 'yes'
            ) ,
            'title' => array(
                'title' => __('Title', 'woo_payflex') ,
                'type' => 'text',
                'description' => __('This controls the payment method title which the user sees during checkout.', 'woo_payflex') ,
                'default' => __('Payflex', 'woo_payflex')
            ) ,
            'testmode' => array(
                'title' => __('Test mode', 'woo_payflex') ,
                'label' => __('Enable Test mode', 'woo_payflex') ,
                'type' => 'select',
                'options' => $env_values,
                'description' => __('Process transactions in Test/Sandbox mode. No transactions will actually take place.', 'woo_payflex') ,
            ) ,
            'client_id' => array(
                'title' => __('Client ID', 'woo_payflex') ,
                'type' => 'text',
                'description' => __('Payflex Client ID credential', 'woo_payflex') ,
                'default' => __('', 'woo_payflex')
            ) ,
            'client_secret' => array(
                'title' => __('Client Secret', 'woo_payflex') ,
                'type' => 'text',
                'description' => __('Payflex Client Secret credential', 'woo_payflex') ,
                'default' => __('', 'woo_payflex')
            ) ,
            'widget_style' => array(
                'title'       => __('Widget Style', 'woo_payflex') ,
                'type'        => 'select',
                'options'     => $widget_types,
                'description' => __('Select the widget style to use on the product page.', 'woo_payflex') ,
                'default'     => 'purple'
            ) ,
            'widget_theme' => array(
                'title'       => __('Widget Theme', 'woo_payflex') ,
                'type'        => 'select',
                'options'     => $widget_themes,
                'description' => __('Select the widget theme', 'woo_payflex') ,
                'default'     => ''
            ) ,
            'pay_type' => array(
                'title'       => __('Pay Months', 'woo_payflex') ,
                'type'        => 'select',
                'options'     => $pay_type,
                'description' => __('Select the number of months to pay.', 'woo_payflex') ,
                'default'     => '4'
            ) ,

            'enable_product_widget' => array(
                'title' => __('Product Page Widget', 'woo_payflex') ,
                'type' => 'checkbox',
                'label' => __('Enable Product Page Widget', 'woo_payflex') ,
                'default' => 'yes',

            ),
            'is_using_page_builder' => array(
                'title' => __('Product Page Widget using any page builder', 'woo_payflex') ,
                'type' => 'checkbox',
                'label' => __('Enable Product Page Widget using page builder', 'woo_payflex') ,
                'default' => 'no',
                'description' => __('<h3 class="wc-settings-sub-title">Page Builders</h3> If you use a page builder plugin, the above payment info can be placed using a shortcode instead of relying on hooks. Use [payflex_widget] within a product page.', 'woo_payflex')

            ) ,
            'enable_checkout_widget' => array(
                'title' => __('Checkout Page Widget', 'woo_payflex') ,
                'type' => 'checkbox',
                'label' => __('Enable Checkout Page Widget', 'woo_payflex') ,
                'default' => 'yes'
            ) ,
            'merchant_widget_reference' => array(
                'title' => __('Widget Reference', 'woo_payflex') ,
                'type' => 'text',
                'label' => __('Widget Reference', 'woo_payflex') ,
                'default' => __('', 'woo_payflex'),
                'description' => __('This is the reference that will be used to identify the widget on Payflex.', 'woo_payflex')
            ) ,
            // 'enable_order_notes' => array(
            //     'title' => __('Order Page Notes', 'woo_payflex') ,
            //     'type' => 'checkbox',
            //     'label' => __('Enable Order Detail Page Notes', 'woo_payflex') ,
            //     'default' => 'no'
            // )
        );
    } // End init_form_fields()
    
    /**
        * Init JS Scripts Options
        *
        * @since 1.2.1
        */
    public function init_scripts_js()
    {
        //use WP native jQuery
        wp_enqueue_script("jquery");

    }

    /**
        * Init Scripts Options
        *
        * @since 1.2.1
        */
    public function init_scripts_css()
    {
    }

    /**
        * Init Environment Options
        *
        * @since 1.2.3
        */
    public function init_environment_config()
    {
        if (empty($this->environments))
        {
            //config separated for ease of editing
            require (__DIR__.'/../config/config.php');
            $this->environments = $environments;
        }
    }

    /**
        * Admin Panel Options
        *
        * @since 1.0.0
        */
    public function admin_options()
    {
    ?>
        <h3><?php esc_html_e('Payflex Gateway', 'woo_payflex'); ?></h3>

        <table class="form-table">
            <?php
        // Generate the HTML For the settings form.
        $this->generate_settings_html();


    ?>
        </table><!--/.form-table-->

        <?php

            $this->admin_info_block();
    } // End admin_options()
    
    public function on_save_settings()
    {
        $this->log('Settings Updated');
        # Reset the access token
        $this->get_partpay_authorization_code(true);

        # Update limits
        // $this->update_payment_limits();
    }

    public function admin_info_block()
    {
    ?>
        <p><a href="<?php echo admin_url('admin.php?page=payflex-support'); ?>"><?php esc_html_e('Support Information', 'woo_payflex'); ?></a></p>
    <?php
    }
    
    /**
     * Display payment options on the checkout page
     *
     * @since 1.0.0
     */
    public function payment_fields()
    {

        global $woocommerce;
        $settings = get_option('woocommerce_payflex_settings');
        if (isset($settings['enable_checkout_widget']) && $settings['enable_checkout_widget'] == 'yes')
        {
            echo '<style>.elementor{max-width:100% !important}';
            echo 'html {
                    -webkit-font-smoothing: antialiased!important;
                    -moz-osx-font-smoothing: grayscale!important;
                    -ms-font-smoothing: antialiased!important;
                }
                .md-stepper-horizontal {
                    display:table;
                    width:100%;
                    margin:0 auto;
                    background-color:transparent;
                }
                .md-stepper-horizontal .md-step {
                    display:table-cell;
                    position:relative;
                    padding:0;
                }
                .md-stepper-horizontal .md-step .md-step-circle {
                    width:30px;
                    height:30px;
                    margin:0 auto;
                    border-radius: 50%;
                    text-align: center;
                    line-height:30px;
                    font-size: 16px;
                    font-weight: 600;
                    color:#FFFFFF;
                }
                .md-stepper-horizontal .md-step .md-step-title {
                    margin-top:16px;
                    font-size:16px;
                    font-weight:600;
                }
                .md-stepper-horizontal .md-step .md-step-title,
                .md-stepper-horizontal .md-step .md-step-optional {
                    text-align: center;
                    color : #002751;
                }
                .md-stepper-horizontal .md-step .md-step-optional {
                    font-size:12px;
                }
                .payflex_description{
                    font-size:16px;text-align:center;
                    margin-top:17px;
                }
                .fontcolor{
                    color : #002751;
                }
                </style>';
            $ordertotal = $woocommerce
                ->cart->total;
            $installment = round(($ordertotal / 4) , 2);
            echo '<div class="fontcolor" style="font-size:16px;text-align:center">Four interest-free payments totalling R' . $ordertotal . '</div>';
            echo '<div class="md-stepper-horizontal orange">
                    <div class="md-step active">
                    <div class="md-step-title">R' . $installment . '</div>
                    <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-01.png') . '"></span></div>
                    <div class="md-step-optional">1st instalment</div>
                    </div>
                    <div class="md-step active">
                    <div class="md-step-title">R' . $installment . '</div>
                    <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-02.png') . '"></span></div>
                    <div class="md-step-optional">2 weeks later</div>
                    </div>
                    <div class="md-step active">
                    <div class="md-step-title">R' . $installment . '</div>
                    <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-03.png') . '"></span></div>
                    <div class="md-step-optional">4 weeks later</div>
                    </div>
                    <div class="md-step active">
                    <div class="md-step-title">R' . $installment . '</div>
                    <div class="md-step-circle"><span><img src ="' . $this->plugin_url('PIE-CHART-04.png') . '"></span></div>
                    <div class="md-step-optional">6 weeks later</div>
                    </div>
                </div>
            <div class="payflex_description fontcolor">You will be redirected to Payflex when you click on place order.</div>
            ';
        }
        else
        {
            if ($this->settings['testmode'] != 'production'): ?><?php esc_html_e('TEST MODE ENABLED', 'woo_payflex'); ?><?php
            endif;
            $arr = array(
                'br' => array() ,
                'p' => array()
            );
            if ($this->description)
            {
                echo wp_kses('<p>' . $this->description . '</p>', $arr);
            }
        }

    }

    /**
     * Register Payflex support page
     *
     * @return void
     */
    static function register_support_page() {
        add_submenu_page(
            'wc-settings',
            'Payflex Support',
            'Payflex Support',
            'manage_options',
            'payflex-support',
            'WC_Gateway_PartPay::payflex_support_page'
        );
    }


    /**
     * Simple support details and tools.
     *
     * @return void
     */
    static function payflex_support_page() {
        $WC                       = WC_Gateway_PartPay::instance();
        $check_php_version        = version_compare(PHP_VERSION, '8.1', '>=');
        $payflex_api_accessable   = ($WC->get_partpay_authorization_code() !== false);
        $payflex_order            = FALSE;
        $redirect_url             = FALSE;

        if(isset($_GET['redirect_url']))
        {
            $redirect_url = $returned_token = sanitize_url(urldecode($_GET['redirect_url']));

            // Validate redirect
            if(!filter_var($redirect_url, FILTER_VALIDATE_URL) OR parse_url($redirect_url, PHP_URL_HOST) !== $_SERVER['HTTP_HOST'])
            {
                $redirect_url = FALSE;
            }
            
        }
        
        // Get order details
        $payflex_order_id = FALSE;

        if(isset($_GET['payflex_order_id']) && !empty($_GET['payflex_order_id']))
        {
            $payflex_order_id = sanitize_text_field($_GET['payflex_order_id']);
            $payflex_order = $WC->payflex_remote_get_order($payflex_order_id);
        }

        // Force cron check
        $running_cron = FALSE;
        if(isset($_GET['force_cron']) && $_GET['force_cron'] == 'Force Check')
        {
            $running_cron = TRUE;
            $WC->check_pending_abandoned_orders(true);
        }

        // Update check
        $update_data      = wp_get_update_data();
        $outdated_plugins = $update_data['counts']['plugins'];

        // Plugin Count
        $plugin_count = count(get_option('active_plugins'));

        // API Token
        $api_token_date = '';

        if($WC->get_access_token_date())
        {
            $formatted_api_date = human_time_diff($WC->get_access_token_date(), time()) . ' ago';
            $api_token_date = '(Updated '.$formatted_api_date.')';
        }

        // Cron checks
        $cron_orders = $WC->get_pending_abandoned_orders();
        
        ?>
        <div class="wrap">
            <h1>Payflex Support</h1>
            <span>
            <?php if($redirect_url): ?>
                <a href="<?=$redirect_url?>">Back to Previous Page</a> | 
            <?php endif; ?>
            <a href="<?=admin_url('admin.php?page=wc-settings&tab=checkout&section=payflex')?>">Payflex Settings</a>
            </span>
            <div class="debug_table_wrapper">
            
                <table class="debug_table">
                    <tr>
                        <td colspan="2">
                            <h3>Support Information</h3>
                        </td>
                    </tr>
                    <tr>
                        <td>Payflex Plugin Version: </td>
                        <td>
                            <span class="payflex_debug_success">v<?=$WC->version?></span>
                        </td>
                    </tr>
                    <tr>
                        <td>PHP Version: </td>
                        <td>
                            <?php if($check_php_version): ?>
                                <span class="payflex_debug_success">PHP v<?=PHP_VERSION?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">PHP v<?=PHP_VERSION?> Isn't officially supported</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>WordPress Version: </td>
                        <td>
                            <?php if(version_compare(get_bloginfo('version'), '6.5', '>=')): ?>
                                <span class="payflex_debug_success">WordPress v<?=get_bloginfo('version')?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">WordPress v<?=get_bloginfo('version')?> Isn't officially supported</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>WooCommerce Version: </td>
                        <td>
                            <?php if(version_compare(WC()->version, '6.5', '>=')): ?>
                                <span class="payflex_debug_success">WooCommerce v<?=WC()->version?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">WooCommerce v<?=WC()->version?> Isn't officially supported</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Plugins:</td>
                        <td>
                            <span class="payflex_debug_success"><?=$plugin_count?> Active</span>
                            <?php if($outdated_plugins): ?>
                                <span class="payflex_debug_warning">(<?=$outdated_plugins?> Outdated)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td>Payflex Authentication:</td>
                        <td>
                            <?php if($payflex_api_accessable): ?>
                                <span class="payflex_debug_success">Successful <?=$api_token_date?></span>
                            <?php else:?>
                                <span class="payflex_debug_error">Authentication Error</span>
                            <?php endif; ?>

                            <div class="payflex_info_text">
                                When saving settings, we will attempt to authenticate with Payflex and cache the access token. <br>
                                The token is used for all API requests and will automatically refresh when it expires.
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td>Scheduled orders (CRON):</td>
                        <td>
                            <div class="payflex_two_column">
                                <div>
                                    <?=count($cron_orders['new'])?> Waiting order<?=(count($cron_orders['new']) !== 1 ? 's' : '')?>.<br/>
                                    <?=count($cron_orders['scheduled'])?> Order<?=(count($cron_orders['scheduled']) !== 1 ? 's' : '')?> currently in queue.
                                </div>
                                <div>
                                    <form method="get" action="<?=admin_url('admin.php');?>">
                                        <input type="hidden" name="redirect_url" value="<?=$redirect_url?>">
                                        <input type="hidden" name="page" value="payflex-support">
                                        <input type="submit" name="force_cron" value="Force Check">
                                    </form>
                                    <div>
                                        <?php if($running_cron): ?>
                                            <span class="payflex_debug_success cron_check_message">Checked Orders!</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>

                            <div class="payflex_info_text">
                                Any orders abandoned during the checkout process are added to a schedule. They always wait at least 30 minutes before running. <br>
                                After 30 minutes, they will be added to a schedule that checks every 2 minutes. After 2 hours, we stop checking.
                            </div>
                        </td>
                    </tr>
                    <tr class="no-border">
                        <td>Lookup Order ID</td>
                        <td>
                            <form method="get" action="<?=admin_url('admin.php');?>">
                                <input type="hidden" name="page" value="payflex-support">
                                <?php if($redirect_url): ?>
                                    <input type="hidden" name="redirect_url" value="<?=$redirect_url?>">
                                <?php endif; ?>
                                <input type="text" id="payflex_order_id" name="payflex_order_id" value="<?=$payflex_order_id?>" style="width:300px;" placeholder="Order ID">
                                <input type="submit" value="Lookup">
                            </form>
                            <?php if($payflex_order_id AND !$payflex_order): ?>
                                <span class="payflex_debug_error">Order ID not found</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            <?php if($payflex_order): ?>
                <div class="debug_table_wrapper">
                    <table class="debug_table">
                        <tr>
                            <td colspan="2">
                                <h3>Order Details</h3>
                            </td>
                        </tr>
                        <tr>
                            <td>Order Consumer</td>
                            <td><?=$payflex_order->consumer->givenNames?> <?=$payflex_order->consumer->surname?></td>
                        </tr>
                        <tr>
                            <?php
                                $class = 'payflex_debug_unknown';
                                if($payflex_order->orderStatus == 'Declined' OR $payflex_order->orderStatus == 'Abandoned') $class = 'payflex_debug_error';
                                if($payflex_order->orderStatus == 'Approved') $class = 'payflex_debug_success';
                            ?>
                            <td>Order Status</td>
                            <td>
                                <span class="<?=$class?>"><?=$payflex_order->orderStatus?></span>
                            </td>
                        </tr>
                        <tr>
                            <td>Order ID</td>
                            <td>
                                <?=$payflex_order->orderId?>
                            </td>
                        </tr>
                        <tr>
                            <td>Merchant Reference</td>
                            <td>
                                <?=$payflex_order->merchantReference?>
                            </td>
                        </tr>
                        <tr>
                            <td>Order Amount</td>
                            <td><?=$payflex_order->amount?></td>
                        </tr>
                        <tr>
                            <td>Order Date</td>
                            <?php $date = new DateTime($payflex_order->createdDateTime); ?>
                            <td> <?=$date->format('Y-m-d H:i:s')?></td>
                        </tr>
                        <tr class="no-border">
                            <td>Order Email</td>
                            <td><a href="mailto:<?=$payflex_order->consumer->email?>"><?=$payflex_order->consumer->email?></a></td>
                        </tr>
                    </table>
                </div>
            <?php endif; ?>

            <script>
                // Remove force_cron from URL, keeping all other parameters on page load
                window.onload = function(){
                    var url = new URL(window.location.href);
                    url.searchParams.delete('force_cron');
                    window.history.replaceState({}, document.title, url);

                    // Remove cron_check_message after 3 seconds
                    setTimeout(function(){
                        var elements = document.getElementsByClassName('cron_check_message');
                        for (var i = 0; i < elements.length; i++) {
                            elements[i].style.display = 'none';
                        }
                    }, 3000);
                }

            </script>
            <style>
            .debug_table_wrapper {
                margin-top: 20px;
                padding: 20px;
                background-color: #f9f9f9;
                border: 1px solid #e5e5e5;
                max-width: 1000px;
            }

            .debug_table {
                width: 100%;
            }

            .debug_table td {
                padding: 5px;
                border-bottom: 1px solid #e5e5e5;
            }
            .no-border td {
                border-bottom: none;
            }

            .debug_table td:first-child {
                font-weight: bold;
                vertical-align: top;
            }

            .debug_table td:first-child {
                font-weight: bold;
            }

            .payflex_debug_success {
                color: #2ecc71;
            }

            .payflex_debug_warning {
                color: #f1c40f;
            }

            .payflex_debug_error {
                color: #e74c3c;
            }

            .payflex_two_column{
                display: flex;
                justify-content: flex-start;
                gap: 40px;
            }
            .payflex_info_text{
                font-size: 12px;
                color: #777;
                padding-left: 10px;
            }
        </style>
        </div>
        <?php
    }


    /**
     * Request an order token from Partpay
     *
     * @return  string or boolean false if no token generated
     * @since 1.0.0
     */
    public function get_partpay_authorization_code($reset_token = false)
    {
        if($reset_token)
        {
            delete_transient('payflex_access_token');
            delete_transient('payflex_access_token_date');
        }

        $access_token      = get_transient('payflex_access_token');
        $access_token_date = get_transient('payflex_access_token_date');

        // $this->log('Access token from cache is ' . $access_token);
        // $this->log('Refreshed Payflex Access Token');

        if (false !== $access_token && !empty($access_token))
        {
            // $this->log('Returning cached access token ' . $access_token);
            return $access_token;
        }

        if (false === $this->apiKeysAvailable())
        {
            $this->log('No api keys available');
            return false;
        }
        
        $this->log('Getting new access token');
        $AuthURL = $this->environments[$this->settings['testmode']]['auth_url'];
        $AuthBody = ['client_id' => $this->settings['client_id'], 'client_secret' => $this->settings['client_secret'], 'audience' => $this->environments[$this->settings['testmode']]['auth_audience'], 'grant_type' => 'client_credentials'];
        $AuthBody = wp_json_encode($AuthBody);
        $headers = array(
            'Content-Type' => 'application/json'
        );
        $AuthBody = json_decode(json_encode($AuthBody) , true);

        $response = wp_remote_post($AuthURL, array(
            'body' => $AuthBody,
            'headers' => $headers
        ));
        $body = json_decode(wp_remote_retrieve_body($response) , true);
        if (!is_wp_error($response) && isset($response['response']['code']) && $response['response']['code'] != '401')
        {
            //store token in cache
            $accessToken = isset($body['access_token']) ? $body['access_token'] : '';
            $expireTime = isset($body['expires_in']) ? $body['expires_in'] : '';
            $this->log('Storing new token in cache ' . $accessToken . ' which is valid for ' . $expireTime . ' seconds');
            set_transient('payflex_access_token', $accessToken, ((int)$expireTime - 120));
            set_transient('payflex_access_token_date', time(), ((int)$expireTime - 120));
            return $accessToken;
        }
        else
        {
            return false;
        }
    }

    public function get_access_token_date()
    {
        return get_transient('payflex_access_token_date');
    }

    private function apiKeysAvailable()
    {

        if (empty($this->settings['client_id']) || empty($this->settings['client_secret']))
        {
            $this->log('API keys not available.');
            return false;
        }

        return true;
    }

    public function update_payment_limits()
    {
        // Get existing limits
        $settings = get_option('woocommerce_payflex_settings');

        if (false === $this->apiKeysAvailable())
        {
            return false;
        }

        // $this->log('Updating payment limits requested');
        if (!empty($this->configurationUrl))
        {
            $response = wp_remote_get($this->configurationUrl, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_partpay_authorization_code()
                )
            ));
            $body = json_decode(wp_remote_retrieve_body($response) , true);


            // $this->log('Updating payment limits response: ' . print_r($body, true));

            if (!is_wp_error($response) && isset($response['response']['code']) && $response['response']['code'] == 200)
            {
                $this->log('Updating payment limits');
                $settings['partpay-amount-minimum'] = isset($body['minimumAmount']) ? $body['minimumAmount'] : 0;
                $settings['partpay-amount-maximum'] = isset($body['maximumAmount']) ? $body['maximumAmount'] : 0;
            }

            update_option('woocommerce_payflex_settings', $settings);
        }
        $this->init_settings();

    }

    /**
     * Process the payment and return the result
     * - redirects the customer to the pay page
     *
     * @param int $order_id
     *
     * @since 1.0.0
     * @return array
     */
    public function process_payment($order_id)
    {

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        // Check if there's a custom order number
        $merchantRefe = $order_id;



        // Get the authorization token
        $access_token = $this->get_partpay_authorization_code();

        //Process here
        $orderitems = $order->get_items();
        $items = array();
        $i = 0;
        if (count($orderitems))
        {
            foreach ($orderitems as $item)
            {

                $i++;
                // get SKU
                if ($item['variation_id'])
                {

                    if (function_exists("wc_get_product"))
                    {
                        $product = wc_get_product($item['variation_id']);
                    }
                    else
                    {
                        $product = new WC_Product($item['variation_id']);
                    }
                }
                else
                {

                    if (function_exists("wc_get_product"))
                    {
                        $product = wc_get_product($item['product_id']);
                    }
                    else
                    {
                        $product = new WC_Product($item['product_id']);
                    }
                }

                if ($i == count($orderitems))
                {
                    $product = $items[] = array(

                        '{
                            "name":"' . esc_html($item['name']) . $i . '",
                            "sku":"' . $product->get_sku() . '",
                            "quantity":"' . $item['qty'] . '",
                            "price":"' . number_format(($item['line_subtotal'] / $item['qty']) , 2, '.', '') . '"
                        }'

                    );
                }
                else
                {
                    $product = $items[] = array(

                        '{
                            "name":"' . esc_html($item['name']) . $i . '",
                            "sku":"' . $product->get_sku() . '",
                            "quantity":"' . $item['qty'] . '",
                            "price":"' . number_format(($item['line_subtotal'] / $item['qty']) , 2, '.', '') . '"
                        }'

                    );
                }
            }
        }

        //calculate total shipping amount
        if (method_exists($order, 'get_shipping_total'))
        {
            //WC 3.0
            $shipping_total = $order->get_shipping_total();
        }
        else
        {
            //WC 2.6.x
            $shipping_total = $order->get_total_shipping();
        }
        $merchantRefe = $order_id;
        $plugin_check = trailingslashit(WP_PLUGIN_DIR) . 'wt-woocommerce-sequential-order-numbers/wt-advanced-order-number.php';
        if (in_array($plugin_check, wp_get_active_and_valid_plugins()) || (is_multisite() && in_array($plugin_check, wp_get_active_network_plugins()))){
            $merchantRefe = $order->get_order_number();
        }
        

        $OrderBodyObj = new stdClass;
        $OrderBodyObj->amount                 = number_format($order->get_total() , 2, '.', '');
        $OrderBodyObj->consumer               = new stdClass;
        $OrderBodyObj->consumer->phoneNumber  = (string)$order->get_billing_phone();
        $OrderBodyObj->consumer->givenNames   = (string)$order->get_billing_first_name();
        $OrderBodyObj->consumer->surname      = (string)$order->get_billing_last_name();
        $OrderBodyObj->consumer->email        = (string)$order->get_billing_email();
        $OrderBodyObj->billing                = new stdClass;
        $OrderBodyObj->billing->addressLine1  = (string)$order->get_billing_address_1();
        $OrderBodyObj->billing->addressLine2  = (string)$order->get_billing_address_2();
        $OrderBodyObj->billing->suburb        = (string)$order->get_billing_city();
        $OrderBodyObj->billing->postcode      = (string)$order->get_billing_postcode();
        $OrderBodyObj->shipping               = new stdClass;
        $OrderBodyObj->shipping->addressLine1 = (string)$order->get_shipping_address_1();
        $OrderBodyObj->shipping->addressLine2 = (string)$order->get_shipping_address_2();
        $OrderBodyObj->shipping->suburb       = (string)$order->get_shipping_city();
        $OrderBodyObj->shipping->postcode     = (string)$order->get_shipping_postcode();
        $OrderBodyObj->description            = 'string';
        $OrderBodyObj->items                  = [];
        $objectItems                          = [];
        foreach ($items as $item)
        {
            array_push($objectItems,json_decode($item[0]));
        }

        $OrderBodyObj->items                        = $objectItems;
        $OrderBodyObj->merchant                     = new stdClass;
        $OrderBodyObj->merchant->redirectConfirmUrl = (string)$this->get_return_url($order) . '&order_id=' . $order_id . '&status=confirmed&wc-api=WC_Gateway_PartPay';
        $OrderBodyObj->merchant->redirectCancelUrl  = (string)$this->get_return_url($order) . '&status=cancelled';
        $OrderBodyObj->merchantReference            = (string)$merchantRefe;
        $OrderBodyObj->taxAmount                    = $order->get_total_tax();
        $OrderBodyObj->shippingAmount               = $shipping_total;



        $platform_info_string = 'Wordpress '. get_bloginfo('version') . ', WooCommerce ' . WC()->version;

        $all_plugins    = get_plugins();
        $active_plugins = count(get_option('active_plugins'));
        $total_plugins  = count($all_plugins);

        $OrderBodyObj->merchantSystemInformation = new stdClass;
        $OrderBodyObj->merchantSystemInformation->plugin_version        = $this->version;
        $OrderBodyObj->merchantSystemInformation->php_version           = PHP_VERSION;
        $OrderBodyObj->merchantSystemInformation->ecommerce_platform    = $platform_info_string;
        $OrderBodyObj->merchantSystemInformation->total_plugin_modules  = (string)$total_plugins;
        $OrderBodyObj->merchantSystemInformation->active_plugin_modules = (string)$active_plugins;

        $APIURL = $this->orderurl . '/productSelect';


        $order_args = array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ) ,
            'body'    => json_encode($OrderBodyObj) ,
            'timeout' => 30
        );

        $order_response = wp_remote_post($APIURL, $order_args);

        // echo json_encode($order_response); die;

        if(is_wp_error($order_response))
        {
            if (defined('WP_DEBUG') && WP_DEBUG)
            {
                wc_add_notice(__('There was an issue connecting to Payflex servers. Please try again later.', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment. Please try again later.', 'woo_payflex') , 'error');
            }

            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        $order_body = json_decode(wp_remote_retrieve_body($order_response));

        if(!is_object($order_body))
        {
            if (defined('WP_DEBUG') && WP_DEBUG)
            {
                wc_add_notice(__('Payflex API return is not a valid object, API might be under maintenance or there was an undefined issue with the sent data', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment.', 'woo_payflex') , 'error');
            }

            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        if(!isset($order_body->redirectUrl) OR !isset($order_body->orderId) OR !isset($order_body->token))
        {
            if (defined('WP_DEBUG') && WP_DEBUG)
            {
                if(isset($order_body->message))
                {
                    wc_add_notice(__('Payflex payment error. Successfully connected to Payflex, but did not get back expected data.<br/>API Responded with: '.$order_body->message, 'woo_payflex') , 'error');
                    return array(
                        'result' => 'failure',
                        'redirect' => $order->get_checkout_payment_url(true)
                    );
                }

                if(isset($order_body->response) AND isset($order_body->response->message))
                {
                    wc_add_notice(__('Payflex payment error. Successfully connected to Payflex, but did not get back expected data.<br/>API Responded with: '.$order_body->message, 'woo_payflex') , 'error');
                    return array(
                        'result' => 'failure',
                        'redirect' => $order->get_checkout_payment_url(true)
                    );
                }

                wc_add_notice(__('Payflex API return response is not in expected format, Payflex is possibly under maintenance', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment.', 'woo_payflex') , 'error');
            }
            
            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        if ($access_token == false)
        {
            // Couldn't generate token
            if (defined('WP_DEBUG') && WP_DEBUG)
            {
                wc_add_notice(__('Payflex API Token appears to be invalid', 'woo_payflex') , 'error');
            }
            else
            {
                wc_add_notice(__('Sorry, there was a problem preparing your payment.', 'woo_payflex') , 'error');
            }
            $order->add_order_note(__('Unable to generate the order token. Payment couldn\'t proceed.', 'woo_payflex'));
            
            return array(
                'result' => 'failure',
                'redirect' => $order->get_checkout_payment_url(true)
            );
        }

        $this->log('Created Payflex OrderId: ' . print_r($order_body->orderId, true));

        # Use WC_Order method to save meta
        $order->update_meta_data('_partpay_order_token', $order_body->token);
        $order->update_meta_data('_partpay_order_id',    $order_body->orderId);
        $order->update_meta_data('_order_redirectURL',   $order_body->redirectUrl);

        # Adding Payflex meta fields for future compatibility
        $order->update_meta_data('_payflex_order_token', $order_body->token);
        $order->update_meta_data('_payflex_order_id',    $order_body->orderId);
        $order->save();

        $savedId = $order->get_meta('_partpay_order_id');

        # Order Link

        $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));
        $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $savedId.'&redirect_url='.$current_order_url);
        $order_id_url = '<a href="'.$admin_support_url.'" >' . $savedId . '</a> ';
        
        # Add order note
        if($this->get_payflex_workflow_status($order_id) !== 'initiated')
            $order->add_order_note(__('User attempted Payflex order.<br>Payflex order ID: ' . $order_id_url, 'woo_payflex'));

        $this->set_payflex_workflow_status($order_id, 'initiated');

        $this->log('Saved ' . $savedId . ' into post meta');

        $redirect = $order->get_checkout_payment_url(true);
        $this->log('Redirect URL ' . json_encode($order->get_payment_method()));
        return array(
            'result'   => 'success',
            'redirect' => $redirect
        );

    }

    /**
     * Update status after API redirect back to merchant site
     *
     * @since 1.0.0
     */
    public function receipt_page($order_id)
    {

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        $redirectURL = $order->get_meta('_order_redirectURL');

        //Update order status if it isn't already
        $is_pending = false;
        if (function_exists("has_status"))
        {
            $is_pending = $order->has_status('pending');
        }
        else
        {
            if ($order->get_status() == 'pending')
            {
                $is_pending = true;
            }
        }

        if (!$is_pending)
        {
            $order->update_status('pending');
        }

        $redirectURL_new = $redirectURL;

        if(isset($redirectURL[0]) && !empty($redirectURL[0])) $redirectURL_new = $redirectURL;

        $this->log('Partpay Checkout URL ' .$redirectURL_new);
        //Redirect to Partpay checkout
        header('Location: ' . $redirectURL_new);
    }

    /**
     * @param $order_id
     */
    public function payment_callback($order_id)
    {   
        // Make sure the order id is set
        if(!isset($_GET['order_id']) OR !isset($_GET['status']) OR !isset($_GET['token']))
        {
            $this->log('Invalid callback data received on payment callback');
            $this->payflex_redirect_failed();
        }

        $returned_order_id = sanitize_text_field($_GET['order_id']);
        $returned_status   = sanitize_text_field($_GET['status']);
        $returned_token    = sanitize_text_field($_GET['token']);

        if($this->current_order_proccessed === $returned_order_id)
        {
            $this->log('Order already processed, redirecting to payment page');
            $this->payflex_redirect_failed($order_id);
        }

        if(empty($returned_order_id) OR empty($returned_token))
        {
            $this->log('Invalid callback data received on payment callback');
            $this->payflex_redirect_failed($order_id);
        }

        // Get the order id, this is not to be trusted yet
        $order_id = $returned_order_id;

        // Get the order
        $order = $this->get_order($order_id);

        $remote_order_status = $this->payflex_remote_check_order_status($order_id);

        // If we don't get a remote status, the data is invalid
        if(!$remote_order_status) {
            $this->payflex_redirect_failed($order_id);
        }

        // get the payflex order id and token
        $payflex_order_token = $this->get_payflex_order_token($order_id);
        $payflex_order_id    = $this->get_payflex_order_id($order_id);

        // If we don't get a payflex order id or token, the data is invalid
        if(!$payflex_order_id OR !$payflex_order_token)
        {   
            $this->log('Invalid callback data received on payment callback');
            $this->payflex_redirect_failed($order_id);
        }

        // If we make it here, the order is both on the site and on Payflex

        $this->log(sprintf('Processing Payflex order for %s, payflex orderId: %s, token: %s', $order_id, $payflex_order_id, $payflex_order_token));
        
        $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $order_id));

        // Comment order url
        $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $payflex_order_id.'&redirect_url='.$current_order_url);
        $order_id_url      = '<a href="'.$admin_support_url.'" >' . $payflex_order_id . '</a> ';
        
        if ($remote_order_status === 'Approved' AND !$order->has_status(['processing', 'completed']))
        {
            $order_note = __('Payment approved.<br>Payflex order ID: <a href="#" >' . $order_id_url, 'woo_payflex');

            if($this->get_payflex_workflow_status($order_id) !== 'completed')
                $order->add_order_note($order_note);

            $order->payment_complete($payflex_order_id);

            $this->set_payflex_workflow_status($order_id, 'completed');

            # Save
            $order->save();

            $this->current_order_proccessed = $payflex_order_id;

            wc_empty_cart();

            $this->payflex_redirect_success($order_id);
            return true;
        }

        if ($remote_order_status === 'Declined' AND !$order->has_status('failed'))
        {
            $order_note = __('Payflex payment declined. Order ID from Payflex: ' . $order_id_url, 'woo_payflex');

            if($this->get_payflex_workflow_status($order_id) !== 'failed')
                $order->add_order_note($order_note);
        
            $this->current_order_proccessed = $payflex_order_id;
            $order->update_status('failed');

            $this->set_payflex_workflow_status($order_id, 'failed');

            $this->payflex_redirect_failed($order_id);
        }
        
        if ($remote_order_status === 'Abandoned' AND !$order->has_status('failed'))
        {
            $order_note = __('Payflex payment abandoned. Order ID from Payflex: ' . $order_id_url . ' ', 'woo_payflex');

            if($this->get_payflex_workflow_status($order_id) !== 'abandoned')
                $order->add_order_note($order_note);
            
            $this->current_order_proccessed = $payflex_order_id;
            $order->update_status('failed');

            $this->set_payflex_workflow_status($order_id, 'abandoned');

            $this->payflex_redirect_failed($order_id);
        }

        $this->payflex_redirect_unknown($order_id);
    }

    public function payflex_redirect_success($order_id = false)
    {
        $order = $this->get_order($order_id);
        $this->log('Payflex redirect success for order ' . $order_id);
        // Load success page
        wp_redirect($this->get_return_url($order)); exit;
    }

    public function payflex_redirect_unknown($order_id = false)
    {
        $order = $this->get_order($order_id);
        $this->log('Payflex redirect unknown for order ' . $order_id);
        wp_redirect($this->get_return_url($order)); exit;
    }

    public function payflex_redirect_failed($order_id = false)
    {
        $order = $this->get_order($order_id);
        $this->log('Payflex redirect failed for order ' . $order_id);
        wp_redirect($this->get_return_url($order)); exit;
    }

    /**
     * Adds a page to check the remote status of the order
     * 
     * 
     */
    public function page_check_remote_status()
    {
        if(isset($_GET['order_id']))
        {
            $order_id = sanitize_text_field($_GET['order_id']);
            $order = $this->get_order($order_id);
            $remote_order_status = $this->payflex_remote_check_order_status($order_id);
            $payflex_order_id    = $this->get_payflex_order_id($order_id);
            $payflex_order_token = $this->get_payflex_order_token($order_id);

            if(!$remote_order_status OR !$payflex_order_id OR !$payflex_order_token)
            {
                $this->log('Invalid callback data received on payment callback');
                wp_redirect($this->get_return_url($order)); exit;
            }

            $this->log('Remote status check for order ' . $order_id . ' returned ' . $remote_order_status);

            $order_note = sprintf(__('Remote status check for order ' . $order_id . ' returned ' . $remote_order_status, 'woo_payflex'));
            $order->add_order_note($order_note);
            wp_redirect($this->get_return_url($order)); exit;
        }
    }

    /**
     * Check whether the cart amount is within payment limits
     *
     * @param  array $gateways Enabled gateways
     * @return  array Enabled gateways, possibly with PartPay removed
     * @since 1.0.0
     */
    public function check_cart_within_limits($gateways)
    {

        global $woocommerce;
        $total = isset($woocommerce
            ->cart
            ->total) ? $woocommerce
            ->cart->total : 0;

        $access_token = $this->get_partpay_authorization_code();
        $config_response_transistent = get_transient('payflex_configuration_response');
        $api_url = $this->configurationUrl;
        if (false !== $config_response_transistent && !empty($config_response_transistent))
        {
            $order_response = $config_response_transistent;
            $order_body = json_decode($order_response);
        }
        else
        {

            $order_args = array(
                'method' => 'GET',
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer ' . $access_token
                ) ,
                'timeout' => 30
            );
            $order_response = wp_remote_post($api_url, $order_args);
            $order_response = wp_remote_retrieve_body($order_response);
            $order_body = json_decode($order_response);
            set_transient('payflex_configuration_response', $order_response, 86400);
        }
        if ($order_response)
        {

            $pbi = ($total >= $order_body->minimumAmount && $total <= $order_body->maximumAmount);

            if (!$pbi)
            {
                unset($gateways['partpay']);
            }

        }

        return $gateways;

    }

    /**
     * Can the order be refunded?
     *
     * @param  WC_Order $order
     * @return bool
     */
    public function can_refund_order($order)
    {
        return $order && $order->get_transaction_id();
    }

    /**
     * Process a refund if supported
     *
     * @param  WC_Order $order
     * @param  float $amount
     * @param  string $reason
     * @return  boolean True or false based on success
     */
    public function process_refund($order_id, $amount = null, $reason = '')
    {
        if(function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        $this->log(sprintf('Attempting refund for order_id: %s', $order_id));

        $partpay_order_id = $order->get_meta('_partpay_order_id');

        if(empty($partpay_order_id))
            $partpay_order_id = get_post_meta($order_id, '_partpay_order_id', true);
        


        $this->log(sprintf('Attempting refund for Payflex orderId: %s', $partpay_order_id));

        $order = new WC_Order($order_id);

        if (empty($partpay_order_id))
        {
            $order->add_order_note(sprintf(__('There was an error submitting the refund to Payflex.', 'woo_payflex')));
            return false;
        }

        $access_token = $this->get_partpay_authorization_code();
        $random_string = wp_generate_password(8, false, false);
        error_log('partpay orderId2' . $partpay_order_id);
        $refund_args = array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $access_token
            ) ,
            'body' => json_encode(array(
                'requestId' => 'Order #' . $order_id . '-' . $random_string,
                'amount' => $amount,
                'merchantRefundReference' => 'Order #' . $order_id . '-' . $random_string
            ))
        );
        error_log('partpay orderId3' . $partpay_order_id);
        $refundOrderUrl = $this->orderurl . '/' . $partpay_order_id . '/refund';

        $refund_response = wp_remote_post($refundOrderUrl, $refund_args);
        $refund_body = json_decode(wp_remote_retrieve_body($refund_response));

        $this->log('Refund body: ' . print_r($refund_body, true));
        error_log('partpay orderId3' . $partpay_order_id);
        $responsecode = isset($refund_response['response']['code']) ? intval($refund_response['response']['code']) : 0;

        if ($responsecode == 201 || $responsecode == 200) {
            $order->add_order_note(sprintf(__('Refund of $%s successfully sent to PayFlex.', 'woo_payflex') , $amount));
            return true;
        } else if($responsecode === 400 && $refund_body->errorCode==='MRM007') {
            $error_message = $refund_body->message;
            $order->add_order_note(sprintf(__($error_message), 'woo_payflex'));
            $error = new WP_Error( 'woocommerce_api_create_order_refund_api_failed', $error_message);    
            return $error;
        } else {
            if ($responsecode == 404) {
                $order->add_order_note(sprintf(__('Order not found on Payflex.', 'woo_payflex')));
            } else {
                $order->add_order_note(sprintf(__('There was an error submitting the refund to Payflex.', 'woo_payflex')));
            }
            return false;
        }

    }

    /**
     * Logging method
     * @param  string $message
     * @param  string $level 
     * @param  array $context
     */
    public static function log($message, $level = 'info', $context = [])
    {

        if (self::$log_enabled)
        {
            if (empty(self::$log))
            {
                self::$log = new WC_Logger();
            }
            
            self::$log->log($level, $message, $context);
        }
    }

    /**
     * @param $order_id
     */
    public function create_refund($order_id)
    {

        $order = new WC_Order($order_id);
        $order_refunds = $order->get_refunds();

        if (!empty($order_refunds))
        {
            $refund_amount = $order_refunds[0]->get_refund_amount();
            if (!empty($refund_amount))
            {
                $this->process_refund($order_id, $refund_amount, "Admin Performed Refund");
            }
        }
    }
    
    /**
     * Check the order status of all orders that didn't return to the thank you page or marked as Pending by Payflex
     *
     * @since 1.0.0
     */
    public function check_pending_abandoned_orders($skip_new_order_check = false)
    {
        $this->log('#### Order CRON running ####');
    
        if($skip_new_order_check)
        {
            $pending_orders = $this->get_pending_abandoned_orders('all');
        }
        else
        {
            $pending_orders = $this->get_pending_abandoned_orders('scheduled');
        }
        
        $this->log('Checking ' . count($pending_orders) . " orders");

        foreach ($pending_orders as $pending_order)
        {
            // Use the available wc_get_order function if it exists
            if (function_exists("wc_get_order"))
                $order = wc_get_order($pending_order->get_id());

            if (!function_exists("wc_get_order"))
                $order = new WC_Order($pending_order->get_id());

            
            // Use wc meta function instead of get_post_meta
            $partpay_order_id = $order->get_meta('_partpay_order_id');

            // If the order meta is not found, try to get it from the post meta
            if(!$partpay_order_id)
                $partpay_order_id = get_post_meta($pending_order->get_id(), '_partpay_order_id', true);
            
            // $this->log(print_r($order, true));
            
            // Check if there's a stored order token. If not, it's not an PartPay order.
            if (!$partpay_order_id)
            {
                $this->log('No Payflex OrderId for Order ' . $pending_order->get_id());
                continue;
            }

            $this->log('Checking abandoned order for WC Order ID ' . $order->get_id() . ', Payflex ID ' . $partpay_order_id);

            // Get the order status from Payflex
            $response = wp_remote_get($this->orderurl . '/' . $partpay_order_id, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->get_partpay_authorization_code() ,
                )
            ));
            $body = json_decode(wp_remote_retrieve_body($response));

            $response_code = wp_remote_retrieve_response_code($response);
            $settings = get_option('woocommerce_payflex_settings');


            if ($response_code != 200)
            {
                // $order->add_order_note(sprintf(__('Tried to check payment status with Payflex. Unable to access API. Repsonse code is %s Payflex Order ID: %s','woo_payflex'),$response_code,$partpay_order_id));
                continue;
            }

            // Comment order url
            $current_order_url = urlencode(admin_url('admin.php?page=wc-orders&action=edit&id=' . $pending_order->get_id()));

            $admin_support_url = admin_url('admin.php?page=payflex-support&payflex_order_id=' . $partpay_order_id.'&redirect_url='.$current_order_url);
            $order_id_url = '<a href="'.$admin_support_url.'" >' . $partpay_order_id . '</a> ';

            $order_note = __('Payment processed via CRON.<br>Payflex Order ID: ' . $order_id_url, 'woo_payflex');

            if($body->orderStatus == "Initiated")
            {
                $this->log('Order is Intiated by customer but not logged in with Payflex OrderId :: '. $partpay_order_id);
                continue;
            }

            if ($body->orderStatus == "Approved")
            {
                $order_note = __('Payment Approved via CRON.<br>Payflex order ID: ' . $order_id_url, 'woo_payflex');

                if(!$this->checkOrderNotesExistsByOrderId($pending_order->get_id(), $order_note))
                    $order->add_order_note($order_note);
                
                $order->payment_complete($pending_order->get_id());
                continue;
            }

            if ($body->orderStatus == "Created")
            {
                if ($settings['enable_order_notes'] == 'yes')
                {   
                    $order_note = sprintf(__('Checked payment status with Payflex. Still pending approval.', 'woo_payflex') , $partpay_order_id);

                    if(!$this->checkOrderNotesExistsByOrderId($pending_order->get_id(), $order_note))
                        $order->add_order_note($order_note);
                }
                continue;
            }

            if ($body->orderStatus == 'Abandoned' OR $body->orderStatus == 'Declined')
            {
                $order_note = __('Payment checked via CRON. Order '.$body->orderStatus.'.<br>Payflex order ID: ' . $order_id_url, 'woo_payflex');
                $isExist = $this->checkOrderNotesExistsByOrderId($pending_order->get_id(), $order_note);

                if(!$isExist){
                    $order->add_order_note($order_note);
                }
                $order->update_status('cancelled');
                continue;
            }

            if(!$this->checkOrderNotesExistsByOrderId($pending_order->get_id(), $order_note))
                $order->add_order_note($order_note);

            $order->update_status('failed');

        }

        $this->log('++++ Order CRON Finished ++++');
    }

    /**
     * Gets a list of orders in two arrays - new orders and orders ready for processing
     *
     * @param string|bool $new_scheduled Whether to get new orders or orders ready for processing ("all", "new", "scheduled", or false/nothing for both)
     * @return array $pending_orders An array of pending orders (new_orders, orders_ready_for_processing)
     */
    public function get_pending_abandoned_orders($new_scheduled = false)
    {

        // Get all orders that are pending, including the new ones.
        if($new_scheduled == 'all')
        {
            $pending_orders = wc_get_orders([
                'status' => [
                    'pending',
                    'failed',
                    'cancelled'
                ],
                'payment_method' => 'payflex',

                // get orders from the last 2 hours
                'date_created' => (time() - 7200).'...'.time()
            ]);

            return $pending_orders;
        }

        // Get all new orders that aren't going to get processed yet
        if($new_scheduled == 'new' OR $new_scheduled == FALSE)
        {
            $pending_orders['new'] = wc_get_orders([
                'status' => [
                    'pending',
                    'failed',
                    'cancelled'
                ],
                'payment_method' => 'payflex',

                // get orders from the last 30 minutes
                'date_created' => (time() - 1800).'...'.time()
            ]);

            if($new_scheduled == 'new') return $pending_orders['new'];
        }

        // Get all orders that are scheduled for processing by the CRON
        if($new_scheduled == 'scheduled' OR $new_scheduled == FALSE)
        {
            $pending_orders['scheduled'] = wc_get_orders([
                'status' => [
                    'pending',
                    'failed',
                    'cancelled'
                ],
                'payment_method' => 'payflex',

                // get orders older than 30 minutes but less than 2 hours
                'date_created' => (time() - 7200).'...'.(time() - 1800)
            ]);

            if($new_scheduled == 'scheduled') return $pending_orders['scheduled'];
        }

        return $pending_orders;
    }

    /**
     * Check if the order notes already exists
     *
     * @param int $order_id
     * @param string $content
     * @return void
     */
    public function checkOrderNotesExistsByOrderId($order_id, $content)
    {
        if (method_exists('WC_Order', 'get_customer_order_notes'))
        {
            $order       = wc_get_order($order_id);
            $order_notes = $order->get_customer_order_notes();
        }
        else
        {
            $order_notes = get_comments([
                'post_id' => $order_id,
                'author'  => 'WooCommerce',
                'type'    => 'order_note'
            ]);
        }

        foreach ($order_notes as $note)
        {
            if ($note->comment_content == $content)
            {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks the current order token, and regenerates it if it's successful
     *
     * @param string|int   $order_id
     * @param string       $order_token
     * @return string|bool The order token or false if it fails
     */
    public function check_order_token($order_id, $order_token)
    {
        $order = wc_get_order($order_id);

        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        if(!$order)
            return false;


        $payflex_token = $order->get_meta('_payflex_token');

        // Legacy checks, first check new order meta, then check old meta
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_token, '_payflex_token', true);
        
        // If it's still empty, check legacy meta
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_token, '_partpay_token');

        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_token, '_partpay_token', true);

        if(empty($payflex_token))
            return false;

    }

    /**
     * Get the Payflex order token
     *
     * @param [type] $order_id
     * @return string|bool
     */
    public function get_payflex_order_token($order_id)
    {
        $order = $this->get_order($order_id);

        $payflex_token = $order->get_meta('_payflex_order_token');

        // Legacy checks, first check new order meta, then check old meta fields
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_id, '_payflex_order_token', true);
        
        // If it's still empty, check legacy meta
        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_id, '_partpay_order_token');

        if(empty($payflex_token))
            $payflex_token = get_post_meta($order_id, '_partpay_order_token', true);

        if(empty($payflex_token))
            return false;

        return $payflex_token;
    }

    public function get_payflex_order_id($order_id)
    {
        $order = $this->get_order($order_id);

        $payflex_order_id = $order->get_meta('_payflex_order_id');

        // Legacy checks, first check new order meta, then check old meta fields
        if(empty($payflex_order_id))
            $payflex_order_id = get_post_meta($order_id, '_payflex_order_id', true);
        
        // If it's still empty, check legacy meta
        if(empty($payflex_order_id))
            $payflex_order_id = get_post_meta($order_id, '_partpay_order_id');

        if(empty($payflex_order_id))
            $payflex_order_id = get_post_meta($order_id, '_partpay_order_id', true);

        if(empty($payflex_order_id))
            return false;

        return $payflex_order_id;
    }

    /**
     * Gets the current workflow status of the order, this isn't the order status. It's how far along the
     * order is in the Payflex workflow. ['initiated', 'processing', 'approved', 'declined', 'abandoned']
     *
     * @param mixed $order_id
     * @return string|bool
     */
    public function get_payflex_workflow_status($order_id)
    {
        $order = $this->get_order($order_id);

        if($this->payflex_worfklow_status)
            return $this->payflex_worfklow_status;

        $payflex_workflow_status = $order->get_meta('_payflex_workflow_status');

        if(!$payflex_workflow_status)
            return false;

        return $payflex_workflow_status;
    }

    /**
     * Set the workflow status of the order
     *
     * @param mixed $order_id
     * @param string $status ['initiated', 'processing', 'approved', 'declined', 'abandoned']
     * @return void
     */
    public function set_payflex_workflow_status($order_id, $status)
    {
        $order = $this->get_order($order_id);
        
        $order->update_meta_data('_payflex_workflow_status', $status);
        $order->save();

        $this->payflex_worfklow_status = $status;

        return true;
    }

    /**
     * Get the order object
     *
     * @param  int $order_id
     * @return  WC_Order
     */
    public function get_order($order_id, $new_order = false)
    {
        if(!$order_id)
            return false;

        // If the order is already set, return it
        if($this->WC_Order && !$new_order && $order_id == $this->WC_Order_ID)
            return $this->WC_Order;
        
        if (function_exists("wc_get_order"))
        {
            $order = wc_get_order($order_id);
        }
        else
        {
            $order = new WC_Order($order_id);
        }

        $this->WC_Order    = $order;
        $this->WC_Order_ID = $order_id;

        return $order;
    
    }

    public function payflex_remote_check_order_status($order_id)
    {
        $order = $this->get_order($order_id);

        $payflex_token = $this->get_payflex_order_id($order_id);

        if(!$payflex_token)
            return false;

        $response = wp_remote_get($this->orderurl . '/' . $payflex_token, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->get_partpay_authorization_code() ,
            )
        ));

        $body = json_decode(wp_remote_retrieve_body($response));

        if(!$body) return false;
        if(!isset($body->token)) return false;
        if(!isset($body->amount)) return false;

        // Check against local token
        $local_token = $this->get_payflex_order_token($order_id);

        if($local_token != $body->token) return false;

        // Check amount
        if($order->get_total() != $body->amount) return false;

        if(!isset($body->orderStatus)) return false;

        return $body->orderStatus;
    }

    public function payflex_remote_get_order($payflex_order_id)
    {
        // sanitize the order id
        $payflex_order_id = sanitize_text_field($payflex_order_id);

        if(!$payflex_order_id)
            return false;

        $response = wp_remote_get($this->orderurl . '/' . $payflex_order_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->get_partpay_authorization_code() ,
            )
        ));

        $body = json_decode(wp_remote_retrieve_body($response));

        return $body;
    }
}
