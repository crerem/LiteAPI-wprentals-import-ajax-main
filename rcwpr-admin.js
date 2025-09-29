/**
 * liteapi Property Importer - Admin JavaScript
 * 
 * Handles the frontend interaction for the property import process.
 * Uses jQuery and WordPress AJAX to communicate with the backend.
 */

// Wait for DOM to be fully loaded before binding events
jQuery(document).ready(function($) {
    
    /**
     * Handle click event on the "Start Import" button
     * 
     * This function:
     * 1. Disables the button to prevent multiple clicks
     * 2. Shows a loading message to the user
     * 3. Makes an AJAX call to fetch and process properties
     * 4. Displays results or error messages
     * 5. Re-enables the button when complete
     */
    $('#start-import').click(function() {
        
        // Store reference to the clicked button
        var button = $(this);
        
        // Disable button and change text to show it's working
        // This prevents users from clicking multiple times
        button.prop('disabled', true).text('Importing...');
        
        // Show initial status message to user
        $('#import-status').html('<p>Fetching reviews from liteapi API...</p>');
        
        // Make AJAX request to WordPress backend
        $.ajax({
            // Use WordPress AJAX URL (passed from PHP via wp_localize_script)
            url: rcwpr_ajax.ajax_url,
            
            // POST method required for WordPress AJAX
            method: 'POST',
            
            // Data to send to the server
            data: {
                action: 'start_import'  // WordPress action hook name
            },
            
            /**
             * Handle successful AJAX response
             * 
             * @param {Object} response - Server response object
             * @param {boolean} response.success - Whether the operation succeeded
             * @param {Object} response.data - Response data (table HTML, error message, etc.)
             */
            success: function(response) {
                console.log(response);
                // Check if server operation was successful
                if (response.success) {
                    // Show success message with green styling
                    $('#import-status').html('<p class="success-message">Import completed successfully!</p>');
                    
                    // Display the properties table HTML returned from server
                    $('#properties-table').html(response.data.table);
                } else {
                    // Show error message with red styling
                    // response.data contains the error message from server
                    $('#import-status').html('<p class="error-message">Error: ' + response.data + '</p>');
                }
            },
            
            /**
             * Handle AJAX request errors
             * 
             * This catches network errors, server timeouts, etc.
             * Different from server-side errors which are handled in success()
             */
            error: function() {
                // Show generic AJAX error message
                $('#import-status').html('<p class="error-message">AJAX error occurred</p>');
            },
            
            /**
             * Always executed after success or error
             * 
             * Used for cleanup tasks that should happen regardless
             * of whether the request succeeded or failed
             */
            complete: function() {
                // Re-enable the button and restore original text
                // This allows users to try again if needed
                button.prop('disabled', false).text('Start Import');
            }
        });
    });
});