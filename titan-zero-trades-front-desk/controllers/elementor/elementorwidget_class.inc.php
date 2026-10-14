<?php
// phpcs:disable WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin-owned custom tables. Direct queries are intentional: caching these results would serve stale bookings. Values are bound with prepare() or %i placeholders; queries built from fragments are reviewed where marked.

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly


/**
 * Elementor oEmbed Widget.
 *
 * Elementor widget that inserts an embbedable content into the page, from any given URL.
 *
 * @since 1.0.0
 */
class TZFD_Elementor_Hour_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * Retrieve widget name.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'Titan Zero Front Desk'; // machine id, kept so saved Elementor pages still match
	}

	/**
	 * Get widget title.
	 *
	 * Retrieve widget title.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Widget title.
	 */
	public function get_title() {
		return 'Titan Zero Trades: Front Desk';
	}

	/**
	 * Get widget icon.
	 *
	 * Retrieve widget icon.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-apps';
	}

	/**
	 * Get widget categories.
	 *
	 * Retrieve the list of categories the oEmbed widget belongs to.
	 *
	 * @since 1.0.0
	 * @access public
	 *
	 * @return array Widget categories.
	 */
	public function get_categories() {
		return [ 'tzfd-widgets' ];
	}

	/**
	 * Register oEmbed widget controls.
	 *
	 * Adds different input fields to allow the user to change and customize the widget settings.
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	protected function _register_controls() {

        global $wpdb, $tzfd_plugin;
        
		$this->start_controls_section(
			'content_section',
			[
				'label' => __( 'Insert Titan Zero Trades: Front Desk', 'titan-zero-trades-front-desk' ),
				'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
			]
		);

        
        $forms = array();
        $tzfd_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id,form_name FROM %i ORDER BY form_name', $wpdb->prefix . 'tzfd_forms' ) );
        foreach ($tzfd_rows as $tzfd_item)
           $forms[$tzfd_item->id] = $tzfd_item->form_name;
                
		$this->add_control(
			'formid',
			[
				'label' => __( 'Select Form', 'titan-zero-trades-front-desk' ),
				'type' => \Elementor\Controls_Manager::SELECT,
                'default' => '1',
				'options' => $forms,
			]
		);

		$this->end_controls_section();

	}

	/**
	 * Render widget output on the frontend.
	 *
	 * Written in PHP and used to generate the final HTML.
	 *
	 * @since 1.0.0
	 * @access protected
	 */
	protected function render() {
        global $tzfd_plugin;

        
        $settings = $this->get_settings_for_display();
        $id = $settings["formid"];
        
        $tzfd_plugin->setId($id);
        
        if ( ! \Elementor\Plugin::instance()->editor->is_edit_mode() ) 
        {
            $tzfd_plugin->output_filter_content( array("id" => intval($id)) );
            return;
        } 
        else
        {
            $GLOBALS['titan_zero_front_desk_form_sequence_number'] = wp_rand(99999,999999);
            $counter = $GLOBALS['titan_zero_front_desk_form_sequence_number']+1;
            define('TZFD_HOUR_ELEMENTOR_EDIT_MODE', true);            
            echo '<fieldset class="tzfdgutenberg_editor" disabled>';
            $tzfd_plugin->output_filter_content( array("id" => intval($id)) );
            echo '</fieldset>';
            // The builder loader is js/inline/adminb-elementor-builder.js; the counter is passed in a data attribute.
            wp_enqueue_script( 'tzfd-adminb-elementor-builder', plugins_url( 'js/inline/adminb-elementor-builder.js', dirname( __FILE__, 3 ) . '/titan-zero-trades-front-desk.php' ), array( 'jquery' ), '2.0.1', true );
            echo '<span hidden data-tzfd-builder="' . intval( $counter ) . '"></span>';
        }

	}

}

\Elementor\Plugin::instance()->widgets_manager->register_widget_type(new TZFD_Elementor_Hour_Widget());