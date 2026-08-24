<?php
// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

class Guesty_Property_Comment_Slider extends \Elementor\Core\DynamicTags\Tag {

    public function get_name() {
        return 'guesty-property-comment-slider';
    }

    public function get_title() {
        return __( 'Property Comment Slider', 'guesty-properties-sync' );
    }

    public function get_group() {
        return [ 'guesty-properties' ];
    }

    public function get_categories() {
        return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
    }

    protected function register_controls() {

        $this->add_control(
            'comments_count',
            [
                'label' => __( 'Number of Comments', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 5,
            ]
        );

        $this->add_control(
            'order',
            [
                'label' => __( 'Order', 'guesty-properties-sync' ),
                'type' => \Elementor\Controls_Manager::SELECT,
                'options' => [
                    'DESC' => 'Newest First',
                    'ASC'  => 'Oldest First',
                ],
                'default' => 'DESC',
            ]
        );
    }

    public function render() {

        $post_id = get_the_ID();

        // Only run on property post type
        if ( get_post_type( $post_id ) !== 'property' ) {
            return;
        }

        $count = $this->get_settings( 'comments_count' );
        $order = $this->get_settings( 'order' );

        $comments = get_comments([
            'post_id' => $post_id,
            'number'  => $count,
            'status'  => 'approve',
            'order'   => $order,
        ]);

        if ( empty( $comments ) ) {
            echo '<p>No reviews yet.</p>';
            return;
        }

        // Unique ID per instance (VERY IMPORTANT in Elementor)
        $uid = 'guesty-comments-' . $this->get_id();

        ?>
        <div id="<?php echo esc_attr( $uid ); ?>" class="guesty-comments-carousel">
            <?php foreach ( $comments as $comment ) : ?>
                <div class="comment-item">
                    
                    <div class="comment-avatar">
                        <?php echo get_avatar( $comment, 60 ); ?>
                    </div>

                    <div class="comment-content">
                        <?php echo esc_html( wp_trim_words( $comment->comment_content, 25 ) ); ?>
                    </div>

                    <div class="comment-author">
                        — <?php echo esc_html( $comment->comment_author ); ?>
                    </div>

                </div>
            <?php endforeach; ?>
        </div>

        <script>
        jQuery(window).on('elementor/frontend/init', function () {
            elementorFrontend.hooks.addAction(
                'frontend/element_ready/global',
                function ($scope) {

                    var $carousel = jQuery('#<?php echo $uid; ?>');

                    if (!$carousel.length) return;

                    if ($carousel.hasClass('slick-initialized')) {
                        $carousel.slick('unslick');
                    }

                    $carousel.slick({
                        slidesToShow: 1,
                        slidesToScroll: 1,
                        autoplay: true,
                        autoplaySpeed: 4000,
                        arrows: true,
                        dots: true,
                        adaptiveHeight: true
                    });
                }
            );
        });
        </script>
        <?php
    }
}