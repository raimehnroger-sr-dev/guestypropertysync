jQuery(function($){

    let reviewPage = 1;
    let reviewLoading = false;
    let reviewAllLoaded = false;
    let activePostID = null;

    /* -------------------------------------------------
     * STATE CHECK (GLOBAL BODY CLASS)
     * ------------------------------------------------- */
    function checkReviews() {
        let hasReviews = $('.property-reviews-list').length > 0;

        $('body')
            .toggleClass('has-property-reviews', hasReviews)
            .toggleClass('no-property-reviews', !hasReviews);
    }

    checkReviews();


    /* -------------------------------------------------
     * MODAL STATE MANAGER
     * ------------------------------------------------- */
    function setModalState(state) {

        let $modal = $('#review-modal');
        let $form = $('#property-review-form');
        let $success = $('#review-success');
        let $title = $('.review-modal-title');
        let $list = $('.review-list-container');

        if (state === 'form') {
            $title.text('Leave a Review');
            $form.show();
            $success.hide();
            $list.hide();
        }

        if (state === 'reviews') {
            $title.text('All Reviews');
            $form.hide();
            $success.hide();
            $list.show();
        }
    }


    /* -------------------------------------------------
     * OPEN MODAL (FORM MODE)
     * ------------------------------------------------- */
    $(document).on('click', '.open-review-modal', function(){
        $('#review-modal').fadeIn();
        setModalState('form');
    });


    /* -------------------------------------------------
     * SHOW ALL REVIEWS (MODAL MODE)
     * ------------------------------------------------- */
    $(document).on('click', '.show-all-reviews', function(e){
        e.preventDefault();

        activePostID = $(this).data('post');

        reviewPage = 1;
        reviewAllLoaded = false;

        $('#review-modal').fadeIn();
        setModalState('reviews');

        $('.review-list-scroll').html('<p style="text-align:center;">Loading reviews...</p>');

        $.post(review_ajax.ajax_url, {
            action: 'get_property_reviews',
            post_id: activePostID,
            paged: reviewPage
        }, function(response){

            if (response.success) {
                $('.review-list-scroll').html(response.data.html);
                
                reviewPage = 2;

                initReviewScroll(); // 🔥 IMPORTANT
            } else {
                $('.review-list-scroll').html('<p>Failed to load reviews</p>');
            }

        });
    });


    /* -------------------------------------------------
     * CLOSE MODAL
     * ------------------------------------------------- */
    $(document).on('click', '.close-modal, .review-modal-overlay', function(){
        $('#review-modal').fadeOut();

        setTimeout(function(){
            resetReviewModal();
            setModalState('form');
        }, 200);
    });


    /* -------------------------------------------------
     * SUBMIT REVIEW (AJAX)
     * ------------------------------------------------- */
    $(document).on('submit', '#property-review-form', function(e){
        e.preventDefault();

        let $form = $(this);

        $('#review-message')
            .hide()
            .removeClass('error success')
            .html('');

        $('#review-success').hide();

        let bookingDate = $form.find('[name="booking_date"]').val();

        // strict MM/YYYY check
        let regex = /^(0[1-9]|1[0-2])\/\d{4}$/;

        if (!regex.test(bookingDate)) {
            showMessage('error', 'Please enter a valid date in MM/YYYY format.');
            return;
        }

        // extra safety: validate month + year
        let [month, year] = bookingDate.split('/');

        month = parseInt(month, 10);
        year = parseInt(year, 10);

        let currentYear = new Date().getFullYear();
        let currentMonth = new Date().getMonth() + 1;

        // month safety (double protection)
        if (month < 1 || month > 12) {
            showMessage('error', 'Month must be between 01 and 12.');
            return;
        }

        // year safety (optional but recommended)
        if (year < 1900 || year > currentYear + 1) {
            showMessage('error', 'Please enter a valid year.');
            return;
        }

        // optional: prevent future dates
        if (year > currentYear || (year === currentYear && month > currentMonth)) {
            showMessage('error', 'Booking date cannot be in the future.');
            return;
        }

        let $btn = $form.find('.submit-review-btn');
        let $text = $btn.find('.btn-text');
        let $loader = $btn.find('.btn-loader');

        if ($btn.hasClass('loading')) return;

        $btn.addClass('loading');
        $text.text('Submitting review...');
        $loader.show();

        let formData = $form.serialize();

        $.post(review_ajax.ajax_url, {
            action: 'submit_property_review',
            ...Object.fromEntries(new URLSearchParams(formData))
        }, function(response){

            if (response.success) {

                $form.hide();
                $('#review-success').show();

                $('#review-message')
                    .hide()
                    .removeClass('error success')
                    .html('');

                checkReviews();

            } else {
                alert(response.data?.message || 'Something went wrong');
                resetButton($btn, $text, $loader);
            }

        }).fail(function(){
            alert('Server error. Please try again.');
            resetButton($btn, $text, $loader);
        });
    });


    /* -------------------------------------------------
     * RESET BUTTON ONLY
     * ------------------------------------------------- */
    function resetButton($btn, $text, $loader) {
        $btn.removeClass('loading');
        $text.text('Submit Review');
        $loader.hide();
    }


    /* -------------------------------------------------
     * RESET MODAL
     * ------------------------------------------------- */
    function resetReviewModal() {

        let $form = $('#property-review-form');
        let $btn = $form.find('.submit-review-btn');

        // reset form
        if ($form.length) {
            $form[0].reset();
        }

        $form.find('[name="booking_date"]').val('');

        // reset stars
        $('.stars span').removeClass('active hover');
        $('.stars').attr('data-rating', 0);
        $('input[name="rating"]').val(0);

        // reset button
        resetButton(
            $btn,
            $btn.find('.btn-text'),
            $btn.find('.btn-loader')
        );

        // reset UI
        $('#review-message')
            .hide()
            .removeClass('error success')
            .html('');
        $('#review-success').hide();
        $form.show();
    }


    /* -------------------------------------------------
     * STAR RATING SYSTEM
     * ------------------------------------------------- */
    function paintStars(container, value, className) {
        container.find('span').each(function () {
            let starValue = $(this).data('value');
            $(this).toggleClass(className, starValue <= value);
        });
    }

    $(document).on('mouseenter', '.stars span', function () {
        let value = $(this).data('value');
        paintStars($(this).closest('.stars'), value, 'hover');
    });

    $(document).on('mouseleave', '.stars', function () {
        $(this).find('span').removeClass('hover');
    });

    $(document).on('click', '.stars span', function () {
        let value = $(this).data('value');
        let $container = $(this).closest('.stars');

        $container.attr('data-rating', value);
        $container.find('span').removeClass('active');

        paintStars($container, value, 'active');

        $container.siblings('input[name="rating"]').val(value);
    });


    /* -------------------------------------------------
     * SHOW MORE / LESS
     * ------------------------------------------------- */
    $(document).on('click', '.review-toggle', function(e){
        e.preventDefault();

        let $parent = $(this).closest('.review-content');
        let $text = $parent.find('.review-text');

        let full = $parent.data('full');
        let short = $parent.data('short');

        let isMore = $(this).text() === 'Show more';

        $text.text(isMore ? full : short);
        $(this).text(isMore ? 'Show less' : 'Show more');
    });

    /* -------------------------------------------------
    * INFINITE SCROLL
    * ------------------------------------------------- */
    function initReviewScroll() {

        let el = document.querySelector('.review-list-scroll');

        if (!el) return;

        el.addEventListener('scroll', function () {

            // console.log('SCROLL FIRED');

            if (reviewLoading || reviewAllLoaded) return;

            let scrollBottom =
                el.scrollHeight -
                el.scrollTop -
                el.clientHeight;

            if (scrollBottom < 120) {

                // console.log('LOAD MORE REVIEWS');

                reviewLoading = true;

                // show loader
                $('.review-loading').show();

                $.post(review_ajax.ajax_url, {
                    action: 'get_property_reviews',
                    post_id: activePostID,
                    paged: reviewPage
                }, function(response){

                    $('.review-loading').hide();

                    if (response.success) {

                        $('.review-list-scroll').append(response.data.html);

                        reviewPage++;

                        let loaded = $('.review-list-scroll .review-item').length;

                        if (loaded >= response.data.total) {
                            reviewAllLoaded = true;
                        }
                    }

                    reviewLoading = false;

                }).fail(function () {

                    $('.review-loading').hide();
                    reviewLoading = false;
                });
            }
        });
    }

    /* -------------------------------------------------
    * BOOKING DATE (MM/YYYY AUTO FORMAT)
    * ------------------------------------------------- */
    $(document).on('input', 'input[name="booking_date"]', function() {

        let value = $(this).val().replace(/\D/g, '');

        if (value.length >= 3) {
            value = value.substring(0, 2) + '/' + value.substring(2, 6);
        }

        $(this).val(value);
    });

    function showMessage(type, text) {
        $('#review-success').hide();
        $('#review-message')
            .removeClass('error success')
            .addClass(type)
            .html('<p>' + text + '</p>')
            .show();
    }
});