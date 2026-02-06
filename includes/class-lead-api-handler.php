<?php
/**
 * Lead API Handler Class
 * 
 * Handles API communication for retrieving lead data
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Lead_API_Handler {
    
    public $notion_client;
    
    public function __construct() {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-lead-api-notion-client.php';
        $token = get_option('buero_leads_notion_token');
        if ($token) {
            $this->notion_client = new NotionApiClient($token);
        }
    }

    
    /**
     * Get lead data from API
     * 
     * @param string $id The ID to query
     * @return array|WP_Error Lead data array or WP_Error on failure
     */
    /**
     * Get lead data from Notion
     * 
     * @param string $id The ID to query (matches "ID" property in Notion)
     * @param string $database_id The Notion Database ID
     * @return array|WP_Error Lead data array or WP_Error on failure
     */
    public function get_lead_data($id, $database_id = '') {
        // Validate ID
        if (empty($id) || !is_string($id)) {
            return new WP_Error('invalid_id', 'Invalid ID provided');
        }
        
        if (empty($database_id)) {
             return new WP_Error('missing_db_id', 'Notion Database ID is required');
        }
        
        if (!$this->notion_client) {
            return new WP_Error('missing_token', 'Notion Token is not configured');
        }
        
        // Get configured ID property name
        $id_property = get_option('buero_leads_notion_id_property', 'ID');
        
        // Query Notion Database
        // Filter: Property "$id_property" (rich_text) equals $id
        $pages = $this->notion_client->getManyDatabasePagesWithFilter(
            $database_id,
            $id_property,
            $id,
            'rich_text',
            'equals',
            1
        );
        
        if ($pages === null) {
             return new WP_Error('api_error', 'Notion API Error: ' . $this->notion_client->getLastError());
        }
        
        if (empty($pages)) {
            return array(); // No results found
        }
        
        $page = $pages[0];
        $properties = $page['properties'] ?? array();
        $page_id = $page['id'];
        // Flatten properties for easier consumption
        $flat_data = array();
        foreach ($properties as $key => $prop) {
            $flat_data[$key] = $this->notion_client->getValue($page, $key);
        }
        
        return array($flat_data, $page_id);
    }

    
/**
     * Update lead data from Notion
     * 
     * @param string $page_id The ID of the database page

     */
    public function update_lead_page_count($page_id, $column, $value) {


        if (empty($page_id) || !is_string($page_id)) {
            return false;
        }
        
        if (empty($column) || !is_string($column)) {
             return false;
        }
        
        if (!$this->notion_client) {
            return false;
        }
        
        try {
            $updated_page_response = $this->notion_client->updateDatabasePage(
                $page_id,
                array(
                    $column => $value
                )
            );
        } catch (Exception $e) {
            return $e;
        }
        
        
        return true;
    }


    public function get_lead_data_page ($page_id) {
        if (empty($page_id)){
            return "";
        }

        $page = $this->notion_client->getDatabasePage($page_id);
        if (empty($page)) {
            return "";
        }

        return $page;
    }
    /**
     * Test API connection
     * 
     * @return bool True if connection is successful, false otherwise
     */
    /**
     * Test API connection
     * 
     * @param string $token Optional token to test with (overrides stored token)
     * @return bool True if connection is successful, false otherwise
     */
    public function test_connection($token = '') {
        $client = $this->notion_client;
        
        if (!empty($token)) {
            require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-lead-api-notion-client.php';
            $client = new NotionApiClient($token);
        }
        
        if (!$client) {
            return false;
        }
        
        // Test by listing users (simple GET request)
        // We can't easily list users with the current client unless we add a method or use a raw request.
        // The client has private request method.
        // Let's try to query a non-existent database or just check if we can instantiate it.
        // Actually, the client doesn't throw on instantiation.
        // We need a valid API call.
        // Since we don't know a valid DB ID, we can't query a DB.
        // 'v1/users/me' is a good candidate for a "whoami" check.
        
        // But the client doesn't expose a generic request method publicly.
        // I'll assume for now that if we can't make a request, we can't test it easily without modifying the client.
        // However, I can modify the client to expose a `me()` method or similar, OR I can just try to query a dummy DB and check for a specific error (like "database not found" vs "unauthorized").
        
        // A better approach: The user wants to test the connection.
        // I will add a `testConnection()` method to NotionApiClient or just use `wp_remote_get` here directly for the test to keep it simple and not touch the client too much if not needed.
        
        $url = 'https://api.notion.com/v1/users/me';
        $args = [
            'headers' => [
                'Authorization'   => 'Bearer ' . ($token ?: get_option('buero_leads_notion_token')),
                'Notion-Version'  => '2022-06-28',
            ],
        ];
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        return $code === 200;
    }

}

