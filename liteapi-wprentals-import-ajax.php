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
define('RCWPR_LITEAPI_API_KEY', 'xxx2'); // LiteAPI API key
define('HOTEL_ID', 'lp1897');                                          // Hotel ID to search
define('RCWPR_LIMIT', '15');                                              // Number of reviews to fetch
define('RCWPR_WP_RENTALS_URL', 'https://rentals.me');                // Target WPRentals site URL
define('RCWPR_WP_USERNAME', 'cretu');                                    // WPRentals username
define('RCWPR_WP_PASSWORD', 'remus');                                    // WPRentals password
define('RCWPR_PROPERTY_ID', 124);                                        // Default property ID for imported reviews
define('RCWPR_REVIEW_USER_ID', 1);                                       // Default WP user ID authoring the imported reviews
define('RCWPR_REVIEW_CONTENT_LIMIT', 4000);                              // Maximum number of characters sent to WPRentals per review
define('RCWPR_REVIEW_REQUEST_TIMEOUT', 45);                              // Initial timeout (seconds) for review POST requests
define('RCWPR_REVIEW_REQUEST_MAX_TIMEOUT', 120);                         // Maximum timeout (seconds) when retrying
define('RCWPR_REVIEW_MAX_ATTEMPTS', 3);                                  // Maximum retry attempts for review POST requests

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



    // Build the LiteAPI Reviews URL - FIXED to match working cURL
    $hotel_id = HOTEL_ID; // Reuse existing constant for hotel ID
    $url = 'https://api.liteapi.travel/v3.0/data/reviews?hotelId=' . $hotel_id . '&limit=' . RCWPR_LIMIT . '&timeout=4&getSentiment=false';



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
   
    curl_close($ch);



    // Check for cURL errors
    if ($error) {
        wp_send_json_error('cURL Error: ' . $error);
 
        return;
    }

    // Check HTTP response code
    if ($http_code !== 200) {
        wp_send_json_error('LiteAPI returned HTTP ' . $http_code . '. Response: ' . substr($body, 0, 200));
    
        return;
    }
    $response_data = json_decode($body, true);


    // Check for JSON decode errors
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error('Invalid JSON response from API: ' . json_last_error_msg());
      
        return;
    }

    // Check if the response contains an error
    if (isset($response_data['error'])) {
        wp_send_json_error('API Error: ' . $response_data['error']);
      
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
      
        return;
    }


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

  
    $response_payload = array(
        'table' => $table_html,
        'count' => count($reviews)
    );


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
    

    
    $response = wp_remote_post($token_url, $args);
    
    if (is_wp_error($response)) {
        error_log('wp_remote_post error: ' . $response->get_error_message());
        return false;
    }
    
    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    

    
    if ($http_code != 200) {

        return false;
    }
    
    $data = json_decode($body, true);
    
    if (isset($data['token'])) {

        return $data['token'];
    }
    

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

        return;
    }

    // Build the WPRentals API endpoint URL for reviews
    $wp_residence_url = RCWPR_WP_RENTALS_URL . '/wp-json/wprentals/v1/post-review';



    // Process each review individually
    foreach ($reviews as $index => $review) {

        if (!is_array($review)) {
            continue;
        }

        // Map LiteAPI review to WPRentals format
        $wp_review = rcwpr_map_review_payload($review);

        if (empty($wp_review['content'])) {
        
            continue;
        }

      

        // Send POST request to WPRentals API with retry logic to handle timeouts
        $response = rcwpr_post_review_with_retry($wp_residence_url, $wp_review, $token);

        // Log the result
        if (is_wp_error($response)) {
          
        } else {
            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
       
            
            if (in_array($response_code, array(200, 201), true)) {
            
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
 * Send review payload to the WPRentals endpoint with retry logic for timeouts.
 *
 * Large review bodies can cause the remote site to respond slowly. To improve
 * resiliency we retry timed-out requests with a progressively higher timeout
 * and short backoff between attempts.
 *
 * @since 1.0.0
 * @param string $url      WPRentals endpoint.
 * @param array  $payload  Prepared review payload.
 * @param string $token    JWT token for authentication.
 * @return array|WP_Error  Response array on success, WP_Error on failure.
 */
function rcwpr_post_review_with_retry($url, $payload, $token) {
    $encoded_body = wp_json_encode($payload);

    if ($encoded_body === false) {
        return new WP_Error('json_encode_error', __('Failed to encode review payload.', 'liteapi-wprentals-importer'));
    }

    $attempts = max(1, (int) RCWPR_REVIEW_MAX_ATTEMPTS);
    $timeout  = max(1, (int) RCWPR_REVIEW_REQUEST_TIMEOUT);
    $max_timeout = max($timeout, (int) RCWPR_REVIEW_REQUEST_MAX_TIMEOUT);

    $last_error = null;
    $payload_bytes = strlen($encoded_body);
    $use_curl = function_exists('curl_init') && function_exists('curl_exec');

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        error_log(sprintf('Review Import Attempt %d/%d with timeout %d seconds (payload %d bytes)', $attempt, $attempts, $timeout, $payload_bytes));

        $start_time = microtime(true);

        if ($use_curl) {
            $response = rcwpr_curl_post_json($url, $encoded_body, $token, $timeout);
        } else {
            $response = wp_remote_post($url, array(
                'headers' => array(
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                    'Expect'        => '',
                ),
                'body'      => $encoded_body,
                'timeout'   => $timeout,
                'sslverify' => false,
            ));
        }

        $elapsed = microtime(true) - $start_time;

        if (!is_wp_error($response)) {
            error_log(sprintf('Review Import Attempt %d succeeded in %.2f seconds', $attempt, $elapsed));
            return $response;
        }

        $last_error = $response;
        error_log(sprintf('Review Import Attempt %d failed after %.2f seconds: %s', $attempt, $elapsed, $response->get_error_message()));

        if ($attempt === $attempts || !rcwpr_is_timeout_error($response)) {
            break;
        }

        $timeout = min($timeout + 30, $max_timeout);

        $wait_time = min(5 * $attempt, 15);
        rcwpr_sleep($wait_time);
    }

    return $last_error instanceof WP_Error ? $last_error : new WP_Error('review_request_failed', __('Unable to post review.', 'liteapi-wprentals-importer'));
}

/**
 * Execute a JSON POST request using cURL so we can bypass the WordPress HTTP
 * API timeout ceiling when large reviews take longer to process.
 *
 * @since 1.0.0
 * @param string $url          Endpoint URL.
 * @param string $encoded_body JSON encoded payload.
 * @param string $token        Bearer token.
 * @param int    $timeout      Timeout (seconds).
 * @return array|WP_Error      WP HTTP style response array or error.
 */
function rcwpr_curl_post_json($url, $encoded_body, $token, $timeout) {
    if (!function_exists('curl_init') || !function_exists('curl_exec')) {
        return new WP_Error('curl_missing', __('cURL functions are not available.', 'liteapi-wprentals-importer'));
    }

    $ch = curl_init($url);

    if (!$ch) {
        return new WP_Error('curl_init_failed', __('Unable to initialise cURL session.', 'liteapi-wprentals-importer'));
    }

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $token,
        'Content-Length: ' . strlen($encoded_body),
        'Expect:',
    );

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded_body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $timeout));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    $response_body = curl_exec($ch);
    $curl_errno    = curl_errno($ch);
    $curl_error    = curl_error($ch);
    $http_code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $total_time    = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    $download_size = (float) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);

    curl_close($ch);

    if ($curl_errno) {
        $message = rcwpr_format_curl_error($curl_errno, $curl_error, $http_code, $total_time);

        return new WP_Error(
            'curl_error_' . $curl_errno,
            $message,
            array(
                'curl_error' => $curl_error,
                'curl_errno' => $curl_errno,
                'http_code'  => $http_code,
                'total_time' => $total_time,
            )
        );
    }

    if ($http_code === 0) {
        return new WP_Error('curl_no_http_code', __('No HTTP status returned from cURL request.', 'liteapi-wprentals-importer'));
    }

    error_log(sprintf('Review Import HTTP %d in %.2f seconds (response %d bytes)', $http_code, $total_time, (int) $download_size));

    return rcwpr_build_wp_like_response($http_code, $response_body);
}

