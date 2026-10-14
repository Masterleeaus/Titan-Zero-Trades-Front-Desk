<?php

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


if (!class_exists('TZFD_tzfd_Elementor'))
{    
    class TZFD_tzfd_Elementor {
          
      private $prefix = 'tzfdhour';           
           
      function __construct() { 
              // Register widget for Elementor builder.
              add_action('elementor/widgets/widgets_registered', array($this, 'register_elementor_widget'));
              // Register 10Web category for Elementor widget if 10Web builder doesn't installed.
              add_action('elementor/elements/categories_registered', array($this, 'register_widget_category'), 1, 1);
              //fires after elementor editor styles and scripts are enqueued.
              add_action('elementor/editor/after_enqueue_styles', array($this, 'enqueue_editor_styles'), 11);
              // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request value used for display or routing; this code path changes no state.
              if ((isset($_GET["action"]) && sanitize_text_field( wp_unslash( $_GET["action"] ?? '' ) ) == 'elementor') || (isset($_GET["elementor-preview"]) ))
                  add_action('elementor/frontend/after_enqueue_styles', array($this, 'enqueue_frontend_styles'), 11);
      }
         
      function enqueue_editor_styles() {
         // wp_enqueue_style('cfte-editor-styles', plugins_url('/elementor_icon.css', __FILE__), array(), '1.0.0');
      }      
         
      function enqueue_frontend_styles() {
          $tzfd_root = dirname( __FILE__, 3 ) . '/titan-zero-trades-front-desk.php';
          wp_enqueue_style('tzfdhour-calendarstyle', plugin_dir_url( $tzfd_root ) . 'css/cupertino/calendar.css', array(), '1.0.0');
          wp_enqueue_style('tzfdhour-publicstyle', plugin_dir_url( $tzfd_root ) . 'css/stylepublic.css', array(), '1.0.0');
        
          wp_enqueue_script( $this->prefix.'_builder_script',
                 get_site_url( get_current_blog_id() ).'?tzfd_resources=public&nc=1',array("jquery","jquery-ui-core","jquery-ui-datepicker","jquery-ui-widget","jquery-ui-position","jquery-ui-tooltip"), '1.0.0', true );          
      }      
    
      /**
       * Register widget for Elementor builder.
       */
      function register_elementor_widget() {
          if ( defined('ELEMENTOR_PATH') && class_exists('Elementor\Widget_Base') ) {
              require_once dirname( __FILE__ ) . '/elementorwidget_class.inc.php';
          }
      }
    
      /**
       * Register 10Web category for Elementor widget if 10Web builder doesn't installed.
       *
       * @param $elements_manager
       */
      function register_widget_category( $elements_manager ) {
          $elements_manager->add_category(
                                          'tzfd-widgets', array(
                                          'title' => __('Titan Zero Trades: Front Desk', 'titan-zero-trades-front-desk'),
                                          'icon' => 'fa fa-calendar',
                                          ));
      }
      
    }

}

$tzfd_cfte_elementor_widget = new TZFD_tzfd_Elementor;

