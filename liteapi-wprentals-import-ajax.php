<?php
/**
 * Plugin Name: LiteApi review Importer
 * Description: Import review from liteapi API to WP wprentals
 * Version: 1.0
 * Author: Your Name
 * License: GPL v2 or later
 * 
 * This plugin fetches real estate listings from the liteapi API and imports them
 * into a WP Residence website via REST API. It provides an admin interface to
 * start the import process and displays the fetched properties in a table format.
 */

// Prevent direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Plugin Configuration Constants
 * 
 * These constants define the API credentials and default search parameters.
 * Modify these values to change the plugin behavior without editing the code.
 */
define('RCWPR_LITEAPI_API_KEY', 'sand_cea622be-f1f4-42a9-9015-a1f9f07255a2'); // LiteAPI API key
define('HOTEL_ID', 'lp1897');                                          // Hotel ID to search
define('RCWPR_LIMIT', '15');                                              // Number of reviews to fetch
define('RCWPR_WP_RENTALS_URL', 'https://rentals.me');                // Target WPRentals site URL
define('RCWPR_WP_USERNAME', 'cretu');                                    // WPRentals username
define('RCWPR_WP_PASSWORD', 'remus');                                    // WPRentals password
define('RCWPR_PROPERTY_ID', 124);                                        // Default property ID for imported reviews

/**
 * Add admin menu item for the plugin
 * 
 * Creates a new menu item in the WordPress admin dashboard under the main menu.
 * The menu item leads to the import interface where users can start the import process.
 * 
 * @since 1.0.0
 * @hook admin_menu
 */
add_action('admin_menu', 'liteapi_add_admin_menu');

/**
 * Register the admin menu page
 * 
 * Adds a top-level menu page to the WordPress admin dashboard.
 * Only users with 'manage_options' capability can access this page.
 * 
 * @since 1.0.0
 */
function liteapi_add_admin_menu() {
    add_menu_page(
        'liteapi Importer',        // Page title
        'liteapi Importer',        // Menu title 
        'manage_options',           // Required capability
        'liteapi-importer',        // Menu slug
        'liteapi_admin_page',      // Callback function
        'dashicons-download'        // Icon
    );
}

/**
 * Display the admin page interface
 * 
 * Renders the HTML for the plugin's admin page, including:
 * - Start Import button to trigger the import process
 * - Status div to show import progress messages
 * - Table div to display fetched properties
 * 
 * The page uses AJAX to communicate with the backend without page refresh.
 * 
 * @since 1.0.0
 */
function liteapi_admin_page() {
    ?>
    <div class="wrap">
        <h1>liteapi Property Importer</h1>
        
        <!-- Import trigger button -->
        <button id="start-import" class="button button-primary">Start Import</button>
        
        <!-- Status messages display area -->
        <div id="import-status"></div>
        
        <!-- Properties table display area -->
        <div id="properties-table"></div>
    </div>
    <?php
}

/**
 * Handle AJAX import request
 * 
 * This function is called when the "Start Import" button is clicked.
 * It performs the following steps:
 * 1. Uses test property data (temporarily replacing liteapi API)
 * 2. Validates the data
 * 3. Generates an HTML table to display the properties
 * 4. Imports the data to WP Residence
 * 5. Returns the results via AJAX response
 * 
 * @since 1.0.0
 * @hook wp_ajax_start_import
 */
add_action('wp_ajax_start_import', 'handle_liteapi_import');

/**
 * Process the LiteAPI Review import - MODIFIED
 * 
 * Main import function that handles:
 * - API request to LiteAPI
 * - Data validation and error handling
 * - HTML table generation for display
 * - AJAX response formatting
 * 
 * @since 1.0.0
 * @return void Outputs JSON response and exits
 */
