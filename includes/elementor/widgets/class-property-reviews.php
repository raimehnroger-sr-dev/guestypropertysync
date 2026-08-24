<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Reviews extends \Elementor\Widget_Base {

    public function get_name() {
        return 'property_reviews';
    }

    public function get_title() {
        return 'Property Reviews';
    }

    public function get_icon() {
        return 'eicon-testimonial';
    }

    public function get_categories() {
        return [ 'general' ];
    }

    public function get_script_depends() {
        return ['property-reviews-js'];
    }

    public function get_style_depends() {
        return ['property-reviews-css'];
    }

    protected function render() {
        global $post;

        $property_id   = $post->ID;
        $property_name = get_the_title($post->ID);
        $nonce = wp_create_nonce('submit_review_nonce');

        // Get approved reviews
        $comments = get_comments([
            'post_id' => $property_id,
            'status'  => 'approve',
            'type'    => 'review',
            'number'  => 3,
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ]);

        $total_reviews = get_comments([
            'post_id' => $property_id,
            'status'  => 'approve',
            'type'    => 'review',
            'count'   => true,
        ]);
        ?>

        <div class="property-reviews-widget">

            <!-- REVIEWS LIST (only if exists) -->
            <?php if (!empty($comments)) : ?>
                <div class="property-reviews-list">

                    <?php foreach ($comments as $comment) :

                        $rating = get_comment_meta($comment->comment_ID, 'rating', true);
                        $month  = get_comment_meta($comment->comment_ID, 'booking_month', true);
                        $year   = get_comment_meta($comment->comment_ID, 'booking_year', true);

                        ?>

                        <div class="review-item">

                            <div class="review-author">
                                <strong><?php echo esc_html($comment->comment_author); ?></strong>
                            </div>

                            <?php
                                $content = strip_tags($comment->comment_content);
                                $limit = 500;

                                $is_long = strlen($content) > $limit;
                                $short = $is_long ? substr($content, 0, $limit) . '...' : $content;
                            ?>

                            <div class="review-content"
                                data-full="<?php echo esc_attr($content); ?>"
                                data-short="<?php echo esc_attr($short); ?>">

                                <span class="review-text">
                                    <?php echo esc_html($short); ?>
                                </span>

                                <?php if ($is_long) : ?>
                                    <a href="#" class="review-toggle">Show more</a>
                                <?php endif; ?>

                            </div>

                            <?php if ($rating || ($month && $year)) : ?>
                                <div class="review-extras">
                                    <?php if ($month && $year) : ?>
                                        <div class="review-date">
                                            <p><strong><?php echo esc_html(date('F', mktime(0,0,0,$month,1))) . ' ' . esc_html($year); ?></strong>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($rating) : ?>
                                        <div class="review-rating">
                                            <?php
                                            for ($i = 1; $i <= 5; $i++) {
                                                echo $i <= $rating ? '★' : '☆';
                                            }
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                </div>
            <?php endif; ?>
 
            <!-- CTA SECTION -->
            <div class="review-cta-wrapper">

                <?php if ($total_reviews > 3) : ?>
                    <button class="show-all-reviews"
                            data-post="<?php echo esc_attr($property_id); ?>"
                            data-property="<?php echo esc_attr($property_name); ?>">
                        Show all <?php echo esc_html($total_reviews); ?> reviews
                    </button>
                <?php endif; ?>

                <button class="open-review-modal"
                        data-property="<?php echo esc_attr($property_name); ?>">
                    Post your review
                </button>

            </div>

            <!-- Modal -->
            <div id="review-modal" style="display:none;">
                <div class="review-modal-overlay"></div>

                <div class="review-modal-content">
                    <span class="close-modal">&times;</span>

                    <h3 class="review-modal-title">Leave a Review</h3>

                    <div class="review-list-container" style="display:none;">
                        <div class="review-list-scroll"></div>
                        <div class="review-loading" style="display:none;">
                            <span></span><span></span><span></span>
                        </div>
                    </div>

                    <form id="property-review-form">
                        <input type="hidden" name="property_name" value="<?php echo esc_attr($property_name); ?>">
                        <input type="hidden" name="post_id" value="<?php echo esc_attr($post->ID); ?>">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">

                        <p>
                            <label>Name *</label>
                            <input type="text" name="name" required>
                        </p>

                        <p>
                            <label>Email *</label>
                            <input type="email" name="email" required>
                        </p>

                        <p>
                            <label>Comment *</label>
                            <textarea name="comment" required></textarea>
                        </p>

                        <p>
                            <label>Booking Date *</label>
                            <input type="text" 
                                name="booking_date" 
                                placeholder="MM/YYYY" 
                                maxlength="7"
                                required>
                        </p> 

                        <p class="star-rating">
                            <label>Rating (optional)</label>

                            <div class="stars" data-rating="0">
                                <span data-value="1">★</span>
                                <span data-value="2">★</span>
                                <span data-value="3">★</span>
                                <span data-value="4">★</span>
                                <span data-value="5">★</span>
                            </div>

                            <input type="hidden" name="rating" value="0">
                        </p>

                        <button type="submit" class="submit-review-btn">
                            <span class="btn-text">Submit Review</span>
                            <span class="btn-loader" style="display:none;"></span>
                        </button>
                    </form>

                    <div id="review-message" style="display:none;"></div>

                    <div id="review-success" style="display:none;">
                        <p>Thanks for your comment. It has been sent to the property team.</p>
                    </div>

                </div>
            </div>

        </div>

        <?php
    }
}