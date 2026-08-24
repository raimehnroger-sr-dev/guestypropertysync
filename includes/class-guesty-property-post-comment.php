<?php
/**
 * Property Post Comment Manager Class
 */

if (!defined('ABSPATH')) {
    exit;
}

class Guesty_Property_Post_Comment {

    public function __construct() {

        add_action('wp_enqueue_scripts', [ $this, 'guesty_enqueue_comment_script'] );

        // Form fields
        add_filter('comment_form_defaults', [$this, 'add_fields']);
        add_filter('comment_form_default_fields', [$this, 'remove_unwanted_fields']);

        // Validation & saving
        add_filter('preprocess_comment', [$this, 'validate_fields']);
        add_action('comment_post', [$this, 'save_fields']);

        // Frontend display
        add_filter('comment_text', [$this, 'render_comment_meta'], 10, 2);
        add_filter('comments_array', [$this, 'hide_unapproved_comments'], 10, 2);

        // Admin
        add_action('manage_comments_custom_column', [$this, 'render_admin_columns'], 10, 2);
        add_filter('manage_edit-comments_sortable_columns', [$this, 'sortable_columns']);
        add_action('pre_get_comments', [$this, 'handle_sorting']);

        add_action('add_meta_boxes_comment', [$this, 'add_comment_metabox']);
        add_action('edit_comment', [$this, 'save_admin_fields']);

        // Remove date/time
        add_filter('get_comment_date', '__return_empty_string');
        add_filter('get_comment_time', '__return_empty_string');
    }

    function guesty_enqueue_comment_script() {
        wp_enqueue_script('post-comment', plugin_dir_url(__FILE__) . '/js/guesty-post-comment.js', [], '1.0', true);
    }

    /**
     * Remove unwanted default fields
     */
    public function remove_unwanted_fields($fields) {

        unset($fields['url']);     // Website
        unset($fields['cookies']); // Save my info

        return $fields;
    }

    /**
     * Add custom fields
     */
    public function add_fields($defaults) {

        if (get_post_type() !== 'property') {
            return $defaults;
        }

        $unique = uniqid('rating_');

        ob_start();
        ?>

        <p class="comment-form-rating">
            <label>Rating <span class="required">*</span></label>
            <span class="rating-input">
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <input type="radio" id="<?php echo $unique . '_' . $i; ?>" name="rating" value="<?php echo $i; ?>" required />
                    <label for="<?php echo $unique . '_' . $i; ?>">★</label>
                <?php endfor; ?>
            </span>
        </p>

        <p class="comment-form-booking-date">
            <label>Booking Month <span class="required">*</span></label>
            <input 
                type="month" 
                name="booking_date" 
                max="<?php echo esc_attr(wp_date('Y-m')); ?>" 
                required 
            />
        </p>

        <?php
        echo wp_nonce_field('review_fields_action', 'review_fields_nonce', true, false);

        $defaults['comment_field'] .= ob_get_clean();

        return $defaults;
    }

    /**
     * Validate fields
     */
    public function validate_fields($commentdata) {

        if (get_post_type($commentdata['comment_post_ID']) !== 'property') {
            return $commentdata;
        }

        if (
            !isset($_POST['review_fields_nonce']) ||
            !wp_verify_nonce($_POST['review_fields_nonce'], 'review_fields_action')
        ) {
            wp_die('Security check failed.');
        }

        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field($_POST['booking_date']) : '';

        if (!$rating) {
            wp_die('Please select a rating.');
        }

        if (!$booking_date) {
            wp_die('Please select a booking month.');
        }

        // Prevent future dates
        if (strtotime($booking_date . '-01') > strtotime(wp_date('Y-m-01'))) {
            wp_die('Booking date cannot be in the future.');
        }

        return $commentdata;
    }

    /**
     * Save meta
     */
    public function save_fields($comment_id) {

        $comment = get_comment($comment_id);

        if (get_post_type($comment->comment_post_ID) !== 'property') {
            return;
        }

        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $booking_date = isset($_POST['booking_date']) ? sanitize_text_field($_POST['booking_date']) : '';

        if ($rating) {
            update_comment_meta($comment_id, 'rating', $rating);
        }

        if ($booking_date) {
            update_comment_meta($comment_id, 'booking_date', $booking_date);
        }
    }