function handle_liteapi_import() {

    error_log('handle_liteapi_import: AJAX request received.');

    // Build the LiteAPI Reviews URL - FIXED to match working cURL
    $hotel_id = HOTEL_ID; // Reuse existing constant for hotel ID
    $url = 'https://api.liteapi.travel/v3.0/data/reviews?hotelId=' . $hotel_id . '&limit=' . RCWPR_LIMIT . '&timeout=4&getSentiment=false';
    error_log('handle_liteapi_import: Requesting LiteAPI URL ' . $url);
    // Make HTTP GET request to LiteAPI using cURL directly (since wp_remote_get hangs)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-API-Key: ' . RCWPR_LITEAPI_API_KEY,
        'Accept: application/json'
    ));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress/LiteAPI-Plugin');
    
    $body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    error_log('handle_liteapi_import: LiteAPI HTTP status ' . $http_code . ' (error: ' . ($error !== '' ? $error : 'none') . ')');
    curl_close($ch);



    // Check for cURL errors
    if ($error) {
        wp_send_json_error('cURL Error: ' . $error);
        error_log('handle_liteapi_import: Aborting due to cURL error.');
        return;
    }

    // Check HTTP response code
    if ($http_code !== 200) {
        wp_send_json_error('LiteAPI returned HTTP ' . $http_code . '. Response: ' . substr($body, 0, 200));
        error_log('handle_liteapi_import: LiteAPI non-200 response, aborting. Body snippet: ' . substr($body, 0, 500));
        return;
    }
    $response_data = json_decode($body, true);
    error_log('handle_liteapi_import: LiteAPI raw body length ' . strlen($body));

    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error('Invalid JSON response from API: ' . json_last_error_msg());
        error_log('handle_liteapi_import: JSON decode error - ' . json_last_error_msg());
        return;
    }

    // Debug: Log the raw response
    error_log('LiteAPI Response: ' . $body);
    
    // Check if the response contains an error
    if (isset($response_data['error'])) {
        wp_send_json_error('API Error: ' . $response_data['error']);
        error_log('handle_liteapi_import: API reported error - ' . print_r($response_data['error'], true));
        return;
    }
    
    // Extract reviews from response
    $reviews = null;
    if (is_array($response_data)) {
        if (isset($response_data['data']) && is_array($response_data['data'])) {
            $reviews = $response_data['data'];
        } elseif (isset($response_data[0]) && is_array($response_data[0])) {
            $reviews = $response_data;
        }
    }
    
    // Validate that we received review data
    if (empty($reviews) || !is_array($reviews)) {
        wp_send_json_error('No valid reviews found in API response. Response structure: ' . print_r($response_data, true));
        error_log('handle_liteapi_import: No reviews found - ' . print_r($response_data, true));
        return;
    }

    error_log('handle_liteapi_import: Retrieved ' . count($reviews) . ' reviews from LiteAPI.');

    // Generate HTML table for displaying reviews
    $table_html = '<table class="wp-list-table widefat fixed striped">';
    
    // Table header
    $table_html .= '<thead><tr>';
    $table_html .= '<th>Date</th>';
    $table_html .= '<th>Rating</th>';
    $table_html .= '<th>Reviewer</th>';
    $table_html .= '<th>Comment</th>';
    $table_html .= '<th>Language</th>';
    $table_html .= '</tr></thead>';
    
    // Table body - loop through each review
    $table_html .= '<tbody>';
    foreach ($reviews as $review) {
        if (!is_array($review)) {
            continue;
        }
        
        $table_html .= '<tr>';
        $table_html .= '<td>' . esc_html(isset($review['date']) ? $review['date'] : 'N/A') . '</td>';
        $table_html .= '<td>' . esc_html(isset($review['averageScore']) ? $review['averageScore'] . '/10' : 'N/A') . '</td>';
        $table_html .= '<td>' . esc_html(isset($review['name']) ? $review['name'] : 'Anonymous') . '</td>';
        $table_html .= '<td>' . esc_html(wp_trim_words(isset($review['pros']) ? $review['pros'] : (isset($review['headline']) ? $review['headline'] : ''), 20)) . '</td>';
        $table_html .= '<td>' . esc_html(isset($review['language']) ? $review['language'] : 'N/A') . '</td>';
        $table_html .= '</tr>';
    }
    $table_html .= '</tbody></table>';
    
    // Import to WPRentals
    import_to_wp_rentals($reviews);

    error_log('handle_liteapi_import: Finished pushing reviews to WPRentals. Preparing AJAX response.');

    $response_payload = array(
        'table' => $table_html,
        'count' => count($reviews)
    );

    error_log('handle_liteapi_import: Responding with payload: ' . json_encode($response_payload));

    // Send success response
    wp_send_json_success($response_payload);
}