/**
 * Create a minimal array that matches the structure expected by the
 * wp_remote_* helper accessors.
 *
 * @since 1.0.0
 * @param int    $status_code HTTP status code.
 * @param string $body        Response body.
 * @param array  $headers     Optional response headers.
 * @return array
 */
function rcwpr_build_wp_like_response($status_code, $body, $headers = array()) {
    return array(
        'headers'  => $headers,
        'body'     => $body,
        'response' => array(
            'code'    => $status_code,
            'message' => function_exists('get_status_header_desc') ? get_status_header_desc($status_code) : '',
        ),
    );
}

/**
 * Build a descriptive cURL error message including context we log elsewhere.
 *
 * @since 1.0.0
 * @param int    $errno      cURL error code.
 * @param string $error_text Error message.
 * @param int    $http_code  HTTP status code seen by cURL.
 * @param float  $total_time Total time spent on the request.
 * @return string
 */
function rcwpr_format_curl_error($errno, $error_text, $http_code, $total_time) {
    $parts = array(sprintf('cURL error %d: %s', $errno, $error_text !== '' ? $error_text : __('unknown error', 'liteapi-wprentals-importer')));

    if ($http_code > 0) {
        $parts[] = sprintf(__('HTTP status %d', 'liteapi-wprentals-importer'), $http_code);
    }

    if ($total_time > 0) {
        $parts[] = sprintf(__('elapsed %.2f seconds', 'liteapi-wprentals-importer'), $total_time);
    }

    return implode(' | ', $parts);
}