    /**
     * Hide unapproved comments completely
     */
    public function hide_unapproved_comments($comments, $post_id) {

        if (get_post_type($post_id) !== 'property') {
            return $comments;
        }

        return array_filter($comments, function($comment) {
            return $comment->comment_approved == '1';
        });
    }

    /**
     * Admin column render
     */
    public function render_admin_columns($column, $comment_ID) {

        if ($column === 'rating') {
            $rating = get_comment_meta($comment_ID, 'rating', true);
            echo $rating ? str_repeat('★', $rating) : '-';
        }

        if ($column === 'booking_date') {
            $date = get_comment_meta($comment_ID, 'booking_date', true);
            echo $date ? wp_date('M Y', strtotime($date . '-01')) : '-';
        }
    }

    /**
     * Sortable columns
     */
    public function sortable_columns($columns) {
        $columns['rating'] = 'rating';
        return $columns;
    }

    /**
     * Handle sorting
     */
    public function handle_sorting($query) {

        if (!is_admin()) return;

        if ($query->get('orderby') === 'rating') {
            $query->query_vars['meta_key'] = 'rating';
            $query->query_vars['orderby'] = 'meta_value_num';
        }
    }

    /**
     * Admin meta box
     */
    public function add_comment_metabox() {
        add_meta_box(
            'comment_extra_fields',
            'Review Details',
            [$this, 'render_comment_metabox'],
            'comment',
            'normal',
            'high'
        );
    }

    public function render_comment_metabox($comment) {

        $rating = get_comment_meta($comment->comment_ID, 'rating', true);
        $booking_date = get_comment_meta($comment->comment_ID, 'booking_date', true);
        ?>

        <p>
            <label><strong>Rating</strong></label><br/>
            <select name="rating">
                <option value="">None</option>
                <?php for ($i = 5; $i >= 1; $i--): ?>
                    <option value="<?php echo $i; ?>" <?php selected($rating, $i); ?>>
                        <?php echo str_repeat('★', $i); ?>
                    </option>
                <?php endfor; ?>
            </select>
        </p>

        <p>
            <label><strong>Booking Month</strong></label><br/>
            <input type="month" name="booking_date" value="<?php echo esc_attr($booking_date); ?>" />
        </p>

        <?php
    }

    public function save_admin_fields($comment_id) {

        if (!current_user_can('edit_comment', $comment_id)) {
            return;
        }

        if (isset($_POST['rating'])) {
            update_comment_meta($comment_id, 'rating', intval($_POST['rating']));
        }

        if (isset($_POST['booking_date'])) {
            update_comment_meta($comment_id, 'booking_date', sanitize_text_field($_POST['booking_date']));
        }
    }

    /**
     * Frontend display
     */
    public function render_comment_meta($comment_text, $comment) {

        if (get_post_type($comment->comment_post_ID) !== 'property') {
            return $comment_text;
        }

        // Hide unapproved content
        if ($comment->comment_approved != '1') {
            return '';
        }

        $rating = get_comment_meta($comment->comment_ID, 'rating', true);
        $booking_date = get_comment_meta($comment->comment_ID, 'booking_date', true);

        ob_start();
        ?>

        <div class="review-meta-wrapper">

            <?php if ($rating): ?>
                <div class="review-rating">
                    <?php echo str_repeat('★', $rating) . str_repeat('☆', 5 - $rating); ?>
                </div>
            <?php endif; ?>

            <?php if ($booking_date): ?>
                <div class="review-booking-date">
                    Stayed: <?php echo esc_html(wp_date('F Y', strtotime($booking_date . '-01'))); ?>
                </div>
            <?php endif; ?>

        </div>

        <div class="review-text">
            <?php echo wpautop($comment_text); ?>
        </div>

        <?php
        return ob_get_clean();
    }
}