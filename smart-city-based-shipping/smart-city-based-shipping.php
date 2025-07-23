<?php
/*
Plugin Name: Smart City-Based Shipping
Description: Configure free shipping per city and replace city fields with dropdowns.
Version: 1.0.0
Author: ChatGPT
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class SCBS_Plugin {

    const OPTION_CITIES = 'scbs_cities';
    const OPTION_FREE_CITIES = 'scbs_free_cities';
    const OPTION_HIDE_COMPANY = 'scbs_hide_company';
    const OPTION_HIDE_ADDRESS2 = 'scbs_hide_address2';

    public function __construct() {
        // Admin settings
        add_filter( 'woocommerce_settings_tabs_array', [ $this, 'add_settings_tab' ], 50 );
        add_action( 'woocommerce_settings_tabs_scbs', [ $this, 'settings_tab' ] );
        add_action( 'woocommerce_update_options_scbs', [ $this, 'update_settings' ] );

        // Checkout field modifications
        add_filter( 'woocommerce_checkout_fields', [ $this, 'checkout_fields' ] );
        add_action( 'wp_footer', [ $this, 'checkout_script' ] );

        // Shipping method filtering
        add_filter( 'woocommerce_package_rates', [ $this, 'filter_shipping_methods' ], 10, 2 );

        // Save custom field
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_delivery_time' ], 10, 2 );
        add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_delivery_time' ] );
    }

    /**
     * Returns array of all configured cities.
     */
    public function get_cities() {
        $cities = get_option( self::OPTION_CITIES, '' );
        $cities = array_filter( array_map( 'trim', explode( ',', $cities ) ) );
        return $cities;
    }

    /**
     * Returns array of cities that have free shipping.
     */
    public function get_free_cities() {
        $cities = get_option( self::OPTION_FREE_CITIES, '' );
        $cities = array_filter( array_map( 'trim', explode( ',', $cities ) ) );
        return $cities;
    }

    /**
     * Add settings tab.
     */
    public function add_settings_tab( $tabs ) {
        $tabs['scbs'] = __( 'City Shipping', 'scbs' );
        return $tabs;
    }

    /**
     * Output settings fields.
     */
    public function settings_tab() {
        woocommerce_admin_fields( $this->get_settings() );
    }

    /**
     * Register settings.
     */
    public function get_settings() {
        $settings = [
            'section_title' => [
                'name' => __( 'City Shipping Settings', 'scbs' ),
                'type' => 'title',
                'desc' => '',
                'id'   => 'scbs_section_title',
            ],
            self::OPTION_CITIES => [
                'name' => __( 'Cities', 'scbs' ),
                'type' => 'textarea',
                'desc' => __( 'Comma separated list of cities.', 'scbs' ),
                'id'   => self::OPTION_CITIES,
            ],
            self::OPTION_FREE_CITIES => [
                'name' => __( 'Free Shipping Cities', 'scbs' ),
                'type' => 'textarea',
                'desc' => __( 'Comma separated list of cities that get free shipping.', 'scbs' ),
                'id'   => self::OPTION_FREE_CITIES,
            ],
            self::OPTION_HIDE_COMPANY => [
                'name' => __( 'Hide Company Field', 'scbs' ),
                'type' => 'checkbox',
                'desc' => __( 'Hide the company field on checkout.', 'scbs' ),
                'id'   => self::OPTION_HIDE_COMPANY,
                'default' => 'no',
            ],
            self::OPTION_HIDE_ADDRESS2 => [
                'name' => __( 'Hide Address 2 Field', 'scbs' ),
                'type' => 'checkbox',
                'desc' => __( 'Hide the address line 2 field on checkout.', 'scbs' ),
                'id'   => self::OPTION_HIDE_ADDRESS2,
                'default' => 'no',
            ],
            'section_end' => [
                'type' => 'sectionend',
                'id'   => 'scbs_section_end',
            ],
        ];
        return $settings;
    }

    /**
     * Save settings.
     */
    public function update_settings() {
        woocommerce_update_options( $this->get_settings() );
    }

    /**
     * Modify checkout fields to use dropdown cities and hide fields.
     */
    public function checkout_fields( $fields ) {
        $cities = $this->get_cities();
        $options = [ '' => __( 'Select a city', 'scbs' ) ];
        foreach ( $cities as $city ) {
            $options[ $city ] = $city;
        }

        $billing_city = [
            'type'     => 'select',
            'options'  => $options,
            'required' => true,
            'label'    => __( 'Town / City', 'woocommerce' ),
            'class'    => [ 'form-row-wide' ],
            'priority' => 70,
        ];
        $shipping_city = $billing_city;

        $fields['billing']['billing_city']  = $billing_city;
        $fields['shipping']['shipping_city'] = $shipping_city;

        // Hide shipping email field
        if ( isset( $fields['shipping']['shipping_email'] ) ) {
            unset( $fields['shipping']['shipping_email'] );
        }

        // Optionally hide company and address2
        if ( 'yes' === get_option( self::OPTION_HIDE_COMPANY, 'no' ) ) {
            unset( $fields['billing']['billing_company'] );
            unset( $fields['shipping']['shipping_company'] );
        }
        if ( 'yes' === get_option( self::OPTION_HIDE_ADDRESS2, 'no' ) ) {
            unset( $fields['billing']['billing_address_2'] );
            unset( $fields['shipping']['shipping_address_2'] );
        }

        // Preferred Delivery Time
        $fields['order']['preferred_delivery_time'] = [
            'type'    => 'select',
            'label'   => __( 'Preferred Delivery Time', 'scbs' ),
            'options' => [
                ''        => __( 'Select time', 'scbs' ),
                'morning' => __( 'Morning', 'scbs' ),
                'evening' => __( 'Evening', 'scbs' ),
            ],
            'class'   => [ 'form-row-wide' ],
            'priority' => 5,
        ];

        return $fields;
    }

    /**
     * Reload shipping methods when city changes.
     */
    public function checkout_script() {
        if ( ! is_checkout() ) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(function($){
            $( 'form.checkout' ).on( 'change', 'select[name=billing_city], select[name=shipping_city]', function(){
                $('body').trigger('update_checkout');
            });
        });
        </script>
        <?php
    }

    /**
     * Filter shipping methods based on selected city.
     */
    public function filter_shipping_methods( $rates, $package ) {
        $city = isset( $package['destination']['city'] ) ? trim( $package['destination']['city'] ) : '';
        $free_cities = $this->get_free_cities();
        $is_free = in_array( $city, $free_cities, true );

        foreach ( $rates as $rate_id => $rate ) {
            if ( $is_free && 'free_shipping' !== $rate->method_id ) {
                unset( $rates[ $rate_id ] );
            } elseif ( ! $is_free && 'flat_rate' !== $rate->method_id ) {
                unset( $rates[ $rate_id ] );
            }
        }
        return $rates;
    }

    /**
     * Save preferred delivery time.
     */
    public function save_delivery_time( $order, $data ) {
        if ( isset( $_POST['preferred_delivery_time'] ) ) {
            $order->update_meta_data( '_preferred_delivery_time', sanitize_text_field( $_POST['preferred_delivery_time'] ) );
        }
    }

    /**
     * Display preferred delivery time in admin order details.
     */
    public function display_delivery_time( $order ) {
        $time = $order->get_meta( '_preferred_delivery_time' );
        if ( $time ) {
            echo '<p><strong>' . esc_html__( 'Preferred Delivery Time:', 'scbs' ) . '</strong> ' . esc_html( ucfirst( $time ) ) . '</p>';
        }
    }
}

new SCBS_Plugin();