/**
 * Determine if the WP_Error represents a transport timeout.
 *
 * @since 1.0.0
 * @param WP_Error $error Error instance from wp_remote_post.
 * @return bool True when the error is timeout-related.
 */
function rcwpr_is_timeout_error($error) {
    if (!($error instanceof WP_Error)) {
        return false;
    }

    $error_code = $error->get_error_code();

    if ($error_code === 'http_request_timeout' || $error_code === 'curl_error_28') {
        return true;
    }

    $message = $error->get_error_message();

    if (!is_string($message) || $message === '') {
        return false;
    }

    return stripos($message, 'timed out') !== false || stripos($message, 'cURL error 28') !== false;
}

/**
 * Wrapper around wp_sleep()/sleep() to wait between retry attempts.
 *
 * @since 1.0.0
 * @param int $seconds Number of seconds to pause.
 * @return void
 */
function rcwpr_sleep($seconds) {
    $seconds = (int) $seconds;

    if ($seconds <= 0) {
        return;
    }

    if (function_exists('wp_sleep')) {
        wp_sleep($seconds);
    } else {
        sleep($seconds);
    }
}

/**
 * Map a LiteAPI review to the payload expected by the WPRentals endpoint.
 *
 * The user requested payload includes only the identifiers, review body and a hard-coded
 * set of 1-star category ratings required by the WPRentals endpoint.
 *
 * @param array $review LiteAPI review item.
 * @return array Minimal payload for the WPRentals review import endpoint.
 */
function rcwpr_map_review_payload($review) {
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

    $title = $headline !== '' ? $headline : wp_html_excerpt($comment, 80, '...');

    $title = rcwpr_prepare_review_title($title);
    $comment = rcwpr_prepare_review_content($comment);

    if ($comment !== '') {
        $comment = rcwpr_limit_text_length($comment, RCWPR_REVIEW_CONTENT_LIMIT);
    }

    return array(
        'property_id' => RCWPR_PROPERTY_ID,
        'user_id' => RCWPR_REVIEW_USER_ID,
        'ratings' => array(
            'accuracy' => 1,
            'communication' => 1,
            'cleanliness' => 1,
            'location' => 1,
            'check_in' => 1,
            'value' => 1,
        ),
        'title' => $title,
        'content' => $comment,
    );
}

/**
 * Prepare the review title for safe API submission.
 *
 * Normalises whitespace, removes unsafe characters and enforces a reasonable
 * length so that the WPRentals endpoint receives a compact, UTF-8 clean value.
 *
 * @since 1.0.0
 * @param string $title Raw title string.
 * @return string Sanitised single-line title.
 */
