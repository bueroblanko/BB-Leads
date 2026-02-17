<?php
/**
 * Plugin Name: Buero Blanko Leads
 * Plugin URI: https://bueroblanko.de
 * Description: A plugin that retrieves lead information from an API and displays it via shortcode.
 * Version: 1.0.4
 * Author: bueroblanko
 * License: GPL v2 or later
 * Text Domain: buero-leads-plugin
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('BUERO_LEADS_PLUGIN_VERSION', '1.0.4');
define('BUERO_LEADS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('BUERO_LEADS_PLUGIN_PATH', plugin_dir_path(__FILE__));

// Include the API handler class
require_once BUERO_LEADS_PLUGIN_PATH . 'includes/class-lead-api-handler.php';

// Initialize the plugin
class BueroLeadsPlugin {
    
    private $api_handler;
    
    public function __construct() {
        $this->api_handler = new Lead_API_Handler();
        add_action('init', array($this, 'init'));
        
        // AJAX handler for testing connection
        add_action('wp_ajax_buero_leads_test_connection', array($this, 'ajax_test_connection'));
    }
    
    public function init() {
        // Register shortcodes
        add_shortcode('lead_info', array($this, 'handle_lead_info_shortcode'));

        
        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_rest_routes'));

        
        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Admin settings
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('buero-leads/v1', '/track_view', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_track_view'),
            'permission_callback' => function( WP_REST_Request $request ) {
                // $nonce = $request->get_param('nonce'); 
                // if ( ! wp_verify_nonce( $nonce, 'buero_leads_track_view' ) ) {
                //     return new WP_Error('bad_nonce', 'Invalid nonce '. $nonce, ['status' => 403]);
                // }

                return true;
            },
            'args' => array(
                'lead_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'page_id' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'column' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'nonce' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
    }
    
    /**
     * Handle tracking the views REST endpoint
     * 
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function handle_track_view($request) {
        $lead_id = $request->get_param('lead_id');
        $column = $request->get_param('column');
        $page_id = $request->get_param('page_id');
        if (empty($lead_id) || empty($column) || empty($page_id)) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => 'Some columns are required'
            ), 400);
        }

        // we should update the lead in notion using notionclient

        $maps = array (
            "page_view" => "page_view|number",
            "link1" => "link1|number",
            "link2" => "link2|number",
            "link3" => "link3|number"

        );

        if (!isset($maps[$column])) {
            return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'wow'
            ), 401);
        }
        $mapped_col = $maps[$column];
        try {
            // get the lead 
            $page = $this->api_handler->get_lead_data_page($page_id);
            if (empty($page)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'wow'
                ), 401);
            }

            // get previous count of that column
            $value =  $this->api_handler->notion_client->getValue($page, str_replace("|number" , "" , $mapped_col));

            if (ctype_digit($value)) { 
                return WP_REST_Response(array(
                    'success' => false,
                    'message' => 'sorry'
                ), 404);
            }


            $value = intval($value);
            // now we update 

            $r = $this->api_handler->update_lead_page_count(
                $page_id,
                $mapped_col,
                $value + 1
            );


            if ($r === false) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => 'wow'
                ), 401);
            }

            if ($r !== true) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $r->getMessage()
                ), 401);
            }
            $err = $this->api_handler->notion_client->getLastError();
            if (!empty($err)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $err
                ), 401);
            }

        } catch (\Throwable $th) {
            //throw $th;


            return new WP_REST_Response(array(
                    'success' => false,
                    'message' => $th->getMessage()
                ), 401);
        }

        
        return new WP_REST_Response(array(
            'success' => true,
            'message' => 'tracked successfully'
        ), 200);
    }




    /**
     * Handle the lead_info shortcode
     * 
     * @param array $atts Shortcode attributes
     * @return string The column value or default message
     */
    public function handle_lead_info_shortcode($atts) {
        // Parse shortcode attributes
        $atts = shortcode_atts(array(
            'column' => '',
            'notion_id' => '',
            'default' => ''
        ), $atts, 'lead_info');
        
        // Validate column parameter
        if (empty($atts['column'])) {
            return 'Error: Column parameter is required.';
        }
        
        // Get ID from query parameter
        $id = isset($_GET['id']) ? sanitize_text_field($_GET['id']) : '';
        
        if (empty($id)) {
            return !empty($atts['default']) ? $atts['default'] : "";
        }
        
        // Get lead data from API
        // Pass database ID if provided
        [$lead_data, $page_id] = $this->api_handler->get_lead_data($id, $atts['notion_id']);
        
        if (is_wp_error($lead_data)) {
            return !empty($atts['default']) ? $atts['default'] : "";
        }
        

        
        if (empty($lead_data) || !isset($lead_data[$atts['column']])) {
             return !empty($atts['default']) ? $atts['default'] : "";
        }
        
        return $lead_data[$atts['column']] . "<span style=\"display:none\" class=\"data_id_buero\">$page_id</span>";
    }
    

    
    /**
     * Enqueue scripts and styles
     */
    public function enqueue_scripts() {
        // Only enqueue on pages that have our shortcodes
        global $post;
        
        wp_enqueue_script('buero-leads-js', BUERO_LEADS_PLUGIN_URL . 'assets/buero-leads.js', array('jquery'), BUERO_LEADS_PLUGIN_VERSION, true);
        wp_enqueue_style('buero-leads-css', BUERO_LEADS_PLUGIN_URL . 'assets/buero-leads.css', array(), BUERO_LEADS_PLUGIN_VERSION);
        
        wp_localize_script('buero-leads-js', 'bueroLeads', array(
            'ajaxUrl' => rest_url('buero-leads/v1/track_view'),
            'nonce' => wp_create_nonce('buero_leads_track_view'),
        ));
        
    }
    public function add_admin_menu() {
        add_options_page(
            'Buero Leads Settings',
            'Buero Leads',
            'manage_options',
            'buero-leads-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {


        // Notion Token setting
        register_setting(
            'buero_leads_settings',
            'buero_leads_notion_token',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => ''
            )
        );
        
        // Notion ID Property setting
        register_setting(
            'buero_leads_settings',
            'buero_leads_notion_id_property',
            array(
                'type' => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default' => 'ID'
            )
        );
        
        add_settings_section(
            'buero_leads_general_section',
            'General Settings',
            array($this, 'settings_section_callback'),
            'buero-leads-settings'
        );
        
        


        


        add_settings_field(
            'buero_leads_notion_token',
            'Notion Integration Token',
            array($this, 'notion_token_field_callback'),
            'buero-leads-settings',
            'buero_leads_general_section'
        );

        add_settings_field(
            'buero_leads_notion_id_property',
            'Notion ID Property Name',
            array($this, 'notion_id_property_field_callback'),
            'buero-leads-settings',
            'buero_leads_general_section'
        );


    }
    
    /**
     * Settings section callback
     */
    public function settings_section_callback() {
        echo '<p>Configure the settings for the Buero Leads plugin.</p>';
    }
    


    /**
     * CV URL field callback
     */
    /**
     * Notion Token field callback
     */
    public function notion_token_field_callback() {
        $token = get_option('buero_leads_notion_token');
        echo '<input type="password" id="buero_leads_notion_token" name="buero_leads_notion_token" value="' . esc_attr($token) . '" class="regular-text" />';
        echo '<p class="description">Enter your Notion Integration Token (starts with ntn_...).</p>';
        echo '<button type="button" id="test-notion-connection" class="button button-secondary" style="margin-top: 10px;">Test Connection</button>';
        echo '<span id="connection-status" style="margin-left: 10px; font-weight: bold;"></span>';
        
        // Add inline script for the test button
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#test-notion-connection').on('click', function() {
                var token = $('#buero_leads_notion_token').val();
                var $status = $('#connection-status');
                
                if (!token) {
                    $status.css('color', 'red').text('Please enter a token first.');
                    return;
                }
                
                $status.css('color', 'blue').text('Testing...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'buero_leads_test_connection',
                        token: token
                    },
                    success: function(response) {
                        if (response.success) {
                            $status.css('color', 'green').text('Connection Successful!');
                        } else {
                            $status.css('color', 'red').text('Connection Failed: ' + (response.data || 'Unknown error'));
                        }
                    },
                    error: function() {
                        $status.css('color', 'red').text('Request failed.');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Notion ID Property field callback
     */
    public function notion_id_property_field_callback() {
        $property = get_option('buero_leads_notion_id_property', 'ID');
        echo '<input type="text" id="buero_leads_notion_id_property" name="buero_leads_notion_id_property" value="' . esc_attr($property) . '" class="regular-text" />';
        echo '<p class="description">Enter the name of the property in your Notion Database that contains the Lead ID (default: "ID").</p>';
    }

    


    
    /**
     * Portfolio URL field callback
     */



    /**
     * Settings page HTML
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Buero Leads Settings</h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('buero_leads_settings');
                do_settings_sections('buero-leads-settings');
                submit_button();
                ?>
            </form>
            
            <div class="card" style="max-width: 600px; margin-top: 20px;">
                <h2>Plugin Information</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Shortcode Usage:</th>
                        <td>
                            <code>[lead_info column="Notion Property Name" notion_id="DATABASE_ID" default="Default Value"]</code><br>
                            <p class="description">
                                <strong>column:</strong> The name of the property in Notion to display.<br>
                                <strong>notion_id:</strong> The ID of the Notion Database.<br>
                                <strong>default:</strong> (Optional) Value to show if lead is not found.
                            </p>
                        </td>

                    </tr>
                    <tr>
                        <th>For the column names to track:</th>
                        <td>
                            <code>page_view -> page_view</code><br>
                            <code>calendar_view -> link1</code><br>
                            <code>portfolio_view -> link2</code><br>
                            <code>cv_view -> link3</code><br>
                        </td>
                    </tr>
                    <tr>
                        <th>For the buttons</th>
                        <td>
                            - Add the class <code>bb-counter-button</code> to every button you want to track.<br>
                            - Add a html element that contains the destination url of each link 
                            <code>
                            &lt;p style="display:none" id="buero-link-targets"&gt;
                                &lt;span class="link1"&gt;https://destination-url1.com&lt;/span&gt;
                                &lt;span class="link2"&gt;https://destination-url2.com&lt;/span&gt;
                                &lt;span class="link3"&gt;https://destination-url3.com&lt;/span&gt;
                                &lt;span class="menu-class"&gt;dvmm_button_one|link1&lt;/span&gt;
                            &lt;/p&gt;</code> <br>
                            - Add an ID to identify the button type (e.g., <code>[link1, link2, link3]</code>).
                        </td>
                    </tr>
                    
                </table>
            </div>
        </div>
        <?php
    }

    


    /**
     * AJAX handler for testing Notion connection
     */
    public function ajax_test_connection() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }
        
        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
        
        if (empty($token)) {
            wp_send_json_error('Token is required');
        }
        
        // Temporarily set the token for the handler to use
        // We can't easily inject it into the existing handler instance if it reads from options
        // So we might need to modify the handler to accept a token in test_connection
        
        $is_connected = $this->api_handler->test_connection($token);
        
        if ($is_connected) {
            wp_send_json_success('Connection working');
        } else {
            wp_send_json_error('Connection failed');
        }
    }
}

// Initialize the plugin
new BueroLeadsPlugin();


require BUERO_LEADS_PLUGIN_PATH . 'plugin-update-checker/plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
	'https://github.com/bueroblanko/BB-Leads',
	BUERO_LEADS_PLUGIN_PATH . 'buero-leads-info-plugin.php', //Full path to the main plugin file or functions.php.
	'buero-leads-info-plugin'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');

//Optional: If you're using a private repository, specify the access token like this:
// $myUpdateChecker->setAuthentication('');