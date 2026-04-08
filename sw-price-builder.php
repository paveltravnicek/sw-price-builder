<?php
/**
 * Plugin Name: Tvorba ceníků
 * Description: Univerzální builder ceníků s podporou více ceníků, kategorií, položek a dynamického výstupu odolného vůči cache.
 * Version: 1.0
 * Author: Smart Websites
 *  * Text Domain: sw-price-builder
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'SW_Price_Builder' ) ) {

final class SW_Price_Builder {

    const VERSION = '1.0';
    const OPTION_SETTINGS = 'swpb_settings';
    const OPTION_DB_VERSION = 'swpb_db_version';
    const DB_VERSION = '0.1.0';
    const REST_NAMESPACE = 'sw-price-builder/v1';

    private static $instance = null;
    private $table_pricelists = '';
    private $table_categories = '';
    private $table_items = '';

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_pricelists = $wpdb->prefix . 'swpb_pricelists';
        $this->table_categories = $wpdb->prefix . 'swpb_categories';
        $this->table_items      = $wpdb->prefix . 'swpb_items';

        register_activation_hook( __FILE__, array( __CLASS__, 'activate' ) );

        add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'handle_admin_post' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'frontend_assets' ) );
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_action( 'wp_ajax_swpb_save_category_order', array( $this, 'ajax_save_category_order' ) );
        add_action( 'wp_ajax_swpb_save_item_order', array( $this, 'ajax_save_item_order' ) );
        add_action( 'wp_ajax_swpb_get_categories', array( $this, 'ajax_get_categories' ) );

        add_shortcode( 'sw_pricelist', array( $this, 'shortcode_pricelist' ) );
        add_shortcode( 'sw_pricelist_updated', array( $this, 'shortcode_updated' ) );
    }

    public static function activate() {
        $self = self::instance();
        $self->create_tables();

        if ( ! get_option( self::OPTION_SETTINGS ) ) {
            add_option( self::OPTION_SETTINGS, $self->get_default_settings() );
        }
    }

    public function maybe_upgrade_db() {
        if ( get_option( self::OPTION_DB_VERSION ) !== self::DB_VERSION ) {
            $this->create_tables();
        }
    }

    private function create_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        $sql_pricelists = "CREATE TABLE {$this->table_pricelists} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            description TEXT NULL,
            layout VARCHAR(30) NOT NULL DEFAULT 'table',
            currency_symbol VARCHAR(20) NOT NULL DEFAULT 'Kč',
            currency_position VARCHAR(10) NOT NULL DEFAULT 'after',
            default_suffix VARCHAR(50) NULL,
            empty_price_text VARCHAR(191) NOT NULL DEFAULT 'Na dotaz',
            css_class VARCHAR(191) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY slug (slug),
            KEY is_active (is_active)
        ) $charset_collate;";

        $sql_categories = "CREATE TABLE {$this->table_categories} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pricelist_id BIGINT(20) UNSIGNED NOT NULL,
            parent_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            name VARCHAR(191) NOT NULL,
            slug VARCHAR(191) NOT NULL,
            description TEXT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pricelist_id (pricelist_id),
            KEY parent_id (parent_id),
            KEY sort_order (sort_order)
        ) $charset_collate;";

        $sql_items = "CREATE TABLE {$this->table_items} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            pricelist_id BIGINT(20) UNSIGNED NOT NULL,
            category_id BIGINT(20) UNSIGNED NOT NULL,
            code VARCHAR(100) NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            badge VARCHAR(100) NULL,
            price_primary DECIMAL(12,2) NULL,
            price_secondary DECIMAL(12,2) NULL,
            price_primary_label VARCHAR(100) NULL,
            price_secondary_label VARCHAR(100) NULL,
            price_prefix VARCHAR(50) NULL,
            price_suffix VARCHAR(50) NULL,
            price_note VARCHAR(191) NULL,
            price_text VARCHAR(191) NULL,
            include_in_range TINYINT(1) NOT NULL DEFAULT 1,
            is_featured TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT(11) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY pricelist_id (pricelist_id),
            KEY category_id (category_id),
            KEY sort_order (sort_order),
            KEY is_active (is_active)
        ) $charset_collate;";

        dbDelta( $sql_pricelists );
        dbDelta( $sql_categories );
        dbDelta( $sql_items );

        update_option( self::OPTION_DB_VERSION, self::DB_VERSION );
    }

    private function get_default_settings() {
        return array(
            'frontend_mode'      => 'dynamic',
            'load_frontend_css'  => '1',
            'show_codes'         => '0',
            'show_update_date'   => '1',
        );
    }

    private function get_settings() {
        return wp_parse_args( (array) get_option( self::OPTION_SETTINGS, array() ), $this->get_default_settings() );
    }

    public function admin_menu() {
        add_menu_page(
            __( 'Ceníky', 'sw-price-builder' ),
            __( 'Ceníky', 'sw-price-builder' ),
            'manage_options',
            'swpb_dashboard',
            array( $this, 'render_dashboard_page' ),
            'dashicons-feedback',
            26
        );

        add_submenu_page( 'swpb_dashboard', __( 'Přehled', 'sw-price-builder' ), __( 'Přehled', 'sw-price-builder' ), 'manage_options', 'swpb_dashboard', array( $this, 'render_dashboard_page' ) );
        add_submenu_page( 'swpb_dashboard', __( 'Ceníky', 'sw-price-builder' ), __( 'Ceníky', 'sw-price-builder' ), 'manage_options', 'swpb_pricelists', array( $this, 'render_pricelists_page' ) );
        add_submenu_page( 'swpb_dashboard', __( 'Kategorie', 'sw-price-builder' ), __( 'Kategorie', 'sw-price-builder' ), 'manage_options', 'swpb_categories', array( $this, 'render_categories_page' ) );
        add_submenu_page( 'swpb_dashboard', __( 'Položky', 'sw-price-builder' ), __( 'Položky', 'sw-price-builder' ), 'manage_options', 'swpb_items', array( $this, 'render_items_page' ) );
        add_submenu_page( 'swpb_dashboard', __( 'Nastavení', 'sw-price-builder' ), __( 'Nastavení', 'sw-price-builder' ), 'manage_options', 'swpb_settings', array( $this, 'render_settings_page' ) );
    }

    public function admin_assets( $hook ) {
        if ( false === strpos( $hook, 'swpb_' ) ) {
            return;
        }

        wp_enqueue_style( 'swpb-admin', plugin_dir_url( __FILE__ ) . 'assets/css/admin.css', array(), self::VERSION );
        wp_enqueue_script( 'jquery-ui-sortable' );
        wp_enqueue_script( 'swpb-admin', plugin_dir_url( __FILE__ ) . 'assets/js/admin.js', array( 'jquery', 'jquery-ui-sortable' ), self::VERSION, true );

        wp_localize_script(
            'swpb-admin',
            'swpbAdmin',
            array(
                'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
                'nonce'              => wp_create_nonce( 'swpb_order' ),
                'categoriesNonce'    => wp_create_nonce( 'swpb_get_categories' ),
                'categoriesEmpty'    => __( 'Nejprve vyber ceník', 'sw-price-builder' ),
                'categoriesChoose'   => __( 'Vyber kategorii', 'sw-price-builder' ),
                'categoriesChooseAll'=> __( 'Všechny kategorie', 'sw-price-builder' ),
            )
        );
    }

    public function frontend_assets() {
        if ( is_admin() ) {
            return;
        }

        $settings = $this->get_settings();

        if ( '1' === $settings['load_frontend_css'] ) {
            wp_enqueue_style( 'swpb-frontend', plugin_dir_url( __FILE__ ) . 'assets/css/frontend.css', array(), self::VERSION );
        }

        wp_enqueue_script( 'swpb-frontend', plugin_dir_url( __FILE__ ) . 'assets/js/frontend.js', array(), self::VERSION, true );
        wp_localize_script(
            'swpb-frontend',
            'swpbFrontend',
            array(
                'endpoint' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/render' ) ),
            )
        );
    }

    public function register_rest_routes() {
        register_rest_route(
            self::REST_NAMESPACE,
            '/render',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_render' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public function rest_render( WP_REST_Request $request ) {
        nocache_headers();
        header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $pricelist_id = absint( $request->get_param( 'id' ) );
        $category_id  = absint( $request->get_param( 'category' ) );
        $layout       = sanitize_key( (string) $request->get_param( 'layout' ) );

        $html = $this->render_pricelist( $pricelist_id, array(
            'category' => $category_id,
            'layout'   => $layout,
        ) );

        return new WP_REST_Response( array( 'html' => $html ), 200 );
    }

    public function shortcode_pricelist( $atts ) {
        $atts = shortcode_atts(
            array(
                'id'       => 0,
                'category' => 0,
                'layout'   => '',
            ),
            $atts,
            'sw_pricelist'
        );

        $pricelist_id = absint( $atts['id'] );
        if ( ! $pricelist_id ) {
            return '';
        }

        $settings = $this->get_settings();

        if ( 'dynamic' === $settings['frontend_mode'] ) {
            return sprintf(
                '<div class="swpb-dynamic-placeholder" data-pricelist="%1$d" data-category="%2$d" data-layout="%3$s"><div class="swpb-loading">%4$s</div></div>',
                $pricelist_id,
                absint( $atts['category'] ),
                esc_attr( sanitize_key( (string) $atts['layout'] ) ),
                esc_html__( 'Načítám ceník…', 'sw-price-builder' )
            );
        }

        return $this->render_pricelist( $pricelist_id, array(
            'category' => absint( $atts['category'] ),
            'layout'   => sanitize_key( (string) $atts['layout'] ),
        ) );
    }

    public function shortcode_updated( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sw_pricelist_updated' );
        $pricelist_id = absint( $atts['id'] );
        if ( ! $pricelist_id ) {
            return '';
        }

        global $wpdb;
        $date = $wpdb->get_var( $wpdb->prepare( "SELECT updated_at FROM {$this->table_pricelists} WHERE id = %d", $pricelist_id ) );
        if ( ! $date ) {
            return '';
        }

        return '<div class="swpb-updated">' . sprintf( esc_html__( 'Poslední aktualizace: %s', 'sw-price-builder' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $date ) ) ) ) . '</div>';
    }

    private function render_pricelist( $pricelist_id, $args = array() ) {
        global $wpdb;

        $args = wp_parse_args(
            $args,
            array(
                'category' => 0,
                'layout'   => '',
            )
        );

        $pricelist = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_pricelists} WHERE id = %d AND is_active = 1", $pricelist_id ) );
        if ( ! $pricelist ) {
            return '';
        }

        $layout = in_array( $args['layout'], array( 'table', 'cards', 'list' ), true ) ? $args['layout'] : $pricelist->layout;
        if ( ! in_array( $layout, array( 'table', 'cards', 'list' ), true ) ) {
            $layout = 'table';
        }

        $categories = $this->get_categories_tree( $pricelist_id, absint( $args['category'] ) );
        if ( empty( $categories ) ) {
            return '<div class="swpb-empty">' . esc_html__( 'Ceník zatím neobsahuje žádné kategorie.', 'sw-price-builder' ) . '</div>';
        }

        ob_start();
        ?>
        <div class="swpb-wrap <?php echo esc_attr( trim( 'swpb-layout-' . $layout . ' ' . $pricelist->css_class ) ); ?>">
            <?php if ( ! empty( $pricelist->description ) ) : ?>
                <div class="swpb-pricelist-description"><?php echo wp_kses_post( wpautop( $pricelist->description ) ); ?></div>
            <?php endif; ?>
            <?php foreach ( $categories as $category ) : ?>
                <?php $this->render_category_block( $pricelist, $category, $layout ); ?>
            <?php endforeach; ?>
            <?php if ( '1' === $this->get_settings()['show_update_date'] ) : ?>
                <div class="swpb-updated"><?php echo esc_html( sprintf( 'Poslední aktualizace: %s', date_i18n( get_option( 'date_format' ), strtotime( $pricelist->updated_at ) ) ) ); ?></div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_category_block( $pricelist, $category, $layout ) {
        $items = $this->get_items_for_category( (int) $pricelist->id, (int) $category->id );
        $children = $this->get_child_categories( (int) $pricelist->id, (int) $category->id );
        ?>
        <section class="swpb-category" data-category-id="<?php echo (int) $category->id; ?>">
            <header class="swpb-category-header">
                <h3 class="swpb-category-title"><?php echo esc_html( $category->name ); ?></h3>
                <?php if ( ! empty( $category->description ) ) : ?>
                    <div class="swpb-category-description"><?php echo wp_kses_post( wpautop( $category->description ) ); ?></div>
                <?php endif; ?>
            </header>

            <?php if ( ! empty( $items ) ) : ?>
                <?php if ( 'cards' === $layout ) : ?>
                    <?php $this->render_items_cards( $pricelist, $items ); ?>
                <?php elseif ( 'list' === $layout ) : ?>
                    <?php $this->render_items_list( $pricelist, $items ); ?>
                <?php else : ?>
                    <?php $this->render_items_table( $pricelist, $items ); ?>
                <?php endif; ?>
            <?php endif; ?>

            <?php foreach ( $children as $child ) : ?>
                <?php $this->render_subcategory_block( $pricelist, $child, $layout ); ?>
            <?php endforeach; ?>
        </section>
        <?php
    }

    private function render_subcategory_block( $pricelist, $category, $layout ) {
        $items = $this->get_items_for_category( (int) $pricelist->id, (int) $category->id );
        if ( empty( $items ) ) {
            return;
        }
        ?>
        <div class="swpb-subcategory">
            <h4 class="swpb-subcategory-title"><?php echo esc_html( $category->name ); ?></h4>
            <?php if ( 'cards' === $layout ) : ?>
                <?php $this->render_items_cards( $pricelist, $items ); ?>
            <?php elseif ( 'list' === $layout ) : ?>
                <?php $this->render_items_list( $pricelist, $items ); ?>
            <?php else : ?>
                <?php $this->render_items_table( $pricelist, $items ); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_items_table( $pricelist, $items ) {
        $settings = $this->get_settings();
        ?>
        <div class="swpb-table-wrap">
            <table class="swpb-table">
                <thead>
                    <tr>
                        <?php if ( '1' === $settings['show_codes'] ) : ?><th><?php esc_html_e( 'Kód', 'sw-price-builder' ); ?></th><?php endif; ?>
                        <th><?php esc_html_e( 'Položka', 'sw-price-builder' ); ?></th>
                        <th><?php esc_html_e( 'Cena', 'sw-price-builder' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $items as $item ) : ?>
                    <tr class="<?php echo $item->is_featured ? 'is-featured' : ''; ?>">
                        <?php if ( '1' === $settings['show_codes'] ) : ?><td><?php echo esc_html( (string) $item->code ); ?></td><?php endif; ?>
                        <td><?php $this->render_item_meta( $item ); ?></td>
                        <td><?php $this->render_item_price( $pricelist, $item ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_items_cards( $pricelist, $items ) {
        ?>
        <div class="swpb-cards">
            <?php foreach ( $items as $item ) : ?>
                <article class="swpb-card <?php echo $item->is_featured ? 'is-featured' : ''; ?>">
                    <?php $this->render_item_meta( $item ); ?>
                    <div class="swpb-card-price"><?php $this->render_item_price( $pricelist, $item ); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_items_list( $pricelist, $items ) {
        ?>
        <div class="swpb-list">
            <?php foreach ( $items as $item ) : ?>
                <article class="swpb-list-item <?php echo $item->is_featured ? 'is-featured' : ''; ?>">
                    <div class="swpb-list-main"><?php $this->render_item_meta( $item ); ?></div>
                    <div class="swpb-list-price"><?php $this->render_item_price( $pricelist, $item ); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_item_meta( $item ) {
        ?>
        <div class="swpb-item-meta">
            <div class="swpb-item-title-row">
                <div class="swpb-item-title"><?php echo esc_html( $item->name ); ?></div>
                <?php if ( ! empty( $item->badge ) ) : ?>
                    <span class="swpb-badge"><?php echo esc_html( $item->badge ); ?></span>
                <?php endif; ?>
            </div>
            <?php if ( ! empty( $item->description ) ) : ?>
                <div class="swpb-item-description"><?php echo wp_kses_post( wpautop( $item->description ) ); ?></div>
            <?php endif; ?>
            <?php if ( ! empty( $item->price_note ) ) : ?>
                <div class="swpb-item-note"><?php echo esc_html( $item->price_note ); ?></div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_item_price( $pricelist, $item ) {
        if ( ! empty( $item->price_text ) ) {
            echo '<div class="swpb-price-text">' . esc_html( $item->price_text ) . '</div>';
            return;
        }

        $primary = $this->format_price( $pricelist, $item->price_primary, $item->price_prefix, $item->price_suffix );
        $secondary = $this->format_price( $pricelist, $item->price_secondary, $item->price_prefix, $item->price_suffix );

        if ( $primary ) {
            echo '<div class="swpb-price-primary">';
            if ( ! empty( $item->price_primary_label ) ) {
                echo '<span class="swpb-price-label">' . esc_html( $item->price_primary_label ) . '</span>';
            }
            echo '<span class="swpb-price-value">' . esc_html( $primary ) . '</span></div>';
        }

        if ( $secondary ) {
            echo '<div class="swpb-price-secondary">';
            if ( ! empty( $item->price_secondary_label ) ) {
                echo '<span class="swpb-price-label">' . esc_html( $item->price_secondary_label ) . '</span>';
            }
            echo '<span class="swpb-price-value">' . esc_html( $secondary ) . '</span></div>';
        }

        if ( ! $primary && ! $secondary ) {
            echo '<div class="swpb-price-empty">' . esc_html( $pricelist->empty_price_text ) . '</div>';
        }
    }

    private function format_price( $pricelist, $price, $prefix = '', $suffix = '' ) {
        if ( null === $price || '' === $price ) {
            return '';
        }

        $formatted = number_format_i18n( (float) $price, 0 === ( (float) $price - floor( (float) $price ) ) ? 0 : 2 );
        $currency = trim( (string) $pricelist->currency_symbol );
        $suffix = trim( $suffix ? $suffix : (string) $pricelist->default_suffix );
        $parts = array();

        if ( ! empty( $prefix ) ) {
            $parts[] = trim( $prefix );
        }

        if ( 'before' === $pricelist->currency_position && $currency ) {
            $parts[] = $currency . ' ' . $formatted;
        } else {
            $parts[] = $formatted . ( $currency ? ' ' . $currency : '' );
        }

        if ( $suffix ) {
            $parts[] = $suffix;
        }

        return trim( implode( ' ', $parts ) );
    }

    private function get_categories_tree( $pricelist_id, $only_category_id = 0 ) {
        global $wpdb;
        if ( $only_category_id ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table_categories} WHERE pricelist_id = %d AND id = %d AND is_active = 1 ORDER BY sort_order ASC, name ASC",
                    $pricelist_id,
                    $only_category_id
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_categories} WHERE pricelist_id = %d AND parent_id = 0 AND is_active = 1 ORDER BY sort_order ASC, name ASC",
                $pricelist_id
            )
        );
    }

    private function get_child_categories( $pricelist_id, $parent_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_categories} WHERE pricelist_id = %d AND parent_id = %d AND is_active = 1 ORDER BY sort_order ASC, name ASC",
                $pricelist_id,
                $parent_id
            )
        );
    }

    private function get_items_for_category( $pricelist_id, $category_id ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_items} WHERE pricelist_id = %d AND category_id = %d AND is_active = 1 ORDER BY sort_order ASC, name ASC",
                $pricelist_id,
                $category_id
            )
        );
    }

    public function handle_admin_post() {
        if ( ! current_user_can( 'manage_options' ) || empty( $_POST['swpb_action'] ) ) {
            return;
        }

        $action = sanitize_text_field( wp_unslash( $_POST['swpb_action'] ) );
        $nonce_action = 'swpb_' . $action;
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), $nonce_action ) ) {
            wp_die( esc_html__( 'Neplatný bezpečnostní token.', 'sw-price-builder' ) );
        }

        switch ( $action ) {
            case 'save_pricelist':
                $this->handle_save_pricelist();
                break;
            case 'delete_pricelist':
                $this->handle_delete_pricelist();
                break;
            case 'save_category':
                $this->handle_save_category();
                break;
            case 'delete_category':
                $this->handle_delete_category();
                break;
            case 'save_item':
                $this->handle_save_item();
                break;
            case 'delete_item':
                $this->handle_delete_item();
                break;
            case 'save_settings':
                $this->handle_save_settings();
                break;
        }
    }

    private function handle_save_pricelist() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

        if ( '' === $name ) {
            $this->redirect_with_notice( 'swpb_pricelists', 'error', 'Název ceníku je povinný.' );
        }

        $data = array(
            'name'              => $name,
            'slug'              => sanitize_title( $name ),
            'description'       => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
            'layout'            => isset( $_POST['layout'] ) && in_array( $_POST['layout'], array( 'table', 'cards', 'list' ), true ) ? sanitize_text_field( wp_unslash( $_POST['layout'] ) ) : 'table',
            'currency_symbol'   => isset( $_POST['currency_symbol'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_symbol'] ) ) : 'Kč',
            'currency_position' => isset( $_POST['currency_position'] ) && 'before' === $_POST['currency_position'] ? 'before' : 'after',
            'default_suffix'    => isset( $_POST['default_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['default_suffix'] ) ) : '',
            'empty_price_text'  => isset( $_POST['empty_price_text'] ) ? sanitize_text_field( wp_unslash( $_POST['empty_price_text'] ) ) : 'Na dotaz',
            'css_class'         => isset( $_POST['css_class'] ) ? sanitize_html_class( wp_unslash( $_POST['css_class'] ) ) : '',
            'is_active'         => ! empty( $_POST['is_active'] ) ? 1 : 0,
            'updated_at'        => current_time( 'mysql' ),
        );

        if ( $id ) {
            $wpdb->update( $this->table_pricelists, $data, array( 'id' => $id ), null, array( '%d' ) );
        } else {
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $this->table_pricelists, $data );
        }

        $this->redirect_with_notice( 'swpb_pricelists', 'success', 'Ceník byl uložen.' );
    }

    private function handle_delete_pricelist() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            $this->redirect_with_notice( 'swpb_pricelists', 'error', 'Ceník se nepodařilo smazat.' );
        }
        $category_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table_categories} WHERE pricelist_id = %d", $id ) );
        if ( ! empty( $category_ids ) ) {
            $wpdb->query( "DELETE FROM {$this->table_items} WHERE category_id IN (" . implode( ',', array_map( 'absint', $category_ids ) ) . ')');
        }
        $wpdb->delete( $this->table_categories, array( 'pricelist_id' => $id ), array( '%d' ) );
        $wpdb->delete( $this->table_items, array( 'pricelist_id' => $id ), array( '%d' ) );
        $wpdb->delete( $this->table_pricelists, array( 'id' => $id ), array( '%d' ) );
        $this->redirect_with_notice( 'swpb_pricelists', 'success', 'Ceník byl smazán.' );
    }

    private function handle_save_category() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $pricelist_id = isset( $_POST['pricelist_id'] ) ? absint( $_POST['pricelist_id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( ! $pricelist_id || '' === $name ) {
            $this->redirect_with_notice( 'swpb_categories', 'error', 'Kategorie vyžaduje ceník a název.' );
        }

        $data = array(
            'pricelist_id' => $pricelist_id,
            'parent_id'    => isset( $_POST['parent_id'] ) ? absint( $_POST['parent_id'] ) : 0,
            'name'         => $name,
            'slug'         => sanitize_title( $name ),
            'description'  => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
            'is_active'    => ! empty( $_POST['is_active'] ) ? 1 : 0,
            'updated_at'   => current_time( 'mysql' ),
        );

        if ( $id ) {
            $wpdb->update( $this->table_categories, $data, array( 'id' => $id ), null, array( '%d' ) );
        } else {
            $max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$this->table_categories} WHERE pricelist_id = %d AND parent_id = %d", $pricelist_id, $data['parent_id'] ) );
            $data['sort_order'] = $max + 1;
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $this->table_categories, $data );
        }

        $this->touch_pricelist( $pricelist_id );
        $this->redirect_with_notice( 'swpb_categories', 'success', 'Kategorie byla uložena.' );
    }

    private function handle_delete_category() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            $this->redirect_with_notice( 'swpb_categories', 'error', 'Kategorie se nepodařilo smazat.' );
        }
        $pricelist_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pricelist_id FROM {$this->table_categories} WHERE id = %d", $id ) );
        $child_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table_categories} WHERE parent_id = %d", $id ) );
        $ids = array_merge( array( $id ), array_map( 'absint', $child_ids ) );
        if ( ! empty( $ids ) ) {
            $wpdb->query( "DELETE FROM {$this->table_items} WHERE category_id IN (" . implode( ',', $ids ) . ')' );
            $wpdb->query( "DELETE FROM {$this->table_categories} WHERE id IN (" . implode( ',', $ids ) . ')' );
        }
        if ( $pricelist_id ) {
            $this->touch_pricelist( $pricelist_id );
        }
        $this->redirect_with_notice( 'swpb_categories', 'success', 'Kategorie byla smazána.' );
    }

    private function handle_save_item() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $pricelist_id = isset( $_POST['pricelist_id'] ) ? absint( $_POST['pricelist_id'] ) : 0;
        $category_id = isset( $_POST['category_id'] ) ? absint( $_POST['category_id'] ) : 0;
        $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
        if ( ! $pricelist_id || ! $category_id || '' === $name ) {
            $this->redirect_with_notice( 'swpb_items', 'error', 'Položka vyžaduje ceník, kategorii a název.' );
        }

        $data = array(
            'pricelist_id'         => $pricelist_id,
            'category_id'          => $category_id,
            'code'                 => isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '',
            'name'                 => $name,
            'description'          => isset( $_POST['description'] ) ? wp_kses_post( wp_unslash( $_POST['description'] ) ) : '',
            'badge'                => isset( $_POST['badge'] ) ? sanitize_text_field( wp_unslash( $_POST['badge'] ) ) : '',
            'price_primary'        => $this->sanitize_decimal( isset( $_POST['price_primary'] ) ? wp_unslash( $_POST['price_primary'] ) : '' ),
            'price_secondary'      => $this->sanitize_decimal( isset( $_POST['price_secondary'] ) ? wp_unslash( $_POST['price_secondary'] ) : '' ),
            'price_primary_label'  => isset( $_POST['price_primary_label'] ) ? sanitize_text_field( wp_unslash( $_POST['price_primary_label'] ) ) : '',
            'price_secondary_label'=> isset( $_POST['price_secondary_label'] ) ? sanitize_text_field( wp_unslash( $_POST['price_secondary_label'] ) ) : '',
            'price_prefix'         => isset( $_POST['price_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['price_prefix'] ) ) : '',
            'price_suffix'         => isset( $_POST['price_suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['price_suffix'] ) ) : '',
            'price_note'           => isset( $_POST['price_note'] ) ? sanitize_text_field( wp_unslash( $_POST['price_note'] ) ) : '',
            'price_text'           => isset( $_POST['price_text'] ) ? sanitize_text_field( wp_unslash( $_POST['price_text'] ) ) : '',
            'include_in_range'     => ! empty( $_POST['include_in_range'] ) ? 1 : 0,
            'is_featured'          => ! empty( $_POST['is_featured'] ) ? 1 : 0,
            'is_active'            => ! empty( $_POST['is_active'] ) ? 1 : 0,
            'updated_at'           => current_time( 'mysql' ),
        );

        if ( $id ) {
            $wpdb->update( $this->table_items, $data, array( 'id' => $id ), null, array( '%d' ) );
        } else {
            $max = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$this->table_items} WHERE category_id = %d", $category_id ) );
            $data['sort_order'] = $max + 1;
            $data['created_at'] = current_time( 'mysql' );
            $wpdb->insert( $this->table_items, $data );
        }

        $this->touch_pricelist( $pricelist_id );
        $this->redirect_with_notice( 'swpb_items', 'success', 'Položka byla uložena.' );
    }

    private function handle_delete_item() {
        global $wpdb;
        $id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        if ( ! $id ) {
            $this->redirect_with_notice( 'swpb_items', 'error', 'Položka se nepodařilo smazat.' );
        }
        $pricelist_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pricelist_id FROM {$this->table_items} WHERE id = %d", $id ) );
        $wpdb->delete( $this->table_items, array( 'id' => $id ), array( '%d' ) );
        if ( $pricelist_id ) {
            $this->touch_pricelist( $pricelist_id );
        }
        $this->redirect_with_notice( 'swpb_items', 'success', 'Položka byla smazána.' );
    }

    private function handle_save_settings() {
        $settings = array(
            'frontend_mode'     => ( isset( $_POST['frontend_mode'] ) && 'direct' === $_POST['frontend_mode'] ) ? 'direct' : 'dynamic',
            'load_frontend_css' => ! empty( $_POST['load_frontend_css'] ) ? '1' : '0',
            'show_codes'        => ! empty( $_POST['show_codes'] ) ? '1' : '0',
            'show_update_date'  => ! empty( $_POST['show_update_date'] ) ? '1' : '0',
        );
        update_option( self::OPTION_SETTINGS, $settings );
        $this->redirect_with_notice( 'swpb_settings', 'success', 'Nastavení bylo uloženo.' );
    }

    private function sanitize_decimal( $value ) {
        $value = str_replace( array( ' ', ',' ), array( '', '.' ), (string) $value );
        return is_numeric( $value ) ? $value : null;
    }

    private function touch_pricelist( $pricelist_id ) {
        global $wpdb;
        $wpdb->update(
            $this->table_pricelists,
            array( 'updated_at' => current_time( 'mysql' ) ),
            array( 'id' => $pricelist_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    public function ajax_save_category_order() {
        check_ajax_referer( 'swpb_order', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        global $wpdb;
        $top = isset( $_POST['top'] ) ? json_decode( wp_unslash( $_POST['top'] ), true ) : array();
        $sub = isset( $_POST['sub'] ) ? json_decode( wp_unslash( $_POST['sub'] ), true ) : array();
        if ( is_array( $top ) ) {
            foreach ( $top as $row ) {
                $wpdb->update( $this->table_categories, array( 'sort_order' => absint( $row['sort_order'] ), 'parent_id' => 0 ), array( 'id' => absint( $row['id'] ) ) );
            }
        }
        if ( is_array( $sub ) ) {
            foreach ( $sub as $row ) {
                $wpdb->update( $this->table_categories, array( 'sort_order' => absint( $row['sort_order'] ), 'parent_id' => absint( $row['parent_id'] ) ), array( 'id' => absint( $row['id'] ) ) );
            }
        }
        wp_send_json_success();
    }

    public function ajax_save_item_order() {
        check_ajax_referer( 'swpb_order', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        global $wpdb;
        $order = isset( $_POST['order'] ) ? json_decode( wp_unslash( $_POST['order'] ), true ) : array();
        if ( is_array( $order ) ) {
            foreach ( $order as $row ) {
                $wpdb->update( $this->table_items, array( 'sort_order' => absint( $row['sort_order'] ) ), array( 'id' => absint( $row['id'] ) ) );
            }
        }
        wp_send_json_success();
    }

    public function ajax_get_categories() {
        check_ajax_referer( 'swpb_get_categories', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        $pricelist_id = isset( $_POST['pricelist_id'] ) ? absint( $_POST['pricelist_id'] ) : 0;
        $categories = array();

        if ( $pricelist_id ) {
            foreach ( $this->get_categories_for_admin( $pricelist_id ) as $category ) {
                $label = ( (int) $category->parent_id > 0 ) ? '— ' . $category->name : $category->name;
                $categories[] = array(
                    'id'   => (int) $category->id,
                    'name' => $label,
                );
            }
        }

        wp_send_json_success( array( 'categories' => $categories ) );
    }

    private function redirect_with_notice( $page, $type, $message ) {
        $args = array(
            'page'        => $page,
            'swpb_notice' => rawurlencode( $message ),
            'swpb_type'   => $type,
        );

        $context_keys = array( 'pricelist_id', 'category_id' );
        foreach ( $context_keys as $key ) {
            if ( isset( $_POST[ $key ] ) ) {
                $args[ $key ] = absint( wp_unslash( $_POST[ $key ] ) );
            } elseif ( isset( $_GET[ $key ] ) ) {
                $args[ $key ] = absint( wp_unslash( $_GET[ $key ] ) );
            }
        }

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_notice() {
        if ( empty( $_GET['swpb_notice'] ) ) {
            return;
        }
        $class = ( ! empty( $_GET['swpb_type'] ) && 'error' === $_GET['swpb_type'] ) ? 'notice-error' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( wp_unslash( $_GET['swpb_notice'] ) ) . '</p></div>';
    }

    private function get_pricelists() {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->table_pricelists} ORDER BY name ASC" );
    }

    private function get_categories_for_admin( $pricelist_id = 0 ) {
        global $wpdb;
        if ( $pricelist_id ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_categories} WHERE pricelist_id = %d ORDER BY parent_id ASC, sort_order ASC, name ASC", $pricelist_id ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$this->table_categories} ORDER BY pricelist_id ASC, parent_id ASC, sort_order ASC, name ASC" );
    }

    private function get_items_for_admin( $pricelist_id = 0, $category_id = 0 ) {
        global $wpdb;
        if ( $category_id ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_items} WHERE category_id = %d ORDER BY sort_order ASC, name ASC", $category_id ) );
        }
        if ( $pricelist_id ) {
            return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_items} WHERE pricelist_id = %d ORDER BY category_id ASC, sort_order ASC, name ASC", $pricelist_id ) );
        }
        return $wpdb->get_results( "SELECT * FROM {$this->table_items} ORDER BY pricelist_id ASC, category_id ASC, sort_order ASC, name ASC LIMIT 300" );
    }


    private function get_plugin_version() {
        if ( ! function_exists( 'get_file_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_file_data( __FILE__, array( 'Version' => 'Version' ), 'plugin' );
        return ! empty( $data['Version'] ) ? $data['Version'] : self::VERSION;
    }

    private function render_admin_hero( $title, $description, $compact = false ) {
        ?>
        <div class="swpb-hero<?php echo $compact ? ' swpb-hero--compact' : ''; ?>">
            <div class="swpb-hero__content">
                <span class="swpb-badge"><?php echo esc_html__( 'Smart Websites', 'sw-price-builder' ); ?></span>
                <h1><?php echo esc_html( $title ); ?></h1>
                <p><?php echo esc_html( $description ); ?></p>
            </div>
            <div class="swpb-hero__meta">
                <div class="swpb-stat">
                    <strong><?php echo esc_html( $this->get_plugin_version() ); ?></strong>
                    <span><?php echo esc_html__( 'Verze pluginu', 'sw-price-builder' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }

    public function render_dashboard_page() {
        global $wpdb;
        $counts = array(
            'pricelists' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_pricelists}" ),
            'categories' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_categories}" ),
            'items'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_items}" ),
        );
        ?>
        <div class="wrap swpb-admin-wrap">
            <?php $this->render_notice(); ?>
            <?php $this->render_admin_hero( 'Ceníky', 'Univerzální builder ceníků pro služby, produkty i balíčky. Plugin umí více ceníků na jednom webu, podporuje kategorie a je připravený i pro weby s agresivní cache nebo Varnishem.' ); ?>

            <div class="swpb-cards-grid swpb-cards-grid--stats">
                <div class="swpb-card"><span class="swpb-card-label">Ceníky</span><strong><?php echo (int) $counts['pricelists']; ?></strong></div>
                <div class="swpb-card"><span class="swpb-card-label">Kategorie</span><strong><?php echo (int) $counts['categories']; ?></strong></div>
                <div class="swpb-card"><span class="swpb-card-label">Položky</span><strong><?php echo (int) $counts['items']; ?></strong></div>
            </div>

            <div class="swpb-panel-grid">
                <section class="swpb-panel">
                    <h2>Jak plugin funguje</h2>
                    <p>Každý ceník má vlastní nastavení, kategorie a položky. Na frontendu lze použít buď dynamický výstup přes REST endpoint s no-cache hlavičkami, nebo přímé HTML vyrenderování.</p>
                    <div class="swpb-code-block"><code>[sw_pricelist id="1"]</code></div>
                    <div class="swpb-code-block"><code>[sw_pricelist id="1" layout="cards"]</code></div>
                    <div class="swpb-code-block"><code>[sw_pricelist_updated id="1"]</code></div>
                </section>
                <section class="swpb-panel">
                    <h2>Doporučení</h2>
                    <ul class="swpb-list-clean">
                        <li>Pro weby s Varnishem nebo agresivní page cache používej dynamický režim.</li>
                        <li>Pokud si chceš vzhled řešit po svém, vypni výchozí frontend CSS.</li>
                        <li>Pro služby a tarify se hodí layout karty, pro klasické ceníky tabulka.</li>
                    </ul>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_pricelists_page() {
        global $wpdb;
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $pricelist = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_pricelists} WHERE id = %d", $edit_id ) ) : null;
        $pricelists = $this->get_pricelists();
        ?>
        <div class="wrap swpb-admin-wrap">
            <?php $this->render_notice(); ?>
            <?php $this->render_admin_hero( 'Ceníky', 'Zde vytvoříš jednotlivé ceníky a nastavíš jejich základní výchozí chování.', true ); ?>
            <div class="swpb-admin-grid">
                <section class="swpb-panel">
                    <h2><?php echo $pricelist ? 'Upravit ceník' : 'Nový ceník'; ?></h2>
                    <form method="post" class="swpb-form-grid">
                        <?php wp_nonce_field( 'swpb_save_pricelist' ); ?>
                        <input type="hidden" name="swpb_action" value="save_pricelist">
                        <input type="hidden" name="id" value="<?php echo $pricelist ? (int) $pricelist->id : 0; ?>">
                        <label><span>Název</span><input type="text" name="name" value="<?php echo esc_attr( $pricelist->name ?? '' ); ?>" required></label>
                        <label class="swpb-field-full"><span>Popis</span><textarea name="description" rows="4"><?php echo esc_textarea( $pricelist->description ?? '' ); ?></textarea></label>
                        <label><span>Výchozí layout</span><select name="layout"><option value="table" <?php selected( $pricelist->layout ?? 'table', 'table' ); ?>>Tabulka</option><option value="cards" <?php selected( $pricelist->layout ?? '', 'cards' ); ?>>Karty</option><option value="list" <?php selected( $pricelist->layout ?? '', 'list' ); ?>>Seznam</option></select></label>
                        <label><span>Měna</span><input type="text" name="currency_symbol" value="<?php echo esc_attr( $pricelist->currency_symbol ?? 'Kč' ); ?>"></label>
                        <label><span>Pozice měny</span><select name="currency_position"><option value="after" <?php selected( $pricelist->currency_position ?? 'after', 'after' ); ?>>Za číslem</option><option value="before" <?php selected( $pricelist->currency_position ?? '', 'before' ); ?>>Před číslem</option></select></label>
                        <label><span>Výchozí suffix</span><input type="text" name="default_suffix" value="<?php echo esc_attr( $pricelist->default_suffix ?? '' ); ?>" placeholder="/ hod"></label>
                        <label><span>Text bez ceny</span><input type="text" name="empty_price_text" value="<?php echo esc_attr( $pricelist->empty_price_text ?? 'Na dotaz' ); ?>"></label>
                        <label><span>Wrapper class</span><input type="text" name="css_class" value="<?php echo esc_attr( $pricelist->css_class ?? '' ); ?>"></label>
                        <label class="swpb-checkbox"><input type="checkbox" name="is_active" value="1" <?php checked( isset( $pricelist->is_active ) ? $pricelist->is_active : 1, 1 ); ?>> <span>Ceník je aktivní</span></label>
                        <div class="swpb-actions swpb-field-full"><button type="submit" class="button button-primary">Uložit ceník</button></div>
                    </form>
                </section>
                <section class="swpb-panel">
                    <h2>Přehled ceníků</h2>
                    <div class="swpb-table-wrap-admin">
                        <table class="widefat striped">
                            <thead><tr><th>Název</th><th>Shortcode</th><th>Layout</th><th>Akce</th></tr></thead>
                            <tbody>
                            <?php if ( empty( $pricelists ) ) : ?>
                                <tr><td colspan="4">Zatím zde není žádný ceník.</td></tr>
                            <?php else : ?>
                                <?php foreach ( $pricelists as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( $row->name ); ?></td>
                                        <td><code>[sw_pricelist id="<?php echo (int) $row->id; ?>"]</code></td>
                                        <td><?php echo esc_html( $row->layout ); ?></td>
                                        <td class="swpb-row-actions">
                                            <a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'swpb_pricelists', 'edit' => (int) $row->id ), admin_url( 'admin.php' ) ) ); ?>">Upravit</a>
                                            <form method="post" onsubmit="return confirm('Opravdu smazat celý ceník včetně kategorií a položek?');">
                                                <?php wp_nonce_field( 'swpb_delete_pricelist' ); ?>
                                                <input type="hidden" name="swpb_action" value="delete_pricelist">
                                                <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
                                                <button type="submit" class="button button-small">Smazat</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_categories_page() {
        global $wpdb;
        $selected_pricelist = isset( $_GET['pricelist_id'] ) ? absint( $_GET['pricelist_id'] ) : 0;
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $category = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_categories} WHERE id = %d", $edit_id ) ) : null;
        if ( ! $selected_pricelist && $category ) {
            $selected_pricelist = (int) $category->pricelist_id;
        }
        $pricelists = $this->get_pricelists();
        $categories = $selected_pricelist ? $this->get_categories_for_admin( $selected_pricelist ) : array();
        $top = array_filter( $categories, function( $cat ) { return 0 === (int) $cat->parent_id; } );
        ?>
        <div class="wrap swpb-admin-wrap">
            <?php $this->render_notice(); ?>
            <div class="swpb-hero swpb-hero--compact"><div><h1>Kategorie</h1><p>U jednoho ceníku můžeš mít hlavní kategorie i podkategorie. Pořadí lze měnit přetažením.</p></div></div>
            <form method="get" class="swpb-filter-bar">
                <input type="hidden" name="page" value="swpb_categories">
                <label><span>Ceník</span><select name="pricelist_id" onchange="this.form.submit()"><option value="0">Vyber ceník</option><?php foreach ( $pricelists as $pricelist_row ) : ?><option value="<?php echo (int) $pricelist_row->id; ?>" <?php selected( $selected_pricelist, (int) $pricelist_row->id ); ?>><?php echo esc_html( $pricelist_row->name ); ?></option><?php endforeach; ?></select></label>
            </form>
            <div class="swpb-admin-grid">
                <section class="swpb-panel">
                    <h2><?php echo $category ? 'Upravit kategorii' : 'Nová kategorie'; ?></h2>
                    <form method="post" class="swpb-form-grid">
                        <?php wp_nonce_field( 'swpb_save_category' ); ?>
                        <input type="hidden" name="swpb_action" value="save_category">
                        <input type="hidden" name="id" value="<?php echo $category ? (int) $category->id : 0; ?>">
                        <label><span>Ceník</span><select name="pricelist_id" required><option value="">Vyber ceník</option><?php foreach ( $pricelists as $pricelist_row ) : ?><option value="<?php echo (int) $pricelist_row->id; ?>" <?php selected( $selected_pricelist ? $selected_pricelist : (int) ( $category->pricelist_id ?? 0 ), (int) $pricelist_row->id ); ?>><?php echo esc_html( $pricelist_row->name ); ?></option><?php endforeach; ?></select></label>
                        <label><span>Nadřazená kategorie</span><select name="parent_id"><option value="0">Bez nadřazené kategorie</option><?php foreach ( $top as $cat ) : ?><option value="<?php echo (int) $cat->id; ?>" <?php selected( $category->parent_id ?? 0, (int) $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option><?php endforeach; ?></select></label>
                        <label><span>Název</span><input type="text" name="name" value="<?php echo esc_attr( $category->name ?? '' ); ?>" required></label>
                        <label class="swpb-field-full"><span>Popis</span><textarea name="description" rows="4"><?php echo esc_textarea( $category->description ?? '' ); ?></textarea></label>
                        <label class="swpb-checkbox"><input type="checkbox" name="is_active" value="1" <?php checked( isset( $category->is_active ) ? $category->is_active : 1, 1 ); ?>> <span>Kategorie je aktivní</span></label>
                        <div class="swpb-actions swpb-field-full"><button type="submit" class="button button-primary">Uložit kategorii</button></div>
                    </form>
                </section>
                <section class="swpb-panel">
                    <h2>Struktura kategorií</h2>
                    <?php if ( ! $selected_pricelist ) : ?>
                        <p>Nejprve vyber ceník.</p>
                    <?php else : ?>
                        <ul class="swpb-sortable-tree" id="swpb-category-tree">
                            <?php foreach ( $top as $cat ) : ?>
                                <li class="swpb-tree-item" data-id="<?php echo (int) $cat->id; ?>">
                                    <div class="swpb-tree-row"><span class="swpb-handle">↕</span><strong><?php echo esc_html( $cat->name ); ?></strong><span class="swpb-tree-actions"><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'swpb_categories', 'pricelist_id' => $selected_pricelist, 'edit' => (int) $cat->id ), admin_url( 'admin.php' ) ) ); ?>">Upravit</a><form method="post" onsubmit="return confirm('Opravdu smazat kategorii?');"><?php wp_nonce_field( 'swpb_delete_category' ); ?><input type="hidden" name="swpb_action" value="delete_category"><input type="hidden" name="id" value="<?php echo (int) $cat->id; ?>"><button type="submit">Smazat</button></form></span></div>
                                    <?php $children = array_filter( $categories, function( $child ) use ( $cat ) { return (int) $child->parent_id === (int) $cat->id; } ); ?>
                                    <?php if ( ! empty( $children ) ) : ?>
                                        <ul class="swpb-sortable-subtree">
                                            <?php foreach ( $children as $child ) : ?>
                                                <li class="swpb-tree-item" data-id="<?php echo (int) $child->id; ?>"><div class="swpb-tree-row"><span class="swpb-handle">↕</span><?php echo esc_html( $child->name ); ?><span class="swpb-tree-actions"><a href="<?php echo esc_url( add_query_arg( array( 'page' => 'swpb_categories', 'pricelist_id' => $selected_pricelist, 'edit' => (int) $child->id ), admin_url( 'admin.php' ) ) ); ?>">Upravit</a><form method="post" onsubmit="return confirm('Opravdu smazat kategorii?');"><?php wp_nonce_field( 'swpb_delete_category' ); ?><input type="hidden" name="swpb_action" value="delete_category"><input type="hidden" name="id" value="<?php echo (int) $child->id; ?>"><button type="submit">Smazat</button></form></span></div></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_items_page() {
        global $wpdb;
        $selected_pricelist = isset( $_GET['pricelist_id'] ) ? absint( $_GET['pricelist_id'] ) : 0;
        $selected_category  = isset( $_GET['category_id'] ) ? absint( $_GET['category_id'] ) : 0;
        $edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
        $item = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_items} WHERE id = %d", $edit_id ) ) : null;
        if ( $item ) {
            $selected_pricelist = (int) $item->pricelist_id;
            $selected_category = (int) $item->category_id;
        }
        $pricelists = $this->get_pricelists();
        $categories = $selected_pricelist ? $this->get_categories_for_admin( $selected_pricelist ) : array();
        $items = $this->get_items_for_admin( $selected_pricelist, $selected_category );
        ?>
        <div class="wrap swpb-admin-wrap">
            <?php $this->render_notice(); ?>
            <div class="swpb-hero swpb-hero--compact"><div><h1>Položky</h1><p>Položky lze řadit přetažením v rámci konkrétní kategorie. Kromě číselné ceny můžeš použít i vlastní text, například „Na dotaz“ nebo „Dle rozsahu“.</p></div></div>
            <form method="get" class="swpb-filter-bar swpb-filter-bar--double">
                <input type="hidden" name="page" value="swpb_items">
                <label><span>Ceník</span><select name="pricelist_id" onchange="this.form.submit()"><option value="0">Vyber ceník</option><?php foreach ( $pricelists as $pricelist_row ) : ?><option value="<?php echo (int) $pricelist_row->id; ?>" <?php selected( $selected_pricelist, (int) $pricelist_row->id ); ?>><?php echo esc_html( $pricelist_row->name ); ?></option><?php endforeach; ?></select></label>
                <label><span>Kategorie</span><select name="category_id" onchange="this.form.submit()"><option value="0">Všechny kategorie</option><?php foreach ( $categories as $cat ) : ?><option value="<?php echo (int) $cat->id; ?>" <?php selected( $selected_category, (int) $cat->id ); ?>><?php echo esc_html( ( (int) $cat->parent_id > 0 ? "— " : "" ) . $cat->name ); ?></option><?php endforeach; ?></select></label>
            </form>
            <div class="swpb-admin-grid swpb-admin-grid--wide">
                <section class="swpb-panel">
                    <h2><?php echo $item ? 'Upravit položku' : 'Nová položka'; ?></h2>
                    <form method="post" class="swpb-form-grid swpb-item-form">
                        <?php wp_nonce_field( 'swpb_save_item' ); ?>
                        <input type="hidden" name="swpb_action" value="save_item">
                        <input type="hidden" name="id" value="<?php echo $item ? (int) $item->id : 0; ?>">
                        <label><span>Ceník</span><select name="pricelist_id" class="swpb-item-pricelist" required><option value="">Vyber ceník</option><?php foreach ( $pricelists as $pricelist_row ) : ?><option value="<?php echo (int) $pricelist_row->id; ?>" <?php selected( $selected_pricelist, (int) $pricelist_row->id ); ?>><?php echo esc_html( $pricelist_row->name ); ?></option><?php endforeach; ?></select></label>
                        <label><span>Kategorie</span><select name="category_id" class="swpb-item-category" data-selected="<?php echo (int) $selected_category; ?>" required><option value="">Vyber kategorii</option><?php foreach ( $categories as $cat ) : ?><option value="<?php echo (int) $cat->id; ?>" <?php selected( $selected_category, (int) $cat->id ); ?>><?php echo esc_html( ( (int) $cat->parent_id > 0 ? "— " : "" ) . $cat->name ); ?></option><?php endforeach; ?></select></label>
                        <label class="swpb-field-full"><span>Název položky</span><input type="text" name="name" value="<?php echo esc_attr( $item->name ?? '' ); ?>" required></label>
                        <label><span>Kód</span><input type="text" name="code" value="<?php echo esc_attr( $item->code ?? '' ); ?>"></label>
                        <label><span>Badge</span><input type="text" name="badge" value="<?php echo esc_attr( $item->badge ?? '' ); ?>" placeholder="Doporučeno"></label>
                        <label><span>Cena 1</span><input type="text" name="price_primary" value="<?php echo esc_attr( $item->price_primary ?? '' ); ?>"></label>
                        <label><span>Label ceny 1</span><input type="text" name="price_primary_label" value="<?php echo esc_attr( $item->price_primary_label ?? '' ); ?>" placeholder="Základ"></label>
                        <label><span>Cena 2</span><input type="text" name="price_secondary" value="<?php echo esc_attr( $item->price_secondary ?? '' ); ?>"></label>
                        <label><span>Label ceny 2</span><input type="text" name="price_secondary_label" value="<?php echo esc_attr( $item->price_secondary_label ?? '' ); ?>" placeholder="Premium"></label>
                        <label><span>Prefix ceny</span><input type="text" name="price_prefix" value="<?php echo esc_attr( $item->price_prefix ?? '' ); ?>" placeholder="od"></label>
                        <label><span>Suffix ceny</span><input type="text" name="price_suffix" value="<?php echo esc_attr( $item->price_suffix ?? '' ); ?>" placeholder="/ hod"></label>
                        <label><span>Text místo ceny</span><input type="text" name="price_text" value="<?php echo esc_attr( $item->price_text ?? '' ); ?>" placeholder="Na dotaz"></label>
                        <label><span>Poznámka k ceně</span><input type="text" name="price_note" value="<?php echo esc_attr( $item->price_note ?? '' ); ?>"></label>
                        <label class="swpb-field-full"><span>Popis</span><textarea name="description" rows="4"><?php echo esc_textarea( $item->description ?? '' ); ?></textarea></label>
                        <label class="swpb-checkbox"><input type="checkbox" name="include_in_range" value="1" <?php checked( isset( $item->include_in_range ) ? $item->include_in_range : 1, 1 ); ?>> <span>Zahrnout do budoucího výpočtu rozsahu cen</span></label>
                        <label class="swpb-checkbox"><input type="checkbox" name="is_featured" value="1" <?php checked( isset( $item->is_featured ) ? $item->is_featured : 0, 1 ); ?>> <span>Zvýrazněná položka</span></label>
                        <label class="swpb-checkbox"><input type="checkbox" name="is_active" value="1" <?php checked( isset( $item->is_active ) ? $item->is_active : 1, 1 ); ?>> <span>Položka je aktivní</span></label>
                        <div class="swpb-actions swpb-field-full"><button type="submit" class="button button-primary">Uložit položku</button></div>
                    </form>
                </section>
                <section class="swpb-panel">
                    <h2>Seznam položek</h2>
                    <div class="swpb-table-wrap-admin">
                        <table class="widefat striped">
                            <thead><tr><th></th><th>Název</th><th>Kategorie</th><th>Cena</th><th>Akce</th></tr></thead>
                            <tbody id="swpb-items-sortable" data-category-id="<?php echo (int) $selected_category; ?>">
                            <?php if ( empty( $items ) ) : ?>
                                <tr><td colspan="5">Zatím zde nejsou žádné položky.</td></tr>
                            <?php else : ?>
                                <?php foreach ( $items as $row ) : ?>
                                    <?php $cat_name = ''; foreach ( $categories as $cat ) { if ( (int) $cat->id === (int) $row->category_id ) { $cat_name = $cat->name; break; } } ?>
                                    <tr data-id="<?php echo (int) $row->id; ?>">
                                        <td class="swpb-order-cell">↕</td>
                                        <td><?php echo esc_html( $row->name ); ?></td>
                                        <td><?php echo esc_html( $cat_name ); ?></td>
                                        <td><?php echo esc_html( ! empty( $row->price_text ) ? $row->price_text : $this->format_price( (object) array( 'currency_symbol' => 'Kč', 'currency_position' => 'after', 'default_suffix' => '' ), $row->price_primary, $row->price_prefix, $row->price_suffix ) ); ?></td>
                                        <td class="swpb-row-actions">
                                            <a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'page' => 'swpb_items', 'pricelist_id' => $selected_pricelist, 'category_id' => $selected_category, 'edit' => (int) $row->id ), admin_url( 'admin.php' ) ) ); ?>">Upravit</a>
                                            <form method="post" onsubmit="return confirm('Opravdu smazat položku?');">
                                                <?php wp_nonce_field( 'swpb_delete_item' ); ?>
                                                <input type="hidden" name="swpb_action" value="delete_item">
                                                <input type="hidden" name="id" value="<?php echo (int) $row->id; ?>">
                                                <button type="submit" class="button button-small">Smazat</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ( ! $selected_category ) : ?><p class="description">Pro přetažení pořadí položek vyber konkrétní kategorii.</p><?php endif; ?>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_settings_page() {
        $settings = $this->get_settings();
        ?>
        <div class="wrap swpb-admin-wrap">
            <?php $this->render_notice(); ?>
            <?php $this->render_admin_hero( 'Nastavení', 'Globální nastavení pluginu. Klíčové je hlavně chování výstupu na frontendu a práce s výchozím CSS.', true ); ?>
            <section class="swpb-panel swpb-panel--narrow">
                <form method="post" class="swpb-form-grid">
                    <?php wp_nonce_field( 'swpb_save_settings' ); ?>
                    <input type="hidden" name="swpb_action" value="save_settings">
                    <label><span>Režim výstupu</span><select name="frontend_mode"><option value="dynamic" <?php selected( $settings['frontend_mode'], 'dynamic' ); ?>>Dynamický přes REST endpoint bez cache</option><option value="direct" <?php selected( $settings['frontend_mode'], 'direct' ); ?>>Přímý HTML výstup</option></select></label>
                    <label class="swpb-checkbox"><input type="checkbox" name="load_frontend_css" value="1" <?php checked( $settings['load_frontend_css'], '1' ); ?>> <span>Načítat výchozí frontend CSS pluginu</span></label>
                    <label class="swpb-checkbox"><input type="checkbox" name="show_codes" value="1" <?php checked( $settings['show_codes'], '1' ); ?>> <span>Zobrazovat kód položky v tabulkovém layoutu</span></label>
                    <label class="swpb-checkbox"><input type="checkbox" name="show_update_date" value="1" <?php checked( $settings['show_update_date'], '1' ); ?>> <span>Zobrazovat datum poslední aktualizace ceníku</span></label>
                    <div class="swpb-actions swpb-field-full"><button type="submit" class="button button-primary">Uložit nastavení</button></div>
                </form>
            </section>
        </div>
        <?php
    }
}

SW_Price_Builder::instance();

}