/**
 * Get JWT token from WP Residence site using cURL
 * 
 * @since 1.0.0
 * @return string|false JWT token on success, false on failure
 */
function get_wp_residence_token() {
    $token_url = RCWPR_WP_RENTALS_URL . '/wp-json/jwt-auth/v1/token';
    
    $args = array(
        'body' => wp_json_encode(array(
            'username' => RCWPR_WP_USERNAME,
            'password' => RCWPR_WP_PASSWORD
        )),
        'headers' => array(
            'Content-Type' => 'application/json'
        ),
        'timeout' => 30,  // FIXED: Changed from 300 to 30 seconds
        'sslverify' => false
    );
    
    error_log('Making wp_remote_post request to: ' . $token_url);
    
    $response = wp_remote_post($token_url, $args);
    
    if (is_wp_error($response)) {
        error_log('wp_remote_post error: ' . $response->get_error_message());
        return false;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    error_log('wp_remote_post response code: ' . $http_code);
    error_log('wp_remote_post response: ' . $body);
    
    if ($http_code != 200) {
        error_log('HTTP error: ' . $http_code);
        return false;
    }
    
    $data = json_decode($body, true);
    
    if (isset($data['token'])) {
        error_log('Token retrieved successfully via wp_remote_post');
        return $data['token'];
    }
    
    error_log('No token found in response');
    return false;
}

/**
 * Import reviews to WPRentals site - MODIFIED
 * 
 * @since 1.0.0
 * @param array $reviews Array of review data
 * @return void
 */
function import_to_wp_rentals($reviews) {
    // Get JWT token for authentication
    $token = get_wp_residence_token();

    if (!$token) {
        error_log('Failed to get JWT token - skipping WPRentals import but continuing with display');
        return;
    }

    // Build the WPRentals API endpoint URL for reviews
    $wp_residence_url = RCWPR_WP_RENTALS_URL . '/wp-json/wprentals/v1/reviews';

    error_log('Starting review import to: ' . $wp_residence_url);

    // Process each review individually
    foreach ($reviews as $index => $review) {

        if (!is_array($review)) {
            continue;
        }

        // Map LiteAPI review to WPRentals format
        $wp_review = rcwpr_map_review_payload($review);

        if (empty($wp_review['comment'])) {
            error_log('Skipping review ' . ($index + 1) . ' because the generated comment is empty.');
            continue;
        }

        error_log('Importing review ' . ($index + 1) . ': ' . (isset($review['name']) ? $review['name'] : 'Anonymous'));
        error_log('Review data being sent: ' . json_encode($wp_review, JSON_PRETTY_PRINT));

        // Send POST request to WPRentals API
        $response = wp_remote_post($wp_residence_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $token
            ),
            'body' => wp_json_encode($wp_review),
            'timeout' => 30,
            'sslverify' => false
        ));
        
        // Log the result
        if (is_wp_error($response)) {
            error_log('Review Import Error: ' . $response->get_error_message());
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            error_log('Review Import Response: Code ' . $response_code . ' - ' . $response_body);
            
            if (in_array($response_code, array(200, 201), true)) {
                error_log('SUCCESS: Review imported successfully');
            } elseif ($response_code === 409) {
                error_log('NOTICE: Review already exists in WPRentals (409 Conflict).');
            } else {
                error_log('FAILED: Review import failed with code ' . $response_code);
            }
        }
    }
    
    error_log('Finished importing all reviews');
}

/**
 * Map a LiteAPI review to the payload expected by the WPRentals endpoint.
 *
 * The Postman documentation referenced in the task specifies the following fields:
 * - property_id (required)
 * - reviewer_name
 * - reviewer_email
 * - rating (1-5 integer)
 * - title (short summary of the review)
 * - comment (full review body)
 * - language
 * - country
 * - travel_type
 * - source
 * - date (Y-m-d H:i:s)
 * - status (approved|pending)
 * - external_id (used to avoid duplicate imports)
 *
 * The LiteAPI payload does not include all these fields directly, so we derive as much
 * information as possible and provide sensible defaults for the rest.
 *
 * @param array $review LiteAPI review item.
 * @return array
 */
