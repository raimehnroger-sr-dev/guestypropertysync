let selectedArrival = null;
let selectedDeparture = null;
let checkIn = null;
let checkOut = null;

jQuery(document).ready(function ($) {

    function loadInitialCalendar($wrapper) {
        if (!$wrapper || !$wrapper.length || $wrapper.data('calendar-loaded') || $wrapper.data('calendar-loading')) {
            return;
        }

        const listingId = String($wrapper.data('listing-id') || '');
        const month = parseInt($wrapper.data('month'), 10);
        const year = parseInt($wrapper.data('year'), 10);
        if (!listingId || !month || !year || typeof guesty_ajax === 'undefined') {
            return;
        }

        $wrapper.data('calendar-loading', true);
        const $button = $wrapper.find('.guesty-calendar-open').first();
        const $loader = $wrapper.find('#calendar-loader').first();
        const $content = $wrapper.find('#calendar-content').first();

        $button.prop('disabled', true).attr('aria-expanded', 'true').text('Loading availability…');
        $loader.show();
        $content.empty();

        $.ajax({
            url: guesty_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'guesty_load_calendar',
                nonce: guesty_ajax.nonce,
                listing_id: listingId,
                month: month,
                year: year,
                arrival_date: String($wrapper.data('arrival') || ''),
                departure_date: String($wrapper.data('departure') || '')
            }
        }).done(function (response) {
            if (response && response.success === false) {
                const message = response.data && response.data.message ? response.data.message : 'Availability could not be loaded.';
                $content.html('<div class="guesty-calendar-error"></div>').find('.guesty-calendar-error').text(message);
                $button.prop('disabled', false).text('Try availability again');
                return;
            }

            $content.html(response);
            $wrapper.data('calendar-loaded', true);
            $button.hide();
            initCalendar();
        }).fail(function () {
            $content.html('<div class="guesty-calendar-error">Availability could not be loaded. Please try again.</div>');
            $button.prop('disabled', false).text('Try availability again');
        }).always(function () {
            $wrapper.data('calendar-loading', false);
            $loader.hide();
        });
    }

    $(document).on('click', '.guesty-calendar-open', function (event) {
        event.preventDefault();
        loadInitialCalendar($(this).closest('.guesty-calendar-lazy'));
    });

    // Booking form date interaction is also an explicit request to open the
    // availability widget. No calendar call occurs before this interaction.
    $(document).on('focus click', '#arrival, #departure, .arrival input, .departure input, .nightsCount input', function () {
        $('.guesty-calendar-lazy').each(function () {
            loadInitialCalendar($(this));
        });
    });

    function clearSelection() {
        $('.calendar td').removeClass('selected-check-in selected-check-out selected-range hover-range hover-check-out');
        checkIn = null;
        checkOut = null;
        $('.calendar-cta').remove();
    }

    function clearError() {
        $('.calendar-error').remove();
    }

    function renderCTA(listingId) {
        clearError();

        const fromCalendar  = getParam('fromCalendar');

        const guests        = getParam('guests');
        let guestsCount     = guests ? '&guests='+guests : '';

        const bookingBase = (typeof guesty_ajax !== 'undefined' && guesty_ajax.booking_url) ? guesty_ajax.booking_url : '/booking/';
        const bookingPath = new URL(bookingBase, window.location.origin).pathname.replace(/\/$/, '');
        const isBookingPage = window.location.pathname.replace(/\/$/, '') === bookingPath;
        let CTAText = fromCalendar ? 'Update Booking Dates' : 'Book Now';

        const $existingCTA = $('.calendar-cta');

        if ($existingCTA.length > 0) {
            // Update href and text
            $existingCTA.find('a')
                .attr('href', `${bookingBase}${bookingBase.includes('?') ? '&' : '?'}listing_id=${encodeURIComponent(listingId)}&arrival=${encodeURIComponent(checkIn)}&departure=${encodeURIComponent(checkOut)}&fromCalendar=true${guestsCount}`)
                .text(CTAText);

            // Show it if it's hidden
            $existingCTA.show();
        } else {
            // Create CTA
            const ctaHtml = `
                <div class="calendar-cta">
                    <a href="${bookingBase}${bookingBase.includes('?') ? '&' : '?'}listing_id=${encodeURIComponent(listingId)}&arrival=${encodeURIComponent(checkIn)}&departure=${encodeURIComponent(checkOut)}&fromCalendar=true${guestsCount}" class="book-now-btn">${CTAText}</a>
                </div>
            `;
            $('.calendar').after(ctaHtml);

            // If on booking page and it's initial load, hide it until user selects new dates
            if (isBookingPage && !checkIn && !checkOut) {
                $('.calendar-cta').hide();
            }
        }
    }



    function renderError(text) {
        if ($('.calendar-error').length === 0) {
            $('.calendar').after(
                `<div class="calendar-error">
                <p style="text-align: center;">${text}</p>
                </div>`
            );
        }
    }

    function getParam(name) {
        return new URLSearchParams(window.location.search).get(name);
    }

    function contactLinks() {
        if (typeof guesty_ajax === 'undefined') return '';
        const links = [];
        const phone = String(guesty_ajax.contact_phone || '').trim();
        const email = String(guesty_ajax.contact_email || '').trim();
        if (phone) {
            links.push('<a href="tel:' + phone.replace(/[^+\d]/g, '') + '" style="color:#022A49;">' + $('<div>').text(phone).html() + '</a>');
        }
        if (email) {
            links.push('<a href="mailto:' + encodeURIComponent(email) + '" style="color:#022A49;">' + $('<div>').text(email).html() + '</a>');
        }
        return links.length ? ' Please contact us at ' + links.join(' or ') + '.' : '';
    }

    function getDateRange(start, end) {
        const range = [];
        const curr = new Date(start);
        const stop = new Date(end);
        while (curr < stop) {
            range.push(curr.toISOString().split('T')[0]);
            curr.setDate(curr.getDate() + 1);
        }
        return range;
    }

    function initCalendar() {
        const arrival = getParam('arrival');
        const departure = getParam('departure');
        const listingId = $('.calendar').data('listing-id');
        const bookingBase = (typeof guesty_ajax !== 'undefined' && guesty_ajax.booking_url) ? guesty_ajax.booking_url : '/booking/';
        const bookingPath = new URL(bookingBase, window.location.origin).pathname.replace(/\/$/, '');
        const isBookingPage = window.location.pathname.replace(/\/$/, '') === bookingPath;

        if (
            arrival &&
            departure &&
            $('.calendar td[data-date="' + arrival + '"]').length &&
            $('.calendar td[data-date="' + departure + '"]').length
        ) {
            checkIn = arrival;
            checkOut = departure;

            if (!isBookingPage) {
                renderCTA(listingId);
            }
        }

        // Click on TODAY cell → show warning
        $(document).on('click', '#calendar-content .calendar td.unavailable.today', function (e) {

            e.stopPropagation(); // prevent other td handlers

            if (!$('#calendar-content .sameday-booking-warning').length) {
                $('#calendar-content').append(
                    '<p class="sameday-booking-warning" style="text-align:center;margin-top:15px;">Same-day bookings require confirmation.' + contactLinks() + '</p>'
                );
            }

        });

        // Click on ANY OTHER td or td link → remove warning
        $(document).on('click','#calendar-content .calendar td:not(.today), #calendar-content .calendar td a', function () {
            $('#calendar-content .sameday-booking-warning').remove();
        });

        $('.calendar td').off('mouseenter mouseleave').on('mouseenter', function () {

            if (!checkIn || checkOut) return;

            const hoverDate = $(this).data('date');
            if (!hoverDate) return;

            const checkInTime = new Date(checkIn).getTime();
            const hoverTime   = new Date(hoverDate).getTime();

            $('.calendar td').removeClass('hover-range hover-check-out');

            // must be after checkin
            if (hoverTime <= checkInTime) return;

            const hoverCheckout = new Date(hoverDate);
            const checkOutStr = hoverCheckout.toISOString().split('T')[0];

            // nights between checkin and hovered checkout
            const range = getDateRange(checkIn, checkOutStr);

            // --------------------------------------------------
            // VALIDATE RANGE (exclude checkout day)
            // --------------------------------------------------
            let rangeValid = true;

            range.forEach(date => {
                if (date === checkOutStr) return; // ignore checkout day

                const $cell = $('.calendar td[data-date="' + date + '"]');
                if (!$cell.hasClass('available')) {
                    rangeValid = false;
                }
            });

            if (!rangeValid) return;

            // --------------------------------------------------
            // APPLY HOVER CLASSES
            // --------------------------------------------------
            $('.calendar td').each(function () {
                const date = $(this).data('date');
                if (!date) return;

                if (date > checkIn && date < checkOutStr) {
                    $(this).addClass('hover-range');
                }

                if (date === checkOutStr) {
                    $(this).addClass('hover-check-out');
                }
            });

        }).on('mouseleave', function () {
            $('.calendar td').removeClass('hover-range hover-check-out');
        });


        $('.calendar td.available, .calendar td.unavailable.check-in').off('click').on('click', function () {
            clearError();

            const listingId = $('.calendar').data('listing-id');
            const selectedDate = $(this).data('date');

            // --------------------------------------------------
            // FIRST CLICK = CHECK-IN
            // --------------------------------------------------
            if (!checkIn || (checkIn && checkOut)) {

                clearSelection();

                checkIn = selectedDate;
                checkOut = null;

                const $cell = $(this);
                minNights = parseInt($cell.data('minnight'), 10) || 1;

                $cell.addClass('selected-check-in');
                return;
            }

            // --------------------------------------------------
            // SECOND CLICK = CHECKOUT DATE
            // --------------------------------------------------
            const checkInDate = new Date(checkIn);
            const clickedDate = new Date(selectedDate);

            // must be after checkin
            if (clickedDate <= checkInDate) {
                clearSelection();
                checkIn = selectedDate;
                $(this).addClass('selected-check-in');
                return;
            }

            // real checkout date (NO +1 day anymore)
            const checkOutStr = clickedDate.toISOString().split('T')[0];
            const range = getDateRange(checkIn, checkOutStr); // nights only
            const numNights = range.length;

            // --------------------------------------------------
            // VALIDATE MIN NIGHTS
            // --------------------------------------------------
            if (numNights < minNights) {
                renderError(`Minimum stay is ${minNights} night${minNights > 1 ? 's' : ''}.`);
                return;
            }

            // --------------------------------------------------
            // VALIDATE AVAILABILITY
            // Only validate nights stayed (exclude checkout day)
            // --------------------------------------------------
            const invalid = range.some(date => {
                if (date === checkOutStr) return false; // allow checkout on unavailable day

                const $cell = $('.calendar td[data-date="' + date + '"]');
                return !$cell.hasClass('available');
            });

            if (invalid) {
                renderError('Selected dates include unavailable nights.' + contactLinks());
                return;
            }

            // --------------------------------------------------
            // APPLY SELECTION
            // --------------------------------------------------
            checkOut = checkOutStr;

            $('.calendar td').removeClass(
                'selected-check-in selected-check-out selected-range'
            );

            $('.calendar td').each(function () {
                const date = $(this).data('date');
                if (!date) return;

                if (date === checkIn) {
                    $(this).addClass('selected-check-in');

                } else if (date === checkOut) {
                    $(this).addClass('selected-check-out');

                } else if (date > checkIn && date < checkOut) {
                    $(this).addClass('selected-range');
                }
            });

            // --------------------------------------------------
            // UPDATE PRICE / NIGHTS
            // --------------------------------------------------
            const nightFromParam = $('.nightsFromParam .elementor-heading-title');

            if (typeof window.fetchGuestyQuote === 'function') {
                // ✅ IMPORTANT: mark as manual selection
                window.isManualSelection = true;
                window.fetchGuestyQuote(checkIn, checkOut);
            }

            if (nightFromParam.length) {
                nightFromParam.text(
                    `for ${numNights} night${numNights !== 1 ? 's' : ''}`
                );
            }

            // --------------------------------------------------
            // CTA
            // --------------------------------------------------
            renderCTA(listingId);
        });

    }

    // Initial setup
    initCalendar();

    $(document).on('change', '.calendar-month-selector', function () {
        const val = $(this).val();
        const [month, year] = val.split('-');
        const listingId = $(this).data('listing-id');
        const arrival = $('.calendar .selected-check-in').data('date') || getParam('arrival');
        const departure = $('.calendar .selected-check-out').data('date') || getParam('departure');

        $('#calendar-loader').show();

        $.ajax({
            url: guesty_ajax.ajax_url,
            method: 'POST',
            data: {
                action: 'guesty_load_calendar',
                nonce: guesty_ajax.nonce,
                listing_id: listingId,
                month,
                year,
                arrival_date: arrival || '',
                departure_date: departure || ''
            },
            success: function (response) {
                $('#calendar-content').html(response);
                initCalendar();
            },
            error: function () {
                alert('Error loading calendar.');
            },
            complete: function () {
                $('#calendar-loader').hide();
            }
        });
    });


    // AJAX navigation
    $(document).on('click', '.calendar .nav-btn', function (e) {
        e.preventDefault();
        const button = $(this);
        const month = button.data('month');
        const year = button.data('year');
        const listingId = button.data('listing-id');

        let arrival = $('.calendar .selected-check-in').data('date');
        let departure = $('.calendar .selected-check-out').data('date');

        if (!arrival || !departure) {
            const params = new URLSearchParams(window.location.search);
            arrival = arrival || params.get('arrival');
            departure = departure || params.get('departure');
        }

        $('#calendar-loader').show();

        $.ajax({
            url: guesty_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'guesty_load_calendar',
                nonce: guesty_ajax.nonce,
                month: month,
                year: year,
                listing_id: listingId,
                arrival_date: arrival || '',
                departure_date: departure || ''
            },
            success: function (response) {
                $('#calendar-content').html(response);
                initCalendar(); // 🔁 REBIND all handlers after new calendar loads
            },
            complete: function () {
                $('#calendar-loader').hide();
            }
        });
    });
});
