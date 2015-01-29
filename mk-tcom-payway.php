<?php
/*
Plugin Name: T-com Payway (MK.dizajn)
Plugin URI: http://www.mk-dizajn.com
Description: T-com Payway gateway za Woocommerce. Plugin također dodaje i HRK novu valutu u woocommerce i postavlja simbol 'kn' sa desne strane.
Version: 1.0
Author: MK.dizajn
Author URI: http://www.mk-dizajn.com
License: wtfpl
*/


add_action('plugins_loaded', 'woocommerce_mk_tcom_init', 0);

function woocommerce_mk_tcom_init() {

    if ( !class_exists( 'WC_Payment_Gateway' ) ) return;


// ##########################################################
// add kuna = new currency
    function add_my_currency( $currencies ) {
       $currencies['HRK'] = __( 'Croatian kuna', 'woocommerce' );
       return $currencies;
    }
    function add_my_currency_symbol( $currency_symbol, $currency ) {
        switch( $currency ) {
            case 'HRK': $currency_symbol = 'kn'; break;
        }
        return $currency_symbol;
    }
    add_filter( 'woocommerce_currencies', 'add_my_currency' );
    add_filter('woocommerce_currency_symbol', 'add_my_currency_symbol', 10, 2);

    // croatian kuna on right of price!
    update_option('woocommerce_currency_pos', 'right_space');
    // ##########################################################


    /**
     * Localisation
     */
    load_plugin_textdomain('mk-tcom', false, dirname( plugin_basename( __FILE__ ) ) . '/languages');

    /**
     * Gateway class
     */
    class WC_MK_tcom extends WC_Payment_Gateway {
       protected $msg = array();
       public function __construct(){
            // Go wild in here
            $this -> id = 'tcom';
            $this -> method_title = __('Tcom Payway', 'tcom');
            $this -> icon = WP_PLUGIN_URL . "/" . plugin_basename(dirname(__FILE__)) . '/payway-admv-hr.gif';
            $this -> has_fields = false;
            $this -> init_form_fields();
            $this -> init_settings();
            $this -> title = $this -> settings['title'];
            $this -> description = $this -> settings['description'];
            $this -> shopid = $this -> settings['shopid'];
            $this -> seckey = $this -> settings['seckey'];
            $this -> redirect_page_id = $this -> settings['redirect_page_id'];
            $this -> liveurl = 'https://pgw.t-com.hr/payment.aspx'; // http://local/i.php
            $this -> msg['message'] = "";
            $this -> msg['class'] = "";
            add_action('init', array($this, 'check_tcom_response'));
            add_action('valid-tcom-request', array($this, 'successful_request'));
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
            add_action('woocommerce_receipt_tcom', array($this, 'receipt_page'));
            add_action('woocommerce_thankyou_tcom',array($this, 'thankyou_page'));
        }

        function init_form_fields(){

            $this -> form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'tcom'),
                    'type' => 'checkbox',
                    'label' => __('Enable T-Com Payment Module.', 'tcom'),
                    'default' => 'no'),
                'title' => array(
                    'title' => __('Title:', 'tcom'),
                    'type'=> 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'tcom'),
                    'default' => __('T-Com PayWay', 'tcom')),
                'description' => array(
                    'title' => __('Description:', 'tcom'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'tcom'),
                    'default' => __('Pay securely by Credit or Debit card through T-Com Secure Servers.', 'tcom')),
                'shopid' => array(
                    'title' => __('Shop ID', 'tcom'),
                    'type' => 'text',
                    'description' => __('Shop ID')),
                'seckey' => array(
                    'title' => __('Secure Key', 'tcom'),
                    'type' => 'text',
                    'description' =>  __('T-Com Secure key', 'tcom'),
                    ),
                'redirect_page_id' => array(
                    'title' => __('Return Page'),
                    'type' => 'select',
                    'options' => $this -> get_pages('Select Page'),
                    'description' => "URL of success page"
                    )
                );
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         **/
        public function admin_options(){
            echo '<h3>'.__('T-Com PayWay Gateway', 'tcom').'</h3>';
            echo '<p>'.__('T-Com PayWay is most popular payment gateway for online shopping in Croatia').'</p>';
            echo '<table class="form-table">';
            $this -> generate_settings_html();
            echo '</table>';

        }
        function payment_fields(){
            if($this -> description) echo wpautop(wptexturize($this -> description));
        }
        function receipt_page($order){
            echo '<p>'.__('Thank you for your order, please click the button below to pay with T-Com PayWay.', 'tcom').'</p>';
            echo $this -> generate_tcom_form($order);
        }
        function process_payment($order_id){
            $order = wc_get_order( $order_id );
            return array(
                'result' => 'success', 
                //'redirect' => $this->get_return_url( $order ),
                'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(get_option('woocommerce_pay_page_id'))))
            );
        }
        function check_tcom_response(){
            if(isset($_REQUEST['tid']) && isset($_REQUEST['card'])){
                $order -> payment_complete();
                $order -> add_order_note('T-Com payment successful');
                $order -> add_order_note($this->msg['message']);
                WC()->cart->empty_cart();
            }
        }

        function showMessage($content){
            return '<div class="box '.$this -> msg['class'].'-box">'.$this -> msg['message'].'</div>'.$content;
        }
        public function generate_tcom_form($order_id){

            $order = wc_get_order( $order_id );
            $redirect_url = ($this -> redirect_page_id=="" || $this -> redirect_page_id==0)?get_site_url() . "/":get_permalink($this -> redirect_page_id);

            $order_id = $order->id;

            // Signature
            $signature = md5($this->shopid . $this->seckey . $order_id . $this->seckey . number_format($order->get_total(), 2, ',', ''). $this->seckey);

            $tcom_args = array(
                'ShopID' =>$this->shopid,
                'ShoppingCartID' => $order_id,
                'TotalAmount' =>number_format($order->get_total(), 2, ',', ''),
                'CancelURL' =>  $order->get_cancel_order_url(),
                'ReturnErrorURL' => $order->get_cancel_order_url(),
                'ReturnURL' => $this->get_return_url( $order ),
                'Signature' => $signature,
                'CustomerFirstname'=>$order->billing_first_name,
                'CustomerSurname'=>$order->billing_last_name,
                'CustomerAddress'=>$order->billing_address_1,
                'CustomerCity'=>$order->billing_city,
                'CustomerZIP'=>$order->billing_postcode,
                'CustomerCountry'=>$order->billing_country,
                'CustomerPhone'=>$order->billing_phone,
                'CustomerEmail'=>$order->billing_email,
                'PaymentType'=>'manual',
                'Installments'=>2,
                'Lang'=>'HR',
                'Curr'=>'N'
                );

                $tcom_args_array = array();
                foreach($tcom_args as $key => $value){
                    $tcom_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
                }
                return '<form action="'.$this -> liveurl.'" method="post" id="paywayform">
                ' . implode('', $tcom_args_array) . '
                <button class="button" type="submit" value="'.__('Pay via T-Com', 'tcom').'"><span>'.__('Pay via T-Com', 'tcom').'</span></button>
                <button class="button" type="submit" value="'.$order->get_cancel_order_url().'"><span>'.__('Cancel order', 'tcom').'</span></button>
                </form>
                <script type="text/javascript">
                    jQuery.noConflict();
                    jQuery(document).ready(function(){
                        jQuery("#paywayform").submit();
                    });
                </script>';
        }


        // get all pages
        function get_pages($title = false, $indent = true) {
            $wp_pages = get_pages('sort_column=menu_order');
            $page_list = array();
            if ($title) $page_list[] = $title;
            foreach ($wp_pages as $page) {
                $prefix = '';
                        // show indented child pages?
                if ($indent) {
                    $has_parent = $page->post_parent;
                    while($has_parent) {
                        $prefix .=  ' - ';
                        $next_page = get_page($has_parent);
                        $has_parent = $next_page->post_parent;
                    }
                }
                        // add to page list array array
                $page_list[$page->ID] = $prefix . $page->post_title;
            }
            return $page_list;
        }

}

    /**
     * Add the Gateway to WooCommerce
     **/
    function woocommerce_add_mk_tcom_gateway($methods) {
        $methods[] = 'WC_MK_tcom';
        return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'woocommerce_add_mk_tcom_gateway' );
}