function rcwpr_map_review_payload($review) {
    $name = isset($review['name']) && $review['name'] !== '' ? $review['name'] : 'Anonymous';
    $email = isset($review['reviewer_email']) && is_email($review['reviewer_email'])
        ? $review['reviewer_email']
        : rcwpr_generate_placeholder_email($name);

    $headline = isset($review['headline']) ? trim(wp_strip_all_tags($review['headline'])) : '';
    $pros = isset($review['pros']) ? trim(wp_strip_all_tags($review['pros'])) : '';
    $cons = isset($review['cons']) ? trim(wp_strip_all_tags($review['cons'])) : '';

    $comment_sections = array();

    if ($pros !== '') {
        $comment_sections[] = $pros;
    }

    if ($cons !== '') {
        $comment_sections[] = 'Cons: ' . $cons;
    }

    if ($headline !== '') {
        array_unshift($comment_sections, $headline);
    }

    if (empty($comment_sections) && isset($review['review'])) {
        $comment_sections[] = trim(wp_strip_all_tags($review['review']));
    }

    $comment = trim(implode("\n\n", array_filter($comment_sections, 'strlen')));

    $rating_value = isset($review['averageScore']) ? floatval($review['averageScore']) : 0;
    $rating = $rating_value > 0 ? max(1, min(5, round($rating_value / 2))) : 5;

    $language = isset($review['language']) ? sanitize_text_field($review['language']) : '';
    $country = isset($review['country']) ? sanitize_text_field($review['country']) : '';
    $travel_type = isset($review['type']) ? sanitize_text_field($review['type']) : '';
    $source = isset($review['source']) ? sanitize_text_field($review['source']) : 'liteapi';

    $date = rcwpr_normalize_review_date(isset($review['date']) ? $review['date'] : '');

    $title = $headline !== '' ? $headline : wp_html_excerpt($comment, 80, '...');

    return array(
        'property_id' => RCWPR_PROPERTY_ID,
        'reviewer_name' => $name,
        'reviewer_email' => $email,
        'rating' => $rating,
        'title' => $title,
        'comment' => $comment,
        'language' => $language,
        'country' => $country,
        'travel_type' => $travel_type,
        'type' => $travel_type,
        'source' => $source,
        'date' => $date,
        'status' => 'approved',
        'external_id' => rcwpr_generate_external_id($review)
    );
}

/**
 * Normalise the review date to the format expected by the API (Y-m-d H:i:s).
 *
 * @param string $date_string
 * @return string
 */
function rcwpr_normalize_review_date($date_string) {
    if (empty($date_string)) {
        return current_time('mysql', true);
    }

    $timestamp = strtotime($date_string);

    if ($timestamp === false) {
        return current_time('mysql', true);
    }

    return gmdate('Y-m-d H:i:s', $timestamp);
}

/**
 * Generate a stable hash for the review so we can prevent duplicates on the API side.
 *
 * @param array $review
 * @return string
 */
function rcwpr_generate_external_id($review) {
    $parts = array(
        isset($review['date']) ? $review['date'] : '',
        isset($review['name']) ? $review['name'] : '',
        isset($review['averageScore']) ? $review['averageScore'] : '',
        isset($review['pros']) ? $review['pros'] : '',
        isset($review['cons']) ? $review['cons'] : ''
    );

    return md5(implode('|', $parts));
}

/**
 * Create a deterministic placeholder email address when the review does not provide one.
 *
 * @param string $name Reviewer name.
 * @return string
 */
function rcwpr_generate_placeholder_email($name) {
    $sanitized_name = sanitize_title($name);

    if ($sanitized_name === '') {
        $sanitized_name = 'guest-' . substr(md5($name), 0, 6);
    }

    return strtolower($sanitized_name) . '@example.com';
}

/**
 * Enqueue admin scripts and styles
 * 
 * @since 1.0.0
 * @param string $hook The current admin page hook
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook == 'toplevel_page_liteapi-importer') {
        wp_enqueue_script('jquery');
        
        wp_enqueue_script(
            'rcwpr-admin-js',
            plugin_dir_url(__FILE__) . 'rcwpr-admin.js',
            array('jquery'),
            '1.0',
            true
        );
        
        wp_enqueue_style(
            'rcwpr-admin-css',
            plugin_dir_url(__FILE__) . 'rcwpr-admin.css',
            array(),
            '1.0'
        );
        
        wp_localize_script('rcwpr-admin-js', 'rcwpr_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php')
        ));
    }
});
?>