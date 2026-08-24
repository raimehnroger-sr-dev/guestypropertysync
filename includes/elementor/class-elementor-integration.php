<?php
/**
 * Elementor Integration Class
 *
 * @link       https://spotzer.com
 * @since      3.3.0
 *
 * @package    Guesty_Property_Sync
 * @subpackage Guesty_Property_Sync/includes/elementor
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Elementor_Integration {

    public function __construct() {

        add_action( 'elementor/dynamic_tags/register', array( $this, 'register_dynamic_tags' ) );
        add_action( 'elementor/widgets/register', array( $this, 'register_widgets' ) );

        add_action('save_post_property', function() {
            delete_transient('cew_property_cities');
        });

        add_action('wp_enqueue_scripts', array( $this, 'guesty_widget_assets') );

        add_shortcode('guesty_overall_rating', array( $this, 'guesty_overall_rating'));

        add_action('wp_ajax_submit_property_review', array( $this, 'handle_property_review'));
        add_action('wp_ajax_nopriv_submit_property_review', array( $this, 'handle_property_review'));

        add_action('wp_ajax_get_property_reviews', [ $this, 'get_property_reviews' ]);
        add_action('wp_ajax_nopriv_get_property_reviews', [ $this, 'get_property_reviews' ]);

        add_filter('comment_text', array( $this, 'add_review_meta_to_comment_text'), 10, 2);

        $this->include_dynamic_tags();
    }

    private function include_dynamic_tags() {

        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-image-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-gallery-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-address-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-price-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-map-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-property-link.php';

        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-booking-tag.php';
        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/dynamic-tags/class-booking-image.php';
    }

    public function register_dynamic_tags( $dynamic_tags_manager ) {

        $dynamic_tags_manager->register_group('guesty-properties', [
            'title' => __('Guesty Properties', 'guesty-properties-sync')
        ]);

        $dynamic_tags_manager->register_group('guesty-booking', [
            'title' => __('Guesty Booking', 'guesty-properties-sync')
        ]);

        $dynamic_tags_manager->register_tag('Guesty_Property_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Property_Image_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Property_Gallery_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Property_Address_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Property_Price_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Property_Map_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Property_Link');

        $dynamic_tags_manager->register_tag('Guesty_Booking_Tag');
        $dynamic_tags_manager->register_tag('Guesty_Booking_Image');
    }

    public function register_widgets( $widgets_manager ) {

        if ( ! class_exists('Elementor\Widget_Base') ) return;

        require_once GUESTY_PROPERTY_SYNC_PLUGIN_DIR . 'includes/elementor/widgets/class-property-reviews.php';

        $widgets_manager->register( new Guesty_Property_Reviews() );
    }

    function guesty_widget_assets() {

        $base_url = plugin_dir_url(dirname(__FILE__, 2));

        wp_register_script(
            'property-reviews-js',
            $base_url . 'includes/js/property-reviews.js',
            ['jquery'],
            '1.0',
            true
        );

        wp_localize_script('property-reviews-js', 'review_ajax', [
            'ajax_url' => admin_url('admin-ajax.php')
        ]);

        wp_register_style(
            'property-reviews-css',
            $base_url . 'includes/css/property-reviews.css',
            [],
            '1.0'
        );
    }

    /* -------------------------------------------------
     * SUBMIT REVIEW (AJAX)
     * ------------------------------------------------- */
    public function handle_property_review() {

        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'submit_review_nonce')) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        $name    = sanitize_text_field($_POST['name'] ?? '');
        $email   = sanitize_email($_POST['email'] ?? '');
        $comment = sanitize_textarea_field($_POST['comment'] ?? '');
        $post_id = intval($_POST['post_id'] ?? 0);
        $rating  = intval($_POST['rating'] ?? 0);
        $booking_date = sanitize_text_field($_POST['booking_date'] ?? '');

        if (empty($name) || empty($email) || empty($comment) || !$post_id) {
            wp_send_json_error(['message' => 'Missing required fields']);
        }

        if (!$booking_date) {
            wp_send_json_error(['message' => 'Booking date is required']);
        }

        $comment_id = wp_insert_comment([
            'comment_post_ID'      => $post_id,
            'comment_author'       => $name,
            'comment_author_email' => $email,
            'comment_content'      => $comment,
            'comment_type'         => 'review',
            'comment_approved'     => 0,
        ]);

        if (!$comment_id) {
            wp_send_json_error(['message' => 'Failed to save review']);
        }

        if ($rating) {
            add_comment_meta($comment_id, 'rating', $rating);
        }

        // MM/YYYY format validation + split
        if ($booking_date && preg_match('/^(0[1-9]|1[0-2])\/\d{4}$/', $booking_date)) {

            list($month, $year) = explode('/', $booking_date);

            $month = intval($month);
            $year  = intval($year);

            // hard validation
            if ($month < 1 || $month > 12) {
                wp_send_json_error(['message' => 'Invalid month value.']);
            }

            if ($year < 1900 || $year > (int) date('Y') + 1) {
                wp_send_json_error(['message' => 'Invalid year value.']);
            }

            add_comment_meta($comment_id, 'booking_month', $month);
            add_comment_meta($comment_id, 'booking_year', $year);
        }

        wp_send_json_success(['message' => 'Review submitted']);
    }

    /* -------------------------------------------------
     * COMMENT DISPLAY META
     * ------------------------------------------------- */
    public function add_review_meta_to_comment_text($comment_text, $comment) {

        if ($comment->comment_type !== 'review') return $comment_text;

        $rating = get_comment_meta($comment->comment_ID, 'rating', true);
        $month  = get_comment_meta($comment->comment_ID, 'booking_month', true);
        $year   = get_comment_meta($comment->comment_ID, 'booking_year', true);

        $extra = '';

        if ($rating) {
            $extra .= '<p><strong>Rating:</strong> ' . esc_html($rating) . '/5</p>';
        }

        if ($month && $year) {
            $extra .= '<p><strong>Stay Date:</strong> ' .
                esc_html(date('F', mktime(0,0,0,$month,1))) . ' ' .
                esc_html($year) . '</p>';
        }

        return $comment_text . $extra;
    }

    /* -------------------------------------------------
     * GET REVIEWS (AJAX)
     * ------------------------------------------------- */
    public function get_property_reviews() {

        $post_id = intval($_POST['post_id'] ?? 0);
        $paged   = intval($_POST['paged'] ?? 1);
        $limit   = 10;

        if (!$post_id) {
            wp_send_json_error();
        }

        $total = get_comments([
            'post_id' => $post_id,
            'status'  => 'approve',
            'type'    => 'review',
            'count'   => true
        ]);

        $comments = get_comments([
            'post_id' => $post_id,
            'status'  => 'approve',
            'type'    => 'review',
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
            'number'  => $limit,
            'offset'  => ($paged - 1) * $limit
        ]);

        ob_start();

        foreach ($comments as $comment) {

            $rating = get_comment_meta($comment->comment_ID, 'rating', true);
            $month  = get_comment_meta($comment->comment_ID, 'booking_month', true);
            $year   = get_comment_meta($comment->comment_ID, 'booking_year', true);

            $content = strip_tags($comment->comment_content);
            $limit_text = 500;

            $is_long = strlen($content) > $limit_text;
            $short = $is_long ? substr($content, 0, $limit_text) . '...' : $content;
            ?>

            <div class="review-item">

                <div class="review-author">
                    <strong><?php echo esc_html($comment->comment_author); ?></strong>
                </div>

                <div class="review-content"
                    data-full="<?php echo esc_attr($content); ?>"
                    data-short="<?php echo esc_attr($short); ?>">

                    <span class="review-text">
                        <?php echo esc_html($short); ?>
                    </span>

                    <?php if ($is_long): ?>
                        <a href="#" class="review-toggle">Show more</a>
                    <?php endif; ?>

                </div>

                <?php if ($rating || ($month && $year)) : ?>
                    <div class="review-extras">

                        <?php if ($month && $year) : ?>
                            <div class="review-date">
                                <p><strong>
                                    <?php echo esc_html(date('F', mktime(0,0,0,$month,1))) . ' ' . esc_html($year); ?>
                                </strong></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($rating) : ?>
                            <div class="review-rating">
                                <?php for ($i = 1; $i <= 5; $i++) {
                                    echo $i <= $rating ? '★' : '☆';
                                } ?>
                            </div>
                        <?php endif; ?>

                    </div>
                <?php endif; ?>

            </div>

            <?php
        }

        $html = ob_get_clean();

        wp_send_json_success([
            'html'  => $html,
            'total' => $total,
            'count' => count($comments)
        ]);
    }

    /* -------------------------------------------------
     * AVERAGE RATING
     * ------------------------------------------------- */
    private function get_property_average_rating($post_id) {

        $comments = get_comments([
            'post_id' => $post_id,
            'status'  => 'approve',
            'type'    => 'review',
        ]);

        if (!$comments) {
            return ['average' => null, 'count' => 0];
        }

        $total = 0;
        $count = 0;

        foreach ($comments as $comment) {
            $rating = get_comment_meta($comment->comment_ID, 'rating', true);

            if ($rating) {
                $total += floatval($rating);
                $count++;
            }
        }

        return [
            'average' => $count ? round($total / $count, 2) : null,
            'count'   => $count
        ];
    }

    public function guesty_overall_rating($atts) {

        global $post;

        if (!$post) return '';

        $data = $this->get_property_average_rating($post->ID);

        if (!$data['count']) return '';

        return '
            <span class="stars-overall">
                ★ ' . esc_html($data['average']) . '
                <span class="rating-separator">·</span>
                <span class="rating-count">' .
                    esc_html($data['count']) . ' ' .
                    esc_html(_n('review', 'reviews', $data['count'])) .
                '</span>
            </span>
        ';
    }
}