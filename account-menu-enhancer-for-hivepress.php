<?php
/**
 * Plugin Name: Account Menu Enhancer for HivePress
 * Description: Integrates HivePress user account menu with WooCommerce My Account menu, with custom menu items, visibility controls, and position management.
 * Version: 1.0
 * Author: Chris Bruce
 * Author URI: https://community.hivepress.io/u/chrisb/summary
 * Requires at least: 5.0
 * Tested up to: 6.6
 * Requires PHP: 7.4
 * Text Domain: account-menu-enhancer-for-hivepress
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define plugin constants
define( 'AMEHP_VERSION', '1.18.22' );
define( 'AMEHP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AMEHP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Initialize the plugin
class AMEHP_Account_Menu_Enhancer {
    private static $instance;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'plugins_loaded', [ $this, 'load_textdomain' ] );
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
        add_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'modify_woocommerce_menu' ], 999 );
        add_filter( 'woocommerce_get_endpoint_url', [ $this, 'override_endpoint_url' ], 10, 4 );
        add_action( 'admin_notices', [ $this, 'show_admin_notices' ] );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'account-menu-enhancer-for-hivepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function add_settings_page() {
        add_options_page(
            __( 'Account Menu Enhancer for HivePress', 'account-menu-enhancer-for-hivepress' ),
            __( 'Account Menu Enhancer', 'account-menu-enhancer-for-hivepress' ),
            'manage_options',
            'amehp-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'amehp_settings_group', 'amehp_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ]
        ] );

        add_settings_section(
            'amehp_main_section',
            __( 'Menu Enhancer Settings', 'account-menu-enhancer-for-hivepress' ),
            null,
            'amehp-settings'
        );

        add_settings_field(
            'amehp_woocommerce_items_to_hide',
            __( 'Hide WooCommerce Menu Items', 'account-menu-enhancer-for-hivepress' ),
            [ $this, 'render_woocommerce_items_field' ],
            'amehp-settings',
            'amehp_main_section'
        );

        add_settings_field(
            'amehp_custom_menu_items',
            __( 'Custom Menu Items', 'account-menu-enhancer-for-hivepress' ),
            [ $this, 'render_custom_menu_items_field' ],
            'amehp-settings',
            'amehp_main_section'
        );
    }

    public function sanitize_settings( $input ) {
        if ( ! isset( $_POST['amehp_nonce'] ) || ! wp_verify_nonce( $_POST['amehp_nonce'], 'amehp_settings_save' ) ) {
            add_settings_error( 'amehp_settings', 'nonce_error', __( 'Security check failed. Please try again.', 'account-menu-enhancer-for-hivepress' ), 'error' );
            return get_option( 'amehp_settings', [] );
        }

        $sanitized = [];
        $sanitized['woocommerce_items_to_hide'] = isset( $input['woocommerce_items_to_hide'] ) ? array_map( 'sanitize_text_field', $input['woocommerce_items_to_hide'] ) : [];
        $sanitized['custom_menu_items'] = [];

        if ( isset( $input['custom_menu_items'] ) && is_array( $input['custom_menu_items'] ) ) {
            foreach ( $input['custom_menu_items'] as $index => $item ) {
                $sanitized_item = [];
                $sanitized_item['label'] = sanitize_text_field( $item['label'] );

                if ( !isset( $item['type'] ) ) {
                    add_settings_error( 'amehp_settings', 'type_error_' . $index, sprintf( __( 'Type for menu item "%s" is missing.', 'account-menu-enhancer-for-hivepress' ), $item['label'] ), 'error' );
                    continue;
                }

                if ( $item['type'] === 'hivepress_route' ) {
                    $sanitized_item['type'] = 'hivepress_route';
                    if ( !empty( $item['route'] ) ) {
                        $sanitized_item['route'] = sanitize_text_field( $item['route'] );
                    } else {
                        add_settings_error( 'amehp_settings', 'route_error_' . $index, sprintf( __( 'Route for menu item "%s" cannot be empty.', 'account-menu-enhancer-for-hivepress' ), $item['label'] ), 'error' );
                        continue;
                    }
                } else {
                    $sanitized_item['type'] = 'url';
                    $url = trim( $item['url'] );
                    if ( empty( $url ) ) {
                        add_settings_error( 'amehp_settings', 'url_error_' . $index, sprintf( __( 'URL for menu item "%s" cannot be empty.', 'account-menu-enhancer-for-hivepress' ), $item['label'] ), 'error' );
                        continue;
                    }
                    $absolute_url_pattern = '/^(https?:\/\/)([\da-z.-]+)\.([a-z]{2,63})(:[0-9]{1,5})?([\/\w .-]*)*\/?$/';
                    if ( preg_match( $absolute_url_pattern, $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                        $sanitized_item['url'] = esc_url_raw( $url );
                    } else {
                        add_settings_error( 'amehp_settings', 'url_error_' . $index, sprintf( __( 'Invalid URL for menu item "%s". Please enter a valid absolute URL (e.g., https://example.com).', 'account-menu-enhancer-for-hivepress' ), $item['label'] ), 'error' );
                        continue;
                    }
                }

                $sanitized_item['menu'] = in_array( $item['menu'], ['hivepress', 'woocommerce', 'both'] ) ? $item['menu'] : 'both';
                $sanitized_item['position'] = absint( $item['position'] );
                $sanitized_item['roles'] = isset( $item['roles'] ) ? array_map( 'sanitize_text_field', $item['roles'] ) : [];
                if ( empty( $sanitized_item['roles'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( "AMEHP: Custom menu item '{$sanitized_item['label']}' has no roles selected, visible to all" );
                }
                $sanitized['custom_menu_items'][$index] = $sanitized_item;
            }
        }

        return $sanitized;
    }

    public function show_admin_notices() {
        $screen = get_current_screen();
        if ( 'settings_page_amehp-settings' !== $screen->id ) {
            return;
        }

        $options = get_option( 'amehp_settings', [] );
        $custom_menu_items = isset( $options['custom_menu_items'] ) ? $options['custom_menu_items'] : [];
        foreach ( $custom_menu_items as $item ) {
            if ( empty( $item['roles'] ) ) {
                echo '<div class="notice notice-warning"><p>' . sprintf(
                    esc_html__( 'Custom menu item "%s" has no user roles selected and will be visible to all users.', 'account-menu-enhancer-for-hivepress' ),
                    esc_html( $item['label'] )
                ) . '</p></div>';
            }
        }
    }

    public function render_settings_page() {
        $options = get_option( 'amehp_settings', [] );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Account Menu Enhancer for HivePress', 'account-menu-enhancer-for-hivepress' ); ?></h1>
            <form method="post" action="options.php" id="amehp-settings-form">
                <?php
                settings_fields( 'amehp_settings_group' );
                wp_nonce_field( 'amehp_settings_save', 'amehp_nonce' );
                do_settings_sections( 'amehp-settings' );
                submit_button( __( 'Save Settings', 'account-menu-enhancer-for-hivepress' ), 'primary', 'amehp-save-settings' );
                ?>
            </form>
        </div>
        <?php
    }

    public function render_woocommerce_items_field() {
        $options = get_option( 'amehp_settings', [] );
        $items_to_hide = isset( $options['woocommerce_items_to_hide'] ) ? $options['woocommerce_items_to_hide'] : [];
        $wc_endpoints = [
            'dashboard'       => __( 'Dashboard', 'woocommerce' ),
            'orders'          => __( 'Orders', 'woocommerce' ),
            'subscriptions'   => __( 'Subscriptions', 'woocommerce' ),
            'downloads'       => __( 'Downloads', 'woocommerce' ),
            'edit-address'    => __( 'Addresses', 'woocommerce' ),
            'payment-methods' => __( 'Payment Methods', 'woocommerce' ),
            'edit-account'    => __( 'Account Details', 'woocommerce' ),
            'customer-logout' => __( 'Logout', 'woocommerce' ),
        ];
        ?>
        <p><?php esc_html_e( 'Select WooCommerce menu items to hide:', 'account-menu-enhancer-for-hivepress' ); ?></p>
        <?php foreach ( $wc_endpoints as $endpoint => $label ) : ?>
            <label>
                <input type="checkbox" name="amehp_settings[woocommerce_items_to_hide][]" value="<?php echo esc_attr( $endpoint ); ?>" <?php checked( in_array( $endpoint, $items_to_hide ) ); ?>>
                <?php echo esc_html( $label ); ?>
            </label><br>
        <?php endforeach; ?>
        <?php
    }

    public function render_custom_menu_items_field() {
        $options = get_option( 'amehp_settings', [] );
        $custom_menu_items = isset( $options['custom_menu_items'] ) ? $options['custom_menu_items'] : [];
        $roles = wp_roles()->get_names();
        $hivepress_routes = $this->get_hivepress_routes();
        ?>
        <div id="amehp-custom-menu-items">
            <p><?php esc_html_e( 'Add custom menu items to HivePress and/or WooCommerce menus:', 'account-menu-enhancer-for-hivepress' ); ?></p>
            <div class="amehp-menu-items">
                <?php
                if ( ! empty( $custom_menu_items ) ) {
                    foreach ( $custom_menu_items as $index => $item ) {
                        $this->render_custom_menu_item_fields( $index, $item, $roles, $hivepress_routes );
                    }
                }
                ?>
            </div>
            <button type="button" class="button amehp-add-menu-item"><?php esc_html_e( 'Add New Menu Item', 'account-menu-enhancer-for-hivepress' ); ?></button>
        </div>

        <script type="text/template" id="amehp-menu-item-template">
            <?php $this->render_custom_menu_item_fields( '{{INDEX}}', [], $roles, $hivepress_routes ); ?>
        </script>
        <?php
    }

    private function render_custom_menu_item_fields( $index, $item, $roles, $hivepress_routes ) {
        $label = isset( $item['label'] ) ? $item['label'] : '';
        $type = isset( $item['type'] ) ? $item['type'] : 'url';
        $url = isset( $item['url'] ) ? $item['url'] : '';
        $route = isset( $item['route'] ) ? $item['route'] : '';
        $menu = isset( $item['menu'] ) ? $item['menu'] : 'both';
        $position = isset( $item['position'] ) ? $item['position'] : 10;
        $selected_roles = isset( $item['roles'] ) ? $item['roles'] : [];
        ?>
        <div class="amehp-menu-item" style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px;">
            <p>
                <label>
                    <?php esc_html_e( 'Label:', 'account-menu-enhancer-for-hivepress' ); ?>
                    <input type="text" name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" required>
                </label>
            </p>
            <p>
Link                <label>
                    <?php esc_html_e( 'Type:', 'account-menu-enhancer-for-hivepress' ); ?>
                    <select name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][type]" class="amehp-type-field">
                        <option value="url" <?php selected( $type, 'url' ); ?>><?php esc_html_e( 'URL', 'account-menu-enhancer-for-hivepress' ); ?></option>
                        <option value="hivepress_route" <?php selected( $type, 'hivepress_route' ); ?>><?php esc_html_e( 'HivePress Route', 'account-menu-enhancer-for-hivepress' ); ?></option>
                    </select>
                </label>
            </p>
            <p class="amehp-url-field" style="<?php echo $type === 'url' ? '' : 'display:none;'; ?>">
                <label>
                    <?php esc_html_e( 'URL:', 'account-menu-enhancer-for-hivepress' ); ?>
                    <input type="text" name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][url]" value="<?php echo esc_attr( $url ); ?>" class="amehp-url-input">
                </label>
                <small><?php esc_html_e( 'Enter a valid absolute URL (e.g., https://example.com).', 'account-menu-enhancer-for-hivepress' ); ?></small>
            </p>
            <p class="amehp-route-field" style="<?php echo $type === 'hivepress_route' ? '' : 'display:none;'; ?>">
                <label>
                    <?php esc_html_e( 'HivePress Route:', 'account-menu-enhancer-for-hivepress' ); ?>
                    <select name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][route]" class="amehp-route-select">
                        <option value=""><?php esc_html_e( 'Select a route', 'account-menu-enhancer-for-hivepress' ); ?></option>
                        <?php foreach ( $hivepress_routes as $route_key => $route_label ) : ?>
                            <option value="<?php echo esc_attr( $route_key ); ?>" <?php selected( $route, $route_key ); ?>><?php echo esc_html( $route_label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Menu:', 'account-menu-enhancer-for-hivepress' ); ?>
                    <select name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][menu]">
                        <option value="hivepress" <?php selected( $menu, 'hivepress' ); ?>><?php esc_html_e( 'HivePress User Menu Only', 'account-menu-enhancer-for-hivepress' ); ?></option>
                        <option value="woocommerce" <?php selected( $menu, 'woocommerce' ); ?>><?php esc_html_e( 'WooCommerce My Account Only', 'account-menu-enhancer-for-hivepress' ); ?></option>
                        <option value="both" <?php selected( $menu, 'both' ); ?>><?php esc_html_e( 'Both Menus', 'account-menu-enhancer-for-hivepress' ); ?></option>
                    </select>
                </label>
            </p>
            <p>
                <label>
                    <?php esc_html_e( 'Position:', 'account-menu-enhancer-for-hivepress' ); ?>
                    <input type="number" name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][position]" value="<?php echo esc_attr( $position ); ?>" min="0" step="10" required class="amehp-position-field">
                    <span class="description"><?php esc_html_e( 'Lower numbers appear higher in the menu (e.g., 0, 10, 20).', 'account-menu-enhancer-for-hivepress' ); ?></span>
                </label>
            </p>
            <p>
                <label><?php esc_html_e( 'Visible to User Roles:', 'account-menu-enhancer-for-hivepress' ); ?></label><br>
                <?php foreach ( $roles as $role_key => $role_name ) : ?>
                    <label>
                        <input type="checkbox" name="amehp_settings[custom_menu_items][<?php echo esc_attr( $index ); ?>][roles][]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $selected_roles ) ); ?>>
                        <?php echo esc_html( $role_name ); ?>
                    </label><br>
                <?php endforeach; ?>
                <small><?php esc_html_e( 'Leave unchecked to show to all roles.', 'account-menu-enhancer-for-hivepress' ); ?></small>
            </p>
            <button type="button" class="button amehp-remove-menu-item"><?php esc_html_e( 'Remove Menu Item', 'account-menu-enhancer-for-hivepress' ); ?></button>
        </div>
        <?php
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( 'settings_page_amehp-settings' !== $hook ) {
            return;
        }

        wp_enqueue_style( 'amehp-admin-css', AMEHP_PLUGIN_URL . 'assets/css/admin.css', [], AMEHP_VERSION );
        wp_enqueue_script( 'amehp-admin-js', AMEHP_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], AMEHP_VERSION, true );
    }

    public function modify_hivepress_menu( $menu23 ) {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( '\HivePress\Menus\User_Account' ) ) {
            return $menu23;
        }

        $options = get_option( 'amehp_settings', [] );
        $items_to_hide = isset( $options['woocommerce_items_to_hide'] ) ? $options['woocommerce_items_to_hide'] : [];
        $custom_menu_items = isset( $options['custom_menu_items'] ) ? $options['custom_menu_items'] : [];

        $hp_menu_items = [];
        if ( function_exists( 'hivepress' ) ) {
            remove_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
            ob_start();
            $hp_menu_items = (new \HivePress\Menus\User_Account())->get_items();
            ob_end_clean();
            add_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
        }

        $hp_items = [];
        foreach ( $hp_menu_items as $endpoint => $item ) {
            $label = ! empty( $item['label'] ) ? $item['label'] : ucwords( str_replace( '_', ' ', $endpoint ) );
            $url = ! empty( $item['url'] ) ? $item['url'] : ( ! empty( $item['route'] ) ? hivepress()->router->get_url( $item['route'] ) : '' );
            if ( empty( $url ) ) {
                continue;
            }
            $hp_items[$endpoint] = [
                'label' => $label,
                'url' => $url,
                'order' => isset( $item['_order'] ) ? $item['_order'] : 999,
                '_order' => isset( $item['_order'] ) ? $item['_order'] : 999,
                'route' => ! empty( $item['route'] ) ? $item['route'] : '',
            ];
        }

        $menu23['items'] = $hp_items;

        $custom_index = 0;
        foreach ( $custom_menu_items as $index => $item ) {
            if ( $item['menu'] === 'woocommerce' ) {
                continue;
            }

            if ( ! $this->user_has_access( $item['roles'], $item['label'] ) ) {
                continue;
            }

            $endpoint = 'custom-' . $index;
            $url = $this->get_custom_item_url( $item );
            if ( $url ) {
                $menu23['items'][$endpoint] = [
                    'label' => $item['label'],
                    'url' => $url,
                    'order' => $item['position'] ?: $custom_index,
                    '_order' => $item['position'] ?: $custom_index,
                ];
                $custom_index += 10;
            }
        }

        $wc_menu_items = function_exists( 'wc_get_account_menu_items' ) ? wc_get_account_menu_items() : [];
        $wc_items = [];
        $wc_index = 50;
        foreach ( $wc_menu_items as $endpoint => $label ) {
            if ( in_array( $endpoint, $items_to_hide ) || isset( $hp_items[$endpoint] ) ) {
                continue;
            }
            $wc_items[$endpoint] = [
                'label' => $label,
                'url' => wc_get_account_endpoint_url( $endpoint ),
                'order' => $wc_index,
                '_order' => $wc_index,
            ];
            $wc_index += 10;
        }

        $is_woocommerce_context = ( function_exists( 'is_account_page' ) && is_account_page() );
        if ( $is_woocommerce_context ) {
            foreach ( $wc_items as $endpoint => $item ) {
                $menu23['items'][$endpoint] = $item;
            }
        } else {
            $menu23['items'] = array_merge( $menu23['items'], $wc_items );
        }

        foreach ( $menu23['items'] as $endpoint => $item ) {
            if ( strpos( $endpoint, 'custom-' ) === 0 ) {
                $index = str_replace( 'custom-', '', $endpoint );
                if ( isset( $custom_menu_items[$index] ) && $custom_menu_items[$index]['menu'] === 'woocommerce' ) {
                    unset( $menu23['items'][$endpoint] );
                }
            }
        }

        uasort( $menu23['items'], function( $a, $b ) {
            $order_a = isset( $a['_order'] ) ? $a['_order'] : 999;
            $order_b = isset( $b['_order'] ) ? $b['_order'] : 999;
            return $order_a <=> $order_b;
        });

        return $menu23;
    }

    public function modify_woocommerce_menu( $items ) {
        if ( ! class_exists( 'WooCommerce' ) || ! class_exists( '\HivePress\Menus\User_Account' ) ) {
            return $items;
        }

        $options = get_option( 'amehp_settings', [] );
        $items_to_hide = isset( $options['woocommerce_items_to_hide'] ) ? $options['woocommerce_items_to_hide'] : [];
        $custom_menu_items = isset( $options['custom_menu_items'] ) ? $options['custom_menu_items'] : [];

        $merged_items = [];
        $wc_index = 50;
        foreach ( $items as $endpoint => $label ) {
            if ( in_array( $endpoint, $items_to_hide ) ) {
                continue;
            }
            $merged_items[$endpoint] = [
                'label' => $label,
                'url' => wc_get_account_endpoint_url( $endpoint ),
                'position' => $wc_index,
            ];
            $wc_index += 10;
        }

        $hp_menu_items = [];
        if ( function_exists( 'hivepress' ) ) {
            remove_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
            ob_start();
            $hp_menu_items = (new \HivePress\Menus\User_Account())->get_items();
            ob_end_clean();
            add_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
        }

        $hp_index = 10;
        foreach ( $hp_menu_items as $endpoint => $item ) {
            $label = ! empty( $item['label'] ) ? $item['label'] : ucwords( str_replace( '_', ' ', $endpoint ) );
            $url = ! empty( $item['url'] ) ? $item['url'] : ( ! empty( $item['route'] ) ? hivepress()->router->get_url( $item['route'] ) : '' );
            if ( empty( $url ) ) {
                continue;
            }
            $merged_items[$endpoint] = [
                'label' => $label,
                'url' => $url,
                'position' => isset( $item['_order'] ) ? $item['_order'] : $hp_index,
            ];
            $hp_index += 10;
        }

        $custom_index = 0;
        foreach ( $custom_menu_items as $index => $item ) {
            if ( $item['menu'] === 'hivepress' ) {
                continue;
            }

            if ( ! $this->user_has_access( $item['roles'], $item['label'] ) ) {
                continue;
            }

            $endpoint = 'custom-' . $index;
            $url = $this->get_custom_item_url( $item );
            if ( $url ) {
                $merged_items[$endpoint] = [
                    'label' => $item['label'],
                    'url' => $url,
                    'position' => $item['position'] ?: $custom_index,
                ];
                $custom_index += 10;
            }
        }

        uasort( $merged_items, function( $a, $b ) {
            $order_a = isset( $a['position'] ) ? $a['position'] : 999;
            $order_b = isset( $b['position'] ) ? $b['position'] : 999;
            return $order_a <=> $order_b;
        });

        $final_items = [];
        foreach ( $merged_items as $endpoint => $item ) {
            $final_items[$endpoint] = $item['label'];
        }

        return $final_items;
    }

    public function override_endpoint_url( $url, $endpoint, $value, $permalink ) {
        if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
            return $url;
        }

        static $is_processing = false;
        if ( $is_processing ) {
            return $url;
        }
        $is_processing = true;

        $options = get_option( 'amehp_settings', [] );
        $custom_menu_items = isset( $options['custom_menu_items'] ) ? $options['custom_menu_items'] : [];

        if ( strpos( $endpoint, 'custom-' ) === 0 ) {
            $index = str_replace( 'custom-', '', $endpoint );
            if ( isset( $custom_menu_items[$index] ) && $this->user_has_access( $custom_menu_items[$index]['roles'], $custom_menu_items[$index]['label'] ) ) {
                $url = $this->get_custom_item_url( $custom_menu_items[$index] );
                $is_processing = false;
                return $url;
            }
        }

        $hp_menu_items = [];
        if ( function_exists( 'hivepress' ) ) {
            remove_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
            ob_start();
            $hp_menu_items = (new \HivePress\Menus\User_Account())->get_items();
            ob_end_clean();
            add_filter( 'hivepress/v1/menus/user_account', [ $this, 'modify_hivepress_menu' ], 1000 );
        }

        if ( isset( $hp_menu_items[$endpoint] ) ) {
            $hp_url = ! empty( $hp_menu_items[$endpoint]['url'] ) ? $hp_menu_items[$endpoint]['url'] : ( ! empty( $hp_menu_items[$endpoint]['route'] ) ? hivepress()->router->get_url( $hp_menu_items[$endpoint]['route'] ) : '' );
            if ( ! empty( $hp_url ) ) {
                $is_processing = false;
                return $hp_url;
            }
        }

        $is_processing = false;
        return $url;
    }

    private function user_has_access( $allowed_roles, $item_label = '' ) {
        if ( empty( $allowed_roles ) ) {
            return true;
        }

        $user = wp_get_current_user();
        if ( ! $user || ! $user->exists() ) {
            return false;
        }

        $user_roles = $user->roles;
        if ( in_array( 'administrator', $user_roles, true ) && ! in_array( 'administrator', $allowed_roles, true ) ) {
            return true;
        }

        foreach ( $allowed_roles as $role ) {
            if ( in_array( $role, $user_roles, true ) ) {
                return true;
            }
        }

        return false;
    }

    private function get_hivepress_routes() {
        return [
            'user_account_page' => __( 'User Account', 'account-menu-enhancer-for-hivepress' ),
            'user_edit_settings_page' => __( 'Edit Settings', 'account-menu-enhancer-for-hivepress' ),
            'user_logout_page' => __( 'Log Out', 'account-menu-enhancer-for-hivepress' ),
            'vendor_view_page' => __( 'Vendor Profile', 'account-menu-enhancer-for-hivepress' ),
            'listings_edit_page' => __( 'Edit Listings', 'account-menu-enhancer-for-hivepress' ),
            'listings_favorite_page' => __( 'Favorite Listings', 'account-menu-enhancer-for-hivepress' ),
        ];
    }

    private function get_custom_item_url( $item ) {
        if ( isset( $item['type'] ) && $item['type'] === 'hivepress_route' && ! empty( $item['route'] ) ) {
            if ( function_exists( 'hivepress' ) ) {
                $route = $item['route'];
                $params = [];
                if ( $route === 'vendor_view_page' ) {
                    $user_id = get_current_user_id();
                    $vendor = \HivePress\Models\Vendor::query()->filter( [ 'user' => $user_id ] )->get_first();
                    if ( $vendor ) {
                        $params['vendor_id'] = $vendor->get_id();
                    } else {
                        return '';
                    }
                }
                return hivepress()->router->get_url( $route, $params );
            }
            return '';
        } elseif ( isset( $item['type'] ) && $item['type'] === 'url' && ! empty( $item['url'] ) ) {
            return $item['url'];
        }
        return '';
    }
}

AMEHP_Account_Menu_Enhancer::get_instance();

register_activation_hook( __FILE__, function() {
    if ( ! file_exists( AMEHP_PLUGIN_DIR . 'assets/css' ) ) {
        mkdir( AMEHP_PLUGIN_DIR . 'assets/css', 0755, true );
    }
    if ( ! file_exists( AMEHP_PLUGIN_DIR . 'assets/js' ) ) {
        mkdir( AMEHP_PLUGIN_DIR . 'assets/js', 0755, true );
    }
});
?>