function rcwpr_prepare_review_title($title) {
    $title = rcwpr_normalise_review_text($title);
    $title = sanitize_text_field($title);

    if ($title === '') {
        return __('Review', 'liteapi-wprentals-importer');
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($title) > 140) {
            $title = rtrim(mb_substr($title, 0, 137)) . '...';
        }
    } elseif (strlen($title) > 140) {
        $title = rtrim(substr($title, 0, 137)) . '...';
    }

    return $title;
}

/**
 * Prepare the review content for safe API submission.
 *
 * Ensures multi-line text keeps intentional paragraph breaks while stripping
 * problematic control characters that may cause the remote API to hang.
 *
 * @since 1.0.0
 * @param string $content Raw content string.
 * @return string Sanitised multi-line content.
 */
function rcwpr_prepare_review_content($content) {
    $content = rcwpr_normalise_review_text($content, true);
    $content = rcwpr_limit_text_length($content, 800);
    $content = sanitize_textarea_field($content);

    return $content;
}

/**
 * Normalise incoming review text by removing control characters, decoding
 * entities and reducing whitespace while optionally retaining newlines.
 *
 * @since 1.0.0
 * @param string  $text         Raw text value.
 * @param boolean $allow_breaks Whether to keep line breaks.
 * @return string Cleaned text.
 */
function rcwpr_normalise_review_text($text, $allow_breaks = false) {
    if (!is_string($text)) {
        return '';
    }

    $text = html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // Replace common Unicode bullets and dashes with ASCII equivalents.
    $text = str_replace(array("\xE2\x80\xA2", "\xE2\x80\xA3", "\xE2\x97\x8F"), '- ', $text);
    $text = str_replace(array("\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x80\x95"), '-', $text);

    // Normalise whitespace and remove control characters except allowed breaks.
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);
    $text = preg_replace('/[\x00-\x08\x0B-\x0C\x0E-\x1F\x7F]/u', '', $text);

    if ($allow_breaks) {
        $text = preg_replace("/\s*\n\s*/u", "\n", $text);
    } else {
        $text = preg_replace('/\s+/u', ' ', $text);
    }

    // Collapse multiple spaces and convert non-breaking spaces.
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/[ \t]+/u', ' ', $text);

    if ($allow_breaks) {
        $text = preg_replace('/\n{2,}/u', "\n\n", $text);
    }

    $text = rcwpr_transliterate_to_ascii($text);

    return trim($text);
}

/**
 * Convert UTF-8 text into a safe ASCII subset for API transport.
 *
 * Uses iconv transliteration when available and removes any remaining
 * non-printable characters while preserving tabs and newlines.
 *
 * @since 1.0.0
 * @param string $text Input text.
 * @return string ASCII-only string.
 */
function rcwpr_transliterate_to_ascii($text) {
    if (!is_string($text) || $text === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($converted !== false) {
            $text = $converted;
        }
    }

    return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
}

/**
 * Limit text length while preserving word boundaries where possible.
 *
 * @since 1.0.0
 * @param string $text       Raw text value.
 * @param int    $max_length Maximum allowed length.
 * @param string $ellipsis   Trailing suffix applied to truncated text.
 * @return string Length-limited text.
 */
function rcwpr_limit_text_length($text, $max_length, $ellipsis = '...') {
    if (!is_string($text)) {
        return '';
    }

    $text = trim($text);

    if ($text === '' || $max_length <= 0) {
        return '';
    }

    $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

    if ($length <= $max_length) {
        return $text;
    }

    $ellipsis_length = function_exists('mb_strlen') ? mb_strlen($ellipsis) : strlen($ellipsis);
    $slice_length = max(0, $max_length - $ellipsis_length);

    if ($slice_length === 0) {
        return $ellipsis_length === 0 ? substr($text, 0, $max_length) : $ellipsis;
    }

    $slice = function_exists('mb_substr')
        ? mb_substr($text, 0, $slice_length)
        : substr($text, 0, $slice_length);

    $slice = rtrim($slice);

    // Attempt to trim back to the last whitespace to avoid mid-word truncation.
    if (preg_match('/^(.+?)\s+[^\s]*$/u', $slice, $matches)) {
        $slice = $matches[1];
    }

    return $slice . $ellipsis;
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
