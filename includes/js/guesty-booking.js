jQuery(document).ready(function($) {
    let guestyBookingID = $('.guestyBooking').data('formid');
    let guestyBookingInitialized = false;
    let guestyQuoteData = null; 
    let guestyConfirmationData = null;
    let totalFare = null;
    let activeCurrency = String(window.guesty_ajax_booking?.default_currency || 'GBP').toUpperCase();
    let currentStepPage = 1; 

    let bookingTagArr = $('.booking-tags .elementor-heading-title');
    let bookingTags = []; 

    if (bookingTagArr.length && bookingTagArr.html()?.trim()) {
        let tagString = bookingTagArr.html().trim();

        // Support comma or space-separated tags
        if (tagString.includes(',')) {
            bookingTags = tagString.split(',').map(tag => tag.trim());
        } else {
            bookingTags = tagString.split(/\s+/).map(tag => tag.trim());
        }
    }

    // Save globally for reuse (like in upsell filtering)
    window.bookingTags = bookingTags;

    

    // Initially get the guest Parameter value if present
    $('.adultsCount select').val(getGuestParam());

    // Step #1 variable fields
    let arrival = $('.arrivalDate input');
    let departure = $('.departureDate input');
    let nightsCount = parseInt($('.nightsCount input').val()) || 1;
    let adultsCount = parseInt($('.adultsCount select').val()) || 0;
    let childrensCount = parseInt($('.childrensCount select').val()) || 0;
    let infantsCount = parseInt($('.infantsCount select').val()) || 0;
    let petsCount = parseInt($('.petsCount select').val()) || 0;
    let couponCode = $('.couponCode input');

    // Loading screen element (append if not exist)
    if ($('#loading-screen').length === 0) {
        $('body').append(`
            <div id="loading-screen" style="display:none;">
                <div id="spinner">
                    <div class="dot"></div><div class="dot"></div><div class="dot"></div>
                </div>
            </div>
        `);
    }
    const $loadingScreen = $('#loading-screen');

    // Error Handling / Displaying Error Messages
    let errorMessages = [];

    function showError(message, type) {
        $('html, body').animate({ scrollTop: $('.guestyBooking').offset().top - 200 }, 100);
        $loadingScreen.fadeOut();
        if (!errorMessages.includes(message)) {
            errorMessages.push(message);
        }
        renderErrors(type);
    }

    function clearError(message = null) {
        if (message === null) {
            errorMessages = [];
        } else {
            errorMessages = errorMessages.filter(msg => msg !== message);
        }
        renderErrors();
    }

    function renderErrors(type = null) {
        const $errorBox = $('.guestyBooking .form-message');
        const step1nextButton = $('.guestyBooking .stepOne .gform_next_button');

        // Remove all known type classes first
        $errorBox.removeClass('error warning info success');

        if (errorMessages.length > 0) {
            if (type) {
                $errorBox.addClass(type);
            }

            const listItems = errorMessages.map(msg => `<li class="error-message">${msg}</li>`).join('');
            $errorBox.html(listItems).fadeIn();
            step1nextButton.prop('disabled', true);
            $('.totalBreakdown').hide();
            $('html, body').animate({ scrollTop: $('#gform_' + guestyBookingID).offset().top - 200 }, 100);
        } else {
            $errorBox.fadeOut().empty();
            $('.totalBreakdown').fadeIn();
            step1nextButton.prop('disabled', false);
        }
    }


    function validateEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(String(email).toLowerCase());
    }

    function dateFormatEnglish($date) {
        let dateObj = new Date($date);

        // Array of English month names
        const months = [
            "January", "February", "March", "April", "May", "June",
            "July", "August", "September", "October", "November", "December"
        ];

        let day = dateObj.getDate();
        let month = months[dateObj.getMonth()];
        let year = dateObj.getFullYear();

        return `${day} ${month} ${year}`;
    }

    function dateFormatYMD($date) {
        let englishDateStr = $date;
        let dateObj = new Date(englishDateStr);

        // Format as YYYY-MM-DD
        let yyyy = dateObj.getFullYear();
        let mm = String(dateObj.getMonth() + 1).padStart(2, '0'); // Months are 0-indexed
        let dd = String(dateObj.getDate()).padStart(2, '0');

        let formattedDate = `${yyyy}-${mm}-${dd}`;

        return formattedDate;
    }

    function dateFormat($date) {
        const date = new Date($date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        return date;
    }

    function formattedAmount(amount, currencyCode = activeCurrency) {
        const numericAmount = Number(amount);
        const safeAmount = Number.isFinite(numericAmount) ? numericAmount : 0;
        const safeCurrency = /^[A-Z]{3}$/.test(String(currencyCode || '').toUpperCase())
            ? String(currencyCode).toUpperCase()
            : 'GBP';

        return safeAmount.toLocaleString('en-GB', {
            style: 'currency',
            currency: safeCurrency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function getCurrencySymbol(code) {
        const symbols = {
            'USD': '$',
            'EUR': '€',
            'GBP': '£',
            'JPY': '¥',
            'AUD': 'A$',
            'CAD': 'C$',
        };
        return symbols[code] || code;
    }
    function getGuestParam() {
        const urlParams = new URLSearchParams(window.location.search);

        return urlParams.get('guests') ? urlParams.get('guests') : 1;
    }
    function updateDeparture() {
        const urlParams = new URLSearchParams(window.location.search);

        const arrivalParam = urlParams.get('arrival');
        const departureParam = urlParams.get('departure');

        let arrivalDate;
        if (arrivalParam) {
            const arrivalParts = arrivalParam.split('-');
            if (arrivalParts.length === 3) {
                arrivalDate = new Date(arrivalParts[0], arrivalParts[1] - 1, arrivalParts[2]);
            }
        } else if (arrival && arrival.val()) {
            const arrivalParts = dateFormatYMD(arrival.val()).split('-');
            if (arrivalParts.length === 3) {
                arrivalDate = new Date(arrivalParts[0], arrivalParts[1] - 1, arrivalParts[2]);
            }
        }

        if (!arrivalDate || isNaN(arrivalDate.getTime())) return;

        let depDate;
        let nights = 1;

        if (departureParam) {
            const depParts = departureParam.split('-');
            if (depParts.length === 3) {
                depDate = new Date(depParts[0], depParts[1] - 1, depParts[2]);
            }

            // ✅ Compute nights if both dates exist
            if (depDate && !isNaN(depDate.getTime())) {
                const timeDiff = depDate - arrivalDate;
                nights = Math.max(1, Math.ceil(timeDiff / (1000 * 60 * 60 * 24)));

                // ✅ Update .nightsCount input and description
                const minNightsGlobal = window.minNightsGlobal;
                const $nightsInput = $('.nightsCount input');
                const $nightsDescription = $('.nightsCount .gfield_description'); // adjust selector if needed

                $nightsInput.val(`${nights} Night${nights > 1 ? 's' : ''}`);
                if (typeof minNightsGlobal !== 'undefined') {
                    $nightsDescription.html(`Minimum of ${minNightsGlobal} night${minNightsGlobal > 1 ? 's' : ''}`);
                }
            }
        } else {
            // Fallback to computing departure from nights input
            const inputNights = parseInt($('.nightsCount input').val()) || 1;
            nights = inputNights;
            depDate = new Date(arrivalDate);
            depDate.setDate(depDate.getDate() + nights);
        }

        if (!depDate || isNaN(depDate.getTime())) return;

        // Format and update departure field
        const englishFormat = dateFormatEnglish(depDate);
        const isoFormat = depDate.getFullYear() + '-' +
                        String(depDate.getMonth() + 1).padStart(2, '0') + '-' +
                        String(depDate.getDate()).padStart(2, '0');

        departure.val(englishFormat);
        departure.attr('data-iso-date', isoFormat);
    }


    function getRatePlanByNights(ratePlans, nightCount) {
        // Sort rate plans descending by minNights so higher minNights come first
        const sortedPlans = ratePlans.slice().sort((a, b) => b.ratePlan.minNights - a.ratePlan.minNights);
        
        // Return the first rate plan where nightCount >= minNights
        return sortedPlans.find(plan => nightCount >= plan.ratePlan.minNights) || null;
    }

    function slugify(text) {
        return text.toString().toLowerCase()
            .trim()
            .replace(/\s+/g, '-')      
            .replace(/[^\w\-]+/g, '') 
            .replace(/\-\-+/g, '-')   
            .replace(/^-+/, '')       
            .replace(/-+$/, '');       
    }

    // Validate guest count against accommodates count from page (assumed somewhere)
    function validateGuests() {
        const guestsTotal = adultsCount + childrensCount;
        const petsTotal = petsCount;
        const infantsTotal = infantsCount
        const accommodatesCount = parseInt($('.booking-accommodates .elementor-heading-title').html()) || 0;

        // const remainingPax = accommodatesCount - guestsTotal;    

        if (guestsTotal > accommodatesCount) {
            showError(`This property can accommodate a maximum of ${accommodatesCount} guests. Kindly update the number of guests to continue.`, 'error');
            // return false;
        }
        
        if(petsTotal > 1) {
            showError(`If you wish to bring additional pets, please contact us at <a href="tel:+441202683333" style="color:#022A49;">+44 1202 683333</a>.`, 'error');
            // return false;
        } 
        
        if(infantsTotal > 2) {
            showError(`If you wish to bring more than 2 infants, please contact us at <a href="tel:+441202683333" style="color:#022A49;">+44 1202 683333</a>.`, 'error');
            // return false;
        } 

        if (guestsTotal <= accommodatesCount && petsTotal <= 1 && infantsTotal <= 2) {
            clearError(); // clear all if valid
            return true;
        }
    }

    let stripe = null;
    let elements = null;

    const appearance = {
        theme: 'stripe', // Options: 'stripe', 'flat', 'night', 'none'
        labels: 'floating',
        variables: {
            colorPrimary: '#002a49',
            colorText: '#112337',
            borderRadius: '3px',
            fontFamily: '"Inter", sans-serif',
        },
        rules: { 
            '.Input': { 
                border: '1px solid #686e77', 
            },
            '.Input:focus': {
                borderColor: '#9cd0e2',
                boxShadow: '0 0 0 1px #9cd0e2a6',
            },
            '.Label': {
                color: '#112337',
            },
        }
    };

    function initStripeElements(clientSecret) {
        if (typeof Stripe === "undefined" || !clientSecret) return;

        const paymentElementContainer = document.getElementById('payment-element');
        if (!paymentElementContainer) return; // Exit if element is missing

        stripe = Stripe(guesty_ajax_booking.stripe_key);
        elements = stripe.elements({ clientSecret, appearance });

        const paymentElement = elements.create('payment');
        paymentElement.mount('#payment-element');

        // Handle real-time validation errors from the Payment Element
        paymentElement.on('change', function(event) {
            const displayError = document.getElementById('card-errors');
            if (displayError) {
                displayError.textContent = event.error ? event.error.message : "";
            }
        });
    }


    function paymentIntent(guestyQuoteData) {

        let nightsCount = parseInt($('.nightsCount input').val()) || 1;
        let selectedPlan = null;
        selectedPlan = getRatePlanByNights(guestyQuoteData.rates.ratePlans, nightsCount);
        
        const money = selectedPlan.money || selectedPlan.quote || {};
        const amountInPence = Math.round(money?.money?.commissionIncTax * 100 || 0);

        const $submitButton = $('.guestyBooking .gform_button');
        $submitButton.prop('disabled', true).val('Loading ...');

        $.ajax({
            type: 'POST',
            url: guesty_ajax_booking.ajax_url,
            data: {
                action: 'guesty_create_payment_intent',
                nonce: guesty_ajax_booking.nonce,
                amount: amountInPence,
                quote_id: guestyQuoteData._id,
            },
            success: function (response) {
                if (!response.success || !response.data?.clientSecret) {
                    showError(response.data?.message || 'Failed to create payment intent.', 'error');
                    $submitButton.prop('disabled', false).val('Confirm Booking');
                    return;
                }

                // ✅ Initialize Stripe Payment Element
                initStripeElements(response.data.clientSecret);
            },
            error: function () {
                showError('Something went wrong while creating the payment intent.', 'error');
            },
            complete: function () {
                $submitButton.prop('disabled', false).val('Confirm Booking');
            }
        });
    }

    function setupIntent(guestyQuoteData) {
        $('html, body').animate({ scrollTop: $('.guestyBooking').offset().top - 200 }, 100);

        const listingId = new URLSearchParams(window.location.search).get('listing_id');
        const quoteId = guestyQuoteData?._id || null;

        const email = $('.emailAddress input').val()?.trim();
        const firstName = $('.firstName input').val()?.trim();
        const lastName = $('.lastName input').val()?.trim();
        const phone = $('.phoneNumber input').val()?.trim();

        if (!email || !validateEmail(email)) {
            showError('Please enter a valid email address before continuing.', 'error');
            return;
        }

        if (!listingId) {
            showError('Missing listing ID for SetupIntent.', 'error');
            return;
        }

        const $submitButton = $('.guestyBooking .gform_button');
        $submitButton.prop('disabled', true).val('Loading ...');

        // $loadingScreen.fadeIn();

        $.ajax({
            type: 'POST',
            url: guesty_ajax_booking.ajax_url,
            data: {
                action: 'guesty_create_setup_intent',
                nonce: guesty_ajax_booking.nonce,
                listingId: listingId,
                quoteId: quoteId,
                email: email, 
                firstName: firstName,
                lastName: lastName,
                phone: phone
            },
            success: function (response) {
                $loadingScreen.fadeOut();
                if (!response.success || !response.data?.clientSecret || !response.data?.stripeAccount) {
                    showError(response.data?.message || 'Failed to create SetupIntent.', 'error');
                    $submitButton.prop('disabled', false).val('Confirm Booking');
                    return;
                }

                window.__guestyStripeCustomerId = response.data.customerId || null;
                window.__paymentProviderId      = response.data.paymentProviderId || null;

                // ✅ Override Stripe to use Guesty’s connected account
                stripe = Stripe(guesty_ajax_booking.stripe_key, {
                    stripeAccount: response.data.stripeAccount
                });

                // ✅ Initialize Stripe Elements with SetupIntent client secret
                elements = stripe.elements({
                    clientSecret: response.data.clientSecret,
                    appearance
                });

                const paymentElementContainer = document.getElementById('payment-element');
                if (!paymentElementContainer) return;

                const paymentElement = elements.create('payment');
                paymentElement.mount('#payment-element');

                paymentElement.on('change', function (event) {
                    const displayError = document.getElementById('card-errors');
                    if (displayError) {
                        displayError.textContent = event.error ? event.error.message : "";
                    }
                });

            },
            error: function () {
                showError('AJAX error while creating SetupIntent.', 'error');
            },
            complete: function () {
                $loadingScreen.fadeOut();
                $submitButton.prop('disabled', false).val('Confirm Booking');
            }
        });
    }

    async function setupGuestyPaymentForm(guestyQuoteData) {
        try {
            $('html, body').animate({ scrollTop: $('.guestyBooking').offset().top - 200 }, 100);

            let nightsCount = parseInt($('.nightsCount input').val()) || 1;
            const selectedPlan = getRatePlanByNights(guestyQuoteData.rates.ratePlans, nightsCount);
            const money = selectedPlan.money || selectedPlan.quote || {};
            const amount = money?.money?.hostPayout;
            if (!amount || isNaN(amount)) throw new Error("Invalid booking amount");
            
            const provider = await paymentProvider(); // ✅ wait for AJAX
            const providerId = provider.paymentProviderId || provider._id;

            window.__paymentProviderId      = providerId || null;

            if (!providerId) {
                throw new Error("Missing GuestyPay providerId");
            }

            // Wait for SDK to load
            await new Promise((resolve, reject) => {
                const start = Date.now();
                const check = setInterval(() => {
                    if (window.guestyTokenization) {
                        clearInterval(check);
                        resolve();
                    } else if (Date.now() - start > 5000) {
                        clearInterval(check);
                        reject(new Error("Guesty SDK timeout"));
                    }
                }, 200);
            });

            // console.log("✅ Guesty SDK loaded");

            const firstName = $(".firstName input").val() || "";
            const lastName = $(".lastName input").val() || "";
            const phoneNumber = $(".phoneNumber input").val() || "";
            const emailAddress = $(".emailAddress input").val() || "";
            const street = $(".address_line_1 input").val() || "";
            const city = $(".address_city input").val() || "";
            const state = $(".address_state input").val() || "";
            const zipCode = $(".address_zip input").val() || "";
            const countryRaw = $(".address_country select").val() || "";
            const country = normalizeCountry(countryRaw);

            // console.log('country: ', country);
            // console.log('amount: ', amount);

            // Render Guesty form
            window.guestyTokenization.render({
                containerId: "guesty-card-form",
                providerId: providerId,
                env: "production", // or "production"
                currency: "GBP",
                amount: amount,
                initialValues: {
                    firstName: firstName,
                    lastName: lastName,
                    street: street,
                    city: city,
                    state: state,
                    zipCode: zipCode,
                    country: country,
                    email: emailAddress,
                    phone: phoneNumber,
                },
                sections: [ "cardholderName", "billingAddress", "paymentDetails"],
                onStatusChange: (status) => console.log("Guesty form status:", status),
                onError: (error) => {
                    console.error("❌ Guesty form error:", error);
                    showError(error.message || "Payment form error", "error");
                },
            });

            $loadingScreen.fadeOut();

        } catch (err) {
            console.error("setupGuestyPaymentForm error:", err);
            showError("Failed to load Guesty payment form", "error");
        }
    }


    function paymentProvider() {
        return new Promise((resolve, reject) => {
            $.ajax({
            type: "POST",
            url: guesty_ajax_booking.ajax_url,
            data: {
                action: "payment_provider",
                nonce: guesty_ajax_booking.nonce,
                listingId: new URLSearchParams(window.location.search).get("listing_id"),
            },
            success: function (response) {
                if (response.success && response.data) {
                    const data = Array.isArray(response.data) ? response.data[0] : response.data;
                    // console.log("✅ Payment Provider:", data);
                    resolve(data);
                } else {
                    reject(new Error("No payment provider found"));
                }
            },
            error: function (xhr, status, error) {
                console.error("❌ Payment Provider fetch error:", error);
                reject(error);
            },
            });
        });
    }

    
    window.minNightsGlobal = 1; 
    
    function initGuestyBooking() {
        if (guestyBookingInitialized) return;
        guestyBookingInitialized = true;

        var totalBreakdown  = $('.totalBreakdown');
        var step1Details    = $('.step1-details-wrapper .elementor-widget-container');

        function populateInvoice(quote) {
            // Validate quote object
            if (!quote || !quote.rates || !quote.rates.ratePlans) return;

            // console.log("populateInvoice:", quote);

            const selectedPlan = getRatePlanByNights(quote.rates.ratePlans, nightsCount);
            if (!selectedPlan) return;

            const money = selectedPlan.money || selectedPlan.quote || {};
            const currency = String(money.currency || money?.money?.currency || activeCurrency || 'GBP').toUpperCase();
            activeCurrency = currency;
            const currencySymbol = getCurrencySymbol(currency);
            const invoiceItems = money?.money?.invoiceItems || [];
            const safeNights = Math.max(1, Number(nightsCount) || 1);
            const accommodationTotal = Number(money?.money?.fareAccommodationAdjusted ?? money?.money?.fareAccommodation ?? 0);
            const nightlyRate = accommodationTotal / safeNights;

            // Display Payment term in Invoice

            const urlParams = new URLSearchParams(window.location.search);
            const arrivalParam = urlParams.get('arrival');
            const arrivalDate = arrivalParam ? new Date(arrivalParam) : null;

            if (!arrivalDate || isNaN(arrivalDate.getTime())) {
                console.error("Invalid or missing arrival date from URL.");
                return;
            }
            const payOut = money?.money?.hostPayout;
            const reservationDate = new Date();
            const timeDiff = arrivalDate - reservationDate;
            const dayDiff = Math.ceil(timeDiff / (1000 * 60 * 60 * 24));

            let paymentHtml = '';
            let paymentHtmlFull = '';
            if (dayDiff > 56) {
                // Payment split: 25% now, 75% 8 weeks before arrival
                const deposit = (payOut * 0.25).toFixed(2);
                const balance = (payOut * 0.75).toFixed(2);
                const paidDate = dateFormatEnglish(reservationDate);

                const balanceDueDate = new Date(arrivalDate.getTime() - (56 * 24 * 60 * 60 * 1000));
                const balanceDueDateFormatted = dateFormatEnglish(balanceDueDate);

                paymentHtml = `
                    <div class="invoice-breakdown_wrapper payment-terms">
                        <div class="invoice-breakdown_row" style="align-items: flex-start;margin-bottom: 10px;">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total">
                                    <strong>25% Deposit</strong>
                                    <br>
                                    <span class="invoice-breakdown_ratePlane-name" style="line-height: 1;">due on booking</span>
                                </p>
                            </div>
                            <div class="invoice-breakdown_col vertical">
                                <p class="invoice-breakdown_price breakdown">${formattedAmount(deposit, currency)}</p>
                            </div>
                        </div>

                        <div class="invoice-breakdown_row" style="align-items: flex-start;">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total">
                                    <strong>75% Balance</strong>
                                    <br>
                                    <span class="invoice-breakdown_ratePlane-name" style="line-height: 1;">will be automatically taken on<br>${balanceDueDateFormatted}</span>
                                </p>
                            </div>
                            <div class="invoice-breakdown_col vertical">
                                <p class="invoice-breakdown_price breakdown">${formattedAmount(balance, currency)}</p>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                // Full payment now
                paymentHtmlFull = `
                    <span class="invoice-breakdown_ratePlane-name" style="line-height: 1.4;text-transform: none;">(Full Payment)</span>
                `;
            }
            
            // Start building invoice HTML
            let html = `
                <div class="invoice-breakdown">

                    <!-- PROMOTIONS AND DISCOUNTS -->
                    ${(quote.promotions || (Array.isArray(quote.coupons) && quote.coupons.length > 0)) ? `
                        <div class="invoice-breakdown_wrapper">
                            ${(Array.isArray(quote.coupons) && quote.coupons.length > 0) ? `
                            <div class="invoice-breakdown_row">
                                <div class="invoice-breakdown_col">
                                    <p class="invoice-breakdown_title">${quote.coupons[0].name}</p>
                                    <div class="coupon-removed"></div>
                                </div>
                                <div class="invoice-breakdown_col">
                                    ${quote.coupons[0].discountType === "PERCENT" ? `
                                        <p class="invoice-breakdown_price breakdown">-${quote.coupons[0].discount}%</p>
                                    ` : ''}
                                    ${quote.coupons[0].discountType === "FIXED" ? `
                                        <p class="invoice-breakdown_price breakdown">-${formattedAmount(quote.coupons[0].discount)}</p>
                                    ` : ''}
                                </div>
                            </div>
                            ` : ''}
                            ${quote.promotions ? `
                            <div class="invoice-breakdown_row">
                                <div class="invoice-breakdown_col">
                                    <p class="invoice-breakdown_title">${quote.promotions.name}</p>
                                </div>
                                <div class="invoice-breakdown_col">
                                    <p class="invoice-breakdown_price">-${quote.promotions.rule.discountAmount}%</p>
                                </div>
                            </div>
                            ` : ''}
                        </div>
                    ` : ''}

                    <!-- SUBTOTAL -->
                    <div class="invoice-breakdown_wrapper">
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total">
                                    <strong>Accommodation</strong>
                                    <span class="invoice-breakdown_ratePlane-name">(${safeNights} night${safeNights !== 1 ? 's' : ''} × ${formattedAmount(nightlyRate, currency)}; ${selectedPlan.ratePlan.name})</span>
                                </p>
                            </div>
                            <div class="invoice-breakdown_col vertical">
                                ${
                                    money?.money?.fareAccommodation && money?.money?.fareAccommodation !== money?.money?.fareAccommodationAdjusted
                                        ? `<p class="invoice-breakdown_price line-through">${formattedAmount(money.money.fareAccommodation)}</p>`
                                        : ''
                                }
                                <p class="invoice-breakdown_price">
                                    <strong>${formattedAmount(money?.money?.fareAccommodationAdjusted)}</strong>
                                </p>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="invoice-breakdown_wrapper additionalFees"> </div> 
                    <!-- FEES - Breakdown -->        
                    <div class="invoice-breakdown_wrapper">`;

            invoiceItems
                .filter(item => item.type === 'ADDITIONAL')
                .forEach(item => {
                    html += `
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_title">${item.title}</p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price breakdown">${formattedAmount(item.amount)}</p>
                            </div>
                        </div>
                    `;
                });

            html += `
                    <!-- FEES - Total -->
                    <!--
                    <div class="invoice-breakdown_wrapper">
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total">Total Fees</p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price">${formattedAmount(money?.money?.totalFees)}</p>
                            </div>
                        </div>
                    </div>
                    -->
                    <!-- SUBTOTAL before taxes -->
                    <!--
                    <div class="invoice-breakdown_wrapper">
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total"><strong>Subtotal before taxes</strong></p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price"><strong>${formattedAmount(money?.money?.subTotalPrice)}</strong></p>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    -->
                    <!-- TAXES - Breakdown -->        
                    <div class="invoice-breakdown_wrapper">`;

            invoiceItems
                .filter(item => item.type === 'TAX')
                .forEach(item => {
                    html += `
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_title">${item.title}</p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price breakdown">${formattedAmount(item.amount)}</p>
                            </div>
                        </div>
                    `;
                });

            html += `
                    </div>

                    <!-- TAXES - Total -->
                    <!-- 
                    <div class="invoice-breakdown_wrapper">
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total">Total Taxes</p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price">${formattedAmount(money?.money?.totalTaxes)}</p>
                            </div>
                        </div>
                    </div>
                    -->
                    <hr>
                    <!-- TOTAL -->
                    <div class="invoice-breakdown_wrapper">
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_total fs-20"><strong>Total</strong> ${paymentHtmlFull}</p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price fs-20"><strong>${formattedAmount(money?.money?.hostPayout)}</strong></p>
                            </div>
                        </div>
                    </div>
                    ${paymentHtml}

                </div>`;

            // Inject invoice HTML into summary container (assuming summary is a jQuery element for invoice container)
            totalBreakdown.html(html);
            step1Details.html(html);

            // OPTIONAL ADDITIONAL FEES SECTION - GROUPED BY TITLE
            let optionalFeesHtml = '';

            if (Array.isArray(money?.money?.optionalInvoiceItems) && money.money.optionalInvoiceItems.length > 0) {
                const groupedFees = {};

                // Group and count items by title
                money.money.optionalInvoiceItems.forEach(item => {
                    if (!groupedFees[item.title]) {
                        groupedFees[item.title] = {
                            amount: item.amount,
                            quantity: 1,
                            currency: item.currency
                        };
                    } else {
                        groupedFees[item.title].quantity += 1;
                        groupedFees[item.title].amount += item.amount;
                    }
                });

                optionalFeesHtml += `<div class="optional-fees-group upsells">`;

                // Build HTML
                for (const title in groupedFees) {
                    const fee = groupedFees[title];
                    const unitPrice = fee.amount / fee.quantity;
                    const quantityText = fee.quantity > 1 ? `${currencySymbol} ${unitPrice} × ${fee.quantity} =` : ''; // ← Only show if > 1

                    optionalFeesHtml += `
                        <div class="invoice-breakdown_row">
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_title">${title}</p>
                            </div>
                            <div class="invoice-breakdown_col">
                                <p class="invoice-breakdown_price breakdown">${quantityText} ${formattedAmount(fee.amount)}</p>
                            </div>
                        </div>
                    `;
                }

                optionalFeesHtml += `</div>`;
            }


            // Inject into the additionalFees wrapper
            step1Details.find('.invoice-breakdown_wrapper.additionalFees').html(optionalFeesHtml);
            totalBreakdown.find('.invoice-breakdown_wrapper.additionalFees').html(optionalFeesHtml);

        }

        window.populateInvoice = populateInvoice;

        function checkAvailability(forceMinNights = false) {
            $loadingScreen.fadeIn();

            const urlParams = new URLSearchParams(window.location.search);
            const arrivalParam = urlParams.get('arrival');
            const departureParam = urlParams.get('departure');

            const checkInDate = dateFormatYMD(arrival.val());
            const today = new Date();
            const todayYMD = today.toISOString().split('T')[0]; // Format today's date to 'YYYY-MM-DD'

            // Check if check-in date is in the past
            if (checkInDate < todayYMD) {
                showError("Please select a valid Arrival date. Past dates are not allowed.", 'error');
                return; // Exit early
            }
            
            let nights = parseInt($('.nightsCount input').val()) || 1;

            const depDate = new Date(checkInDate);
            depDate.setDate(depDate.getDate() + nights);

            // Set availability check end date to check-out minus 1 day
            const availabilityEndDate = new Date(depDate);
            availabilityEndDate.setDate(availabilityEndDate.getDate() - 1);
            const formattedAvailabilityEnd = availabilityEndDate.toISOString().split('T')[0];

            $.ajax({
                type: 'POST',
                url: guesty_ajax_booking.ajax_url,
                data: {
                    action: 'guesty_check_availability',
                    nonce: guesty_ajax_booking.nonce,
                    listingId: new URLSearchParams(window.location.search).get('listing_id'),
                    startDate: checkInDate,
                    endDate: formattedAvailabilityEnd,
                },
                success: function (response) {
                    if (response.success && response.data && Array.isArray(response.data.data.days)) {
                        const days = response.data.data.days;
                        const isBooked = days.some(day => ['booked', 'reserved'].includes(day.status));

                        if (isBooked) {
                            showError(`Looks like this property is no longer available for these dates. Try to broaden or change the date range or check out one of our other properties.`, 'error');
                        } else {
                            window.minNightsGlobal = days[0].minNights || 1;
                            const minNightsGlobal = window.minNightsGlobal;

                            if (arrivalParam && departureParam) { 
                                clearError();
                                updateDeparture();
                                fetchQuote(); 
                            } else {
                                if (forceMinNights && nights < minNightsGlobal) {
                                    clearError();
                                    updateDeparture();
                                    fetchQuote(); 
                                } else if (!forceMinNights && nights < minNightsGlobal) {
                                    showError(`Minimum stay is ${minNightsGlobal} nights.`, 'error'); 
                                    $submitButton.prop('disabled', false).val('Next');
                                }
                            }
                        }
                    } else {
                        $submitButton.prop('disabled', false).val('Next');
                        showError(`Failed to check the availability of this Property. \nPlease try again and reload the page.`, 'error');
                    }
                },
                error: function () {
                    console.log('An error occurred while checking the Availability.');
                    $submitButton.prop('disabled', false).val('Next');
                }
            });
        }

        

        // Fetch quote function using variables
        function fetchQuote() {
            $loadingScreen.fadeIn();

            if (!validateGuests()) {
                $loadingScreen.fadeOut();
                return;
            }

            const minNightsGlobal = window.minNightsGlobal;
            const checkInDate = dateFormatYMD(arrival.val());
            const nights = parseInt($('.nightsCount input').val()) || 1;

            var depDate = new Date(checkInDate);
            depDate.setDate(depDate.getDate() + nights);

            var formattedCheckOut = depDate.getFullYear() + '-' +
                String(depDate.getMonth() + 1).padStart(2, '0') + '-' +
                String(depDate.getDate()).padStart(2, '0');

            $.ajax({
                type: 'POST',
                url: guesty_ajax_booking.ajax_url,
                data: {
                    action: 'guesty_booking_data',
                    nonce: guesty_ajax_booking.nonce,
                    checkInDateLocalized: checkInDate,
                    checkOutDateLocalized: formattedCheckOut,
                    guestsCount: adultsCount + childrensCount + infantsCount,
                    listingId: new URLSearchParams(window.location.search).get('listing_id'),
                    source: 'website',
                    couponCode: couponCode.val(),
                    numberOfGuests: {
                        numberOfAdults: adultsCount,
                        numberOfChildren: childrensCount,
                        numberOfInfants: infantsCount,
                        numberOfPets: petsCount
                    }
                },
                success: function(response) {
                    // console.log('Quote :', response); 
                    
                    if (response.success && response.data) {
                        guestyQuoteData = response.data;
                        populateInvoice(response.data);
                        const appliedCoupons = Array.isArray(response.data.coupons) ? response.data.coupons : [];
                        if (appliedCoupons.length > 0) {
                            $('.couponCode').hide();
                        } else {
                            $('.couponCode').fadeIn();
                        }
                        $('.coupon-code-error').hide();
                    } else {
                        $('.guestyBooking .stepOne .gform_next_button').prop('disabled', false); 

                        if (response?.data?.message?.includes('ERR_COUPON_NOT_VALID')) {
                            $('.coupon-code-error').fadeIn();
                        } else if (response?.data?.message?.includes('ERR_TERMS_NOT_APPLICABLE')) {
                            showError(`Minimum of ${minNightsGlobal} night${minNightsGlobal > 1 ? 's' : ''}`, 'error'); 
                        } else {
                            $('.coupon-code-error').hide(); 
                            showError(`Please try again and reload the page.`, 'error'); 
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', error);
                },
                complete: function() {
                    $loadingScreen.fadeOut();
                }
            });
        }

        // Bind change events for all guest count selects and coupon input
        $('.adultsCount select, .childrensCount select, .infantsCount select, .petsCount select, .couponCode input').on('change', function (e, silent) {
            
            nightsCount = parseInt($('.nightsCount input').val()) || 1;
            adultsCount = parseInt($('.adultsCount select').val()) || 0;
            childrensCount = parseInt($('.childrensCount select').val()) || 0;
            infantsCount = parseInt($('.infantsCount select').val()) || 0;
            petsCount = parseInt($('.petsCount select').val()) || 0;

            updateDeparture();

            if (silent) return; // Prevent infinite loop on programmatic change
            checkAvailability(false);
        });

        // Initialize coupon apply button
        const $applyCouponBtn = $('<span>').text('Apply').attr('id', 'add-coupon').on('click', fetchQuote);
        $('.couponCode .ginput_container').append($applyCouponBtn);
        $('.coupon-code').append(`<p class="coupon-code-error">The coupon code you entered is not valid. Please check the code and try again.</p>`);

        // Coupon removal event
        $(document).on('click', '.coupon-removed', () => {
            couponCode.val('');
            fetchQuote();
        });

        const tooltip_message = $('.tooltip .gfield_description');
        tooltip_message.css("display", "none");

        $('.tooltip .gfield_label').append(`
            <div class="tooltip_wrapper">
                <p class="tooltip_message">${tooltip_message.html()}</p>
                <span class="tooltip_icon">?</span>
            </div>
        `);
  
        // Now check and run logic if arrival has a value
        if (arrival.val()) {
            arrival.val(dateFormatEnglish(arrival.val()));
            updateDeparture();
            checkAvailability(true);
        }
    }

    window.selectedAdditionalFees = {}; // feeId => quantity

    // Upsell or Additional Fees
    function fetchAdditionalFees(params = null, callback) {
        $loadingScreen.fadeIn();
        jQuery.ajax({
            url: guesty_ajax_booking.ajax_url,
            method: 'POST',
            data: {
                action: 'guesty_additional_fees_get',
                nonce: guesty_ajax_booking.nonce,
                listingId: new URLSearchParams(window.location.search).get('listing_id'),
            },
            success: function (response) {
                if (response.success && response.data) {
                    if (window.__feesRendered) return;
                    window.__feesRendered = true;

                    // console.log('Additional fees:', response.data);

                    const bookingTags = window.bookingTags || [];

                    const fees = response.data.fees || [];
                    const dogLimit = response.data.dog_limit || 0;
                    const filteredFees = fees.filter(fee =>
                        (fee.isUpsell) ||
                        (fee.isUpsell && fee.type === 'PET' && bookingTags.includes("Pets"))
                    );

                    $('.upsellSection, .upsellContainer').removeClass('gfield_visibility_hidden');
                    $('.admin-hidden-markup').hide('gfield_visibility_hidden');

                    const selectedPetsCount = 0;
                    

                    let html = '';

                    if (filteredFees.length > 0) {
                        $('.upsellSection, .upsellContainer').fadeIn();
                        filteredFees.forEach((fee, index) => {
                            const isPetUpsell = fee.type?.toUpperCase() === 'PET' &&
                                bookingTags?.some(tag => tag.toUpperCase() === 'PET FRIENDLY');

                            const quantityValue = isPetUpsell
                                ? selectedPetsCount || 0
                                : (window.selectedAdditionalFees?.[fee._id] || 0);

                            const disabledAttr = isPetUpsell ? 'disabled' : '';
                            const maxAttr = isPetUpsell ? dogLimit : 3;
                            
                            html += `
                                <div class="upsell-box" data-fee-id="${fee._id}">
                                    <div class="upsell-img">
                                        <img class="upsell-image" src="${fee.upsell?.images?.[0]?.url || window.guesty_ajax_booking?.placeholder_image || ''}" alt="${fee.upsell?.images?.[0]?.fileName || ''}">
                                    </div>
                                    <div class="upsell-details">
                                        <p class="upsell-title">${fee.name}</p>
                                        <p class="upsell-description">${fee.upsell?.description || ''}</p>
                                        <div class="upsell-price-qty">
                                            <div class="upsell-price">${formattedAmount(fee.value || 0)}</div>
                                            <div class="upsell-qty number-input">
                                                <span class="decrease">-</span>
                                                <input type="number" min="0" max="${maxAttr}" value="${quantityValue}" data-fee-id="${fee._id}" ${disabledAttr} readonly>
                                                <span class="increase">+</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>`;
                        });

                        $('.upsellContainer .upsell-wrapper').html(html); 

                        // 🔥 Re-apply previously selected fees AFTER rendering UI
                        setTimeout(() => {
                            if (window.selectedAdditionalFees && Object.keys(window.selectedAdditionalFees).length > 0) {
                                updateAdditionalFeesOnQuote(window.selectedAdditionalFees);
                            }
                        }, 100);

                        // Bind handlers only once
                        if (!window.__upsellHandlersBound) {
                            window.__upsellHandlersBound = true;

                            $(document).on('click', '.upsell-qty .increase', function () {
                                const $input = $(this).siblings('input');
                                const currentVal = parseInt($input.val()) || 0;
                                const max = parseInt($input.attr('max')) || Infinity;

                                if (currentVal < max) {
                                    $input.val(currentVal + 1).trigger('change');
                                }
                            });

                            $(document).on('click', '.upsell-qty .decrease', function () {
                                const $input = $(this).siblings('input');
                                const currentVal = parseInt($input.val()) || 0;
                                const min = parseInt($input.attr('min')) || 0;

                                if (currentVal > min) {
                                    $input.val(currentVal - 1).trigger('change');
                                }
                            });

                            // Improved debounce with PET skip logic
                            let feeUpdateTimeout;
                            $(document).on('change', '.upsell-qty input', function () {
                                const $input = $(this);
                                const isDisabled = $input.is(':disabled');

                                const applyFeeUpdate = () => {
                                    const feeMap = {};
                                    $('.upsell-qty input').each(function () {
                                        const qty = parseInt($(this).val(), 10);
                                        const feeId = $(this).data('fee-id');
                                        if (qty > 0) {
                                            feeMap[feeId] = qty;
                                        }
                                    });

                                    // Save globally for reuse
                                    window.selectedAdditionalFees = feeMap;
                                    updateAdditionalFeesOnQuote(window.selectedAdditionalFees);
                                };

                                if (isDisabled) {
                                    applyFeeUpdate(); // No debounce for disabled inputs
                                } else {
                                    clearTimeout(feeUpdateTimeout);
                                    feeUpdateTimeout = setTimeout(applyFeeUpdate, 500);
                                }
                            });
                        }

                        if (typeof callback === 'function') {
                            callback(filteredFees);
                        }

                        // Trigger change manually for disabled PET inputs
                        // TODO: IF the pet is 0 dont trigger it.
                        // $('.upsell-qty input[disabled]').each(function () {
                        //     $(this).trigger('change');
                        // });

                        $('html, body').animate({ scrollTop: $('.guestyBooking').offset().top - 200 }, 100);
                    } else {
                        // showError('There are no add-ons available for this property.', 'warning');
                    }
                } else {
                    console.error('Failed to fetch additional fees:', response);
                }
            },
            error: function (err) {
                console.error('AJAX error:', err);
            },
            complete() {
                // $loadingScreen.fadeOut();
            }
        });
    }


    function updateAdditionalFeesOnQuote(feeMap = {}) {
        $loadingScreen.fadeIn();

        const inquiryId = guestyQuoteData?._id;
        const ratePlans = guestyQuoteData?.rates?.ratePlans || [];

        // Validate inquiryId and ratePlans
        if (!inquiryId) {
            $loadingScreen.fadeOut();
            return;
        }

        if (!Array.isArray(ratePlans) || ratePlans.length === 0) {
            $loadingScreen.fadeOut();
            return;
        }

        // Build ratePlanIds
        const ratePlanIds = ratePlans.map(rp => rp.ratePlan?._id).filter(Boolean);
        if (ratePlanIds.length === 0) {
            $loadingScreen.fadeOut();
            return;
        }

        // Build items array (can be empty to remove all upsells)
        const items = Object.entries(feeMap).map(([feeId, quantity]) => ({
            feeId,
            quantity: parseInt(quantity)
        }));


        $.ajax({
            type: 'POST',
            url: guesty_ajax_booking.ajax_url,
            data: {
                action: 'guesty_additional_fees_post',
                nonce: guesty_ajax_booking.nonce,
                inquiryId,
                ratePlanIds: JSON.stringify(ratePlanIds),
                items: JSON.stringify(items) // Send an empty array [] if no fees selected
            },
            success(response) {
                if (response.success) {
                    guestyQuoteData = response.data;
                    // console.log("Quote With Upsell",response.data);
                    populateInvoice(response.data);
                    // paymentIntent(guestyQuoteData);
                } else {
                    console.error('Failed to update additional fees:', response.data?.message || response);
                }
            },
            error(xhr, status, error) {
                console.error('AJAX error:', error);
            },
            complete() {
                $loadingScreen.fadeOut();
            }
        });
    }

    function ensureBookingPolicies() {
        const config = window.guesty_ajax_booking || {};
        const cancellation = String(config.cancellation_policy || '').trim();
        const houseRules = String(config.house_rules || '').trim();
        const $page = $('.guestyBooking .gform_page:visible');
        if (!$page.length || $page.find('.guesty-policy-consent').length) {
            return;
        }

        const $panel = $('<section>', {
            class: 'guesty-policy-consent',
            'aria-label': 'Booking policies and consent'
        });
        $panel.append($('<h3>').text('Policies and House Rules'));

        if (cancellation) {
            const $section = $('<div>', { class: 'guesty-policy-section' });
            $section.append($('<h4>').text('Cancellation Policy'));
            $section.append($('<p>').text(cancellation));
            $panel.append($section);
        }
        if (houseRules) {
            const $section = $('<div>', { class: 'guesty-policy-section' });
            $section.append($('<h4>').text('Property / House Rules'));
            $section.append($('<p>').text(houseRules));
            $panel.append($section);
        }

        const $cancelField = $('<div>', { class: 'gfield guesty-consent-field' });
        const $cancelLabel = $('<label>');
        $cancelLabel.append($('<input>', {
            type: 'checkbox',
            id: 'guesty-cancellation-consent',
            'aria-required': 'true'
        }));
        $cancelLabel.append(document.createTextNode(' I agree to the cancellation policy.'));
        $cancelField.append($cancelLabel);

        const $termsField = $('<div>', { class: 'gfield guesty-consent-field' });
        const $termsLabel = $('<label>');
        $termsLabel.append($('<input>', {
            type: 'checkbox',
            id: 'guesty-terms-consent',
            'aria-required': 'true'
        }));
        if (config.terms_url) {
            $termsLabel.append(document.createTextNode(' I agree to the '));
            $termsLabel.append($('<a>', { href: config.terms_url, target: '_blank', rel: 'noopener noreferrer', text: 'terms and conditions' }));
            $termsLabel.append(document.createTextNode('.'));
        } else {
            $termsLabel.append(document.createTextNode(' I agree to the terms and conditions.'));
        }
        $termsField.append($termsLabel);

        $panel.append($cancelField, $termsField);
        const $footer = $page.find('.gform_page_footer').first();
        if ($footer.length) {
            $panel.insertBefore($footer);
        } else {
            $page.append($panel);
        }

        const $submit = $page.find('.gform_button');
        const updateConsentState = function () {
            const accepted = $('#guesty-cancellation-consent').is(':checked') && $('#guesty-terms-consent').is(':checked');
            $submit.prop('disabled', !accepted);
        };
        $panel.on('change', 'input[type=checkbox]', updateConsentState);
        updateConsentState();
    }

    $(document).on('gform_post_render', function (event, formId, currentPage) {

        // if($('.guestyBooking')) {
        //     $('html, body').animate({ scrollTop: $('.guestyBooking').offset().top - 200 }, 100);
        // }

        // UI behavior based on tag
        if (!bookingTags.includes("Pets")) {
            $('.petsCount').hide();
            $('.adultsCount, .childrensCount, .infantsCount').css("grid-column", "span 4");
        }
        
        currentStepPage = currentPage;

        const $formBody = $('#gform_' + formId + ' .gform-body.gform_body');

        // Only append if not already present
        if ($formBody.find('ul.form-message').length === 0) {
            $formBody.prepend('<ul class="form-message" style="display: none;"></ul>');
        }

        var $form = jQuery('#gform_' + formId);
        $form.find('.readonly input').prop('readonly', true);
        let nightsCount = parseInt($('.nightsCount input').val()) || 1;
        let nights = nightsCount;
        let label = nights === 1 ? "Night" : "Nights";
        let selectedPlan = null;
        let currencySymbol = getCurrencySymbol(activeCurrency); // Default fallback

        const $phoneInput = $('.phoneNumber input');

        // Prevent letters in phone field
        $phoneInput.on('input', function () {
            const cleaned = $(this).val().replace(/[^0-9\s\-\+\(\)]/g, '');
            $(this).val(cleaned);
        });        

        if (guestyQuoteData?.rates?.ratePlans) {
            selectedPlan = getRatePlanByNights(guestyQuoteData.rates.ratePlans, nights);
            const money = selectedPlan?.money || selectedPlan?.quote || {};
            currencySymbol = getCurrencySymbol(money?.currency || 'GBP');
        } 

        // Step 1 logic
        if (formId == guestyBookingID && currentStepPage == 1) {
            $('#calendar-container').fadeIn();
            $('.step1-details').hide();
            guestyBookingInitialized = false; // Reset when returning to step 1
            window.__feesRendered = false;
            // window.selectedAdditionalFees = {};
            initGuestyBooking();
            fetchAdditionalFees();

            $('.nightsCount .gfield_description').html(
                `Minimum of ${minNightsGlobal} night${minNightsGlobal > 1 ? 's' : ''}`
            );
        }

        // Step 2 logic
        if (formId == guestyBookingID && currentStepPage == 2) {
            if (guestyQuoteData && selectedPlan) {
                window.__feesRendered = false;

                if (guestyQuoteData && selectedPlan) {
                    $('#calendar-container').hide();
                    $('.step1-details').fadeIn();
                    $('.step1-arrival-date .elementor-heading-title').html(dateFormatEnglish(guestyQuoteData.checkInDateLocalized));
                    $('.step1-departure-date .elementor-heading-title').html(dateFormatEnglish(guestyQuoteData.checkOutDateLocalized));
                    $('.step1-night-counts .elementor-heading-title').html(`${nights} ${label}`);

                    $loadingScreen.fadeOut();
                }
            }
        }

        // Step 3 logic
        if (formId == guestyBookingID && currentStepPage == 3) {
            ensureBookingPolicies();
            if (guestyQuoteData && selectedPlan) {
                // setupIntent(guestyQuoteData || null);
                setupGuestyPaymentForm(guestyQuoteData || null);
            }
        }
    });

    const $submitButton = $('.guestyBooking .gform_button');
    let paymentAlreadyConfirmed = false; 

    async function retryRequest(fn, maxAttempts = 3, delay = 5000) {
        const retryMessage = "Oops! Something went wrong—we're retrying your booking now. Please hold on.";
        const supportPhone = String((window.guesty_ajax_booking && guesty_ajax_booking.contact_phone) || '').trim();
        const supportEmail = String((window.guesty_ajax_booking && guesty_ajax_booking.contact_email) || '').trim();
        const supportParts = [];
        if (supportPhone) supportParts.push(`<a href="tel:${supportPhone.replace(/[^+\d]/g, '')}" style="color:#022A49;">${supportPhone}</a>`);
        if (supportEmail) supportParts.push(`<a href="mailto:${encodeURIComponent(supportEmail)}" style="color:#022A49;">${supportEmail}</a>`);
        const finalFailMessage = `Unfortunately, we couldn't complete your booking after multiple attempts.${supportParts.length ? '<br>Please contact us at ' + supportParts.join(' or ') + ' so we can assist you.' : '<br>Please contact the property team so we can assist you.'}`;

        // Cache button selectors
        const $backButton = $('.gform_previous_button');
        const $submitButton = $('.gform_button');

        // Disable buttons before retrying
        $backButton.prop('disabled', true);
        $submitButton.prop('disabled', true);

        for (let attempt = 1; attempt <= maxAttempts; attempt++) {
            try {
                if (attempt > 1) {
                    showError(retryMessage, 'warning'); // show retry message (non-blocking)
                }
                const result = await fn();
                clearError(retryMessage); // remove retry message on success
                $backButton.prop('disabled', false);
                $submitButton.prop('disabled', false);
                return result;
            } catch (err) {
                console.warn(`Attempt ${attempt} failed:`, err);
                if (attempt === maxAttempts) {
                    clearError(retryMessage); // clear retry message if it was shown
                    showError(finalFailMessage, 'error');
                    throw err;
                }
                await new Promise(resolve => setTimeout(resolve, delay));
            }
        }
    }

    // Helper: Native POST using fetch (mimics $.post)
    async function postData(action, data = {}) {
        const body = new URLSearchParams({ action, ...data });
        const response = await fetch(guesty_ajax_booking.ajax_url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        });

        const result = await response.json();

        if (!response.ok || result.success === false) {
            const message = result.data?.message || result.message || 'Unknown error';
            throw new Error(message);
        }

        return result;
    }


    if(guestyBookingID) {
        gform.initializeOnLoaded(function () {
            gform.utils.addAsyncFilter('gform/submission/pre_submission', async (formData, event) => {
                const $clicked = document.activeElement;
                const isBackButton = $($clicked).hasClass('gform_previous_button');
                const currentPage = parseInt(currentStepPage, 10);

                if (isBackButton) return formData;
                // if (currentPage !== 2) return formData;
                if (paymentAlreadyConfirmed) return formData;

                $loadingScreen.fadeIn();

                clearError();

                let hasEmpty = false; 
                let emailInvalid = false;

                if (currentPage === 2 || currentPage === 3) {
                    
                    $('.guestyBooking .gform_page:visible :input[aria-required="true"]').each(function () {
                        const $input = $(this);
                        const type = $input.attr('type');
                        let value = $input.val()?.trim();
                        const $field = $input.closest('.gfield');

                        // Checkboxes
                        if ($input.is(':checkbox')) {
                            value = $input.is(':checked');
                        }

                        // Email format check (takes priority)
                        if (type === 'email') {
                            if (!value) {
                                $field.addClass('gfield_error');
                                hasEmpty = true;
                            } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                                $field.addClass('gfield_error');
                                emailInvalid = true;
                            } else {
                                $field.removeClass('gfield_error');
                            }
                            return; // skip the general empty check
                        }

                        // General required check
                        if (!value) {
                            $field.addClass('gfield_error');
                            hasEmpty = true;
                        } else {
                            $field.removeClass('gfield_error');
                        }
                    });

                    if (emailInvalid) {
                        showError('Please enter a valid email address.', 'error');
                        $('html, body').animate({ scrollTop: $('#gform_' + guestyBookingID).offset().top - 200 }, 100);
                        formData.abort = true;
                        return formData;
                    }

                    if (hasEmpty) {
                        showError('Please fill in all required fields.', 'error');
                        $('html, body').animate({ scrollTop: $('#gform_' + guestyBookingID).offset().top - 200 }, 100);
                        formData.abort = true;
                        return formData;
                    }


                    if (!guestyQuoteData || !guestyQuoteData._id) {
                        showError('Booking session has expired. Please refresh the page and try again.', 'error');
                        formData.abort = true;
                        return formData;
                    }
                }

                if (currentPage === 3) {
                    const cancellationAccepted = $('#guesty-cancellation-consent').is(':checked');
                    const termsAccepted = $('#guesty-terms-consent').is(':checked');
                    if (!cancellationAccepted || !termsAccepted) {
                        showError('Please accept the cancellation policy and terms and conditions before payment.', 'error');
                        $loadingScreen.fadeOut();
                        formData.abort = true;
                        return formData;
                    }

                    $loadingScreen.fadeOut();
                    $submitButton.prop('disabled', true).val('Processing...');

                    // ✅ Extract guest details
                    const firstName = $('.firstName input');
                    const lastName = $('.lastName input');
                    const phoneNumber = $('.phoneNumber input');
                    const emailAddress = $('.emailAddress input');
                    const street = $('.address_line_1 input');
                    const city = $('.address_city input');
                    const state = $('.address_state input');
                    const zipCode = $('.address_zip input');
                    const country = $('.address_country select');

                    const guest = {
                        firstName: firstName?.val?.() || '',
                        lastName: lastName?.val?.() || '',
                        phone: phoneNumber?.val?.() || '',
                        email: emailAddress?.val?.() || '',
                        address: {
                            street: street?.val?.() || '',
                            zipCode: zipCode?.val?.() || '',
                            city: city?.val?.() || '',
                            state: state?.val?.() || '',
                            country: country?.val?.() || ''
                        }
                    };

                    const selectedPlan = getRatePlanByNights(guestyQuoteData.rates.ratePlans, nightsCount);
                    const money = selectedPlan.money || selectedPlan.quote || {};
                    totalFare = money?.money?.hostPayout;

                
                    try {
                        // console.log("window.__paymentProviderId: ", window.__paymentProviderId);
                        // ✅ 1. Validate Guesty form
                        // console.log('🔄 Validating Guesty form...');
                        window.guestyTokenization.validate();

                        // ✅ 2. Submit to get token
                        // console.log('💳 Submitting Guesty tokenization form...');
                        
                        const paymentMethod = await window.guestyTokenization.submit();

                        // console.log('🧾 Raw Guesty tokenization response:', paymentMethod);

                        const guestyCardToken = paymentMethod?._id || paymentMethod?.token;
                        if (!guestyCardToken) {
                            console.error('⚠️ No payment token or _id returned from Guesty:', paymentMethod);
                            throw new Error('No payment token returned from Guesty');
                        }

                        // console.log('✅ Guesty payment token:', guestyCardToken);
                        // console.log('✅ ratePlanId:', selectedPlan?.ratePlan?._id);

                        // Guest creation is handled by reservations-v3/quote to avoid a redundant API call.

                        // ✅ 4. Create Reservation
                        let reservation;
                        try {
                            reservation = await retryRequest(() => 
                                postData('guesty_booking_reservation', {
                                    nonce: guesty_ajax_booking.nonce,
                                    quoteId: guestyQuoteData?._id || '',
                                    ratePlanId: selectedPlan?.ratePlan?._id,
                                    guest: JSON.stringify(guest),
                                    guesty_payment_token: guestyCardToken,   // ✅ from tokenization
                                    payment_provider_id: window.__paymentProviderId, // ✅ from payment-provider API
                                    listingId: new URLSearchParams(window.location.search).get('listing_id') || '',
                                    checkIn: guestyQuoteData?.checkInDateLocalized || '',
                                    checkOut: guestyQuoteData?.checkOutDateLocalized || '',
                                })
                            );
                        } catch (err) {
                            showError('Reservation Failed: ' + err.message, 'error');
                            $submitButton.prop('disabled', false).val('Confirm Booking');
                            formData.abort = true;
                            return formData;
                        }

                        guestyConfirmationData = reservation.data;

                        // ✅ 5. Attach Payment Method to Guesty
                        try {
                            const paymentResponse = await retryRequest(() =>
                                postData('guesty_payment_method', {
                                    nonce: guesty_ajax_booking.nonce,
                                    guestID: guestyConfirmationData.guestId,
                                    paymentProviderId: window.__paymentProviderId, // stored earlier when form was setup
                                    guestyCardToken: guestyCardToken,
                                    reservationId: guestyConfirmationData.reservationId
                                })
                            );
                            // console.log('✅ Payment method attachedxx1:', paymentResponse);
                        } catch (err) {
                            showError('Payment Method Error: ' + err.message, 'error');
                            $submitButton.prop('disabled', false).val('Confirm Booking');
                            formData.abort = true;
                            return formData;
                        }

                        $loadingScreen.fadeOut();

                    } catch (error) {
                        console.error('Guesty tokenization error:', error);
                        showError(error.message || 'An unknown error occurred.', 'error');
                        $submitButton.prop('disabled', false).val('Confirm Booking');
                        formData.abort = true;
                        return formData;
                    }
                }

                return formData;
            });
        });
    }

    $(document).on('gform_confirmation_loaded', function(event, formId) {
        if (parseInt(formId) !== parseInt(guestyBookingID)) return;
        if (!guestyConfirmationData) return;

        $('html, body').animate({ scrollTop: $('.guestyBooking').offset().top - 200 }, 100);

        const $confirmationWrapper = $(`#gform_confirmation_wrapper_${formId} #reservation-wrapper`);
        if ($confirmationWrapper.length) {
            const confirmationCode = guestyConfirmationData.confirmationCode || 'N/A';
            const urlParams = new URLSearchParams(window.location.search);
            const arrivalParam = urlParams.get('arrival');
            const arrivalDate = arrivalParam ? new Date(arrivalParam) : null;

            if (!arrivalDate || isNaN(arrivalDate.getTime())) {
                console.error("Invalid or missing arrival date from URL.");
                return;
            }

            const reservationDate = new Date();
            const timeDiff = arrivalDate - reservationDate;
            const dayDiff = Math.ceil(timeDiff / (1000 * 60 * 60 * 24));

            let paymentHtml = '';
            if (dayDiff > 56) {
                // Payment split: 25% now, 75% 8 weeks before arrival
                const deposit = (totalFare * 0.25).toFixed(2);
                const balance = (totalFare * 0.75).toFixed(2);
                const paidDate = dateFormatEnglish(reservationDate);

                const balanceDueDate = new Date(arrivalDate.getTime() - (56 * 24 * 60 * 60 * 1000));
                const balanceDueDateFormatted = dateFormatEnglish(balanceDueDate);

                paymentHtml = `
                    <p class="reservation-subtitle">Total Accommodation Fare: ${formattedAmount(totalFare)}</p>
                    <p class="reservation-label">Deposit Fee (25%): ${formattedAmount(deposit)} <span class="reservation-status paid">PAID</span></p>
                    <p class="reservation-dateCollect">Paid on: ${paidDate}</p>
                    <p class="reservation-label">Balance (75%): ${formattedAmount(balance)} <span class="reservation-status pending">PENDING</span></p>
                    <p class="reservation-dateCollect">Payment Scheduled for: ${balanceDueDateFormatted}</p>
                `;
            } else {
                // Full payment now
                const paidDate = dateFormatEnglish(reservationDate);
                paymentHtml = `
                    <p class="reservation-subtitle fullpayment">Total Accommodation Fare: ${formattedAmount(totalFare)} <span class="reservation-status paid">PAID</span></p>
                    <p class="reservation-dateCollect">Paid on: ${paidDate}</p>
                `;
            }

            const detailsHtml = `
            <div class="reservation-wrapper">
                <div class="reservation-details">
                    <p class="reservation-title">Payment ID:</p>
                    <h3 class="reservation-code">
                        ${window.__paymentProviderId}
                        <span class="reservation-copy">
                            <img src="${window.guesty_ajax_booking?.copy_icon || ''}" alt="">
                            <span class="tooltip-text">Copied!</span>
                        </span>
                    </h3>
                </div>
                <div class="reservation-details">
                    <p class="reservation-title">Confirmation Code:</p>
                    <h3 class="reservation-code">
                        ${confirmationCode}
                        <span class="reservation-copy">
                            <img src="${window.guesty_ajax_booking?.copy_icon || ''}" alt="">
                            <span class="tooltip-text">Copied!</span>
                        </span>
                    </h3>
                </div>

                <div class="reservation-details">
                    <p class="reservation-title">Invoice:</p>
                    
                    ${paymentHtml}
                </div>
            </div>
            `;
            $confirmationWrapper.append(detailsHtml);
        }
    }); 

    // Clipboard copy + fallback + tooltip
    $(document).on('click', '.reservation-copy img', function () {
        const $copyWrapper = $(this).closest('.reservation-copy');
        const $codeText = $copyWrapper.closest('h3').contents().filter(function () {
            return this.nodeType === 3;
        }).text().trim();

        if (!$codeText) return;

        function showTooltip($el) {
            $el.addClass('show-tooltip');
            setTimeout(() => {
                $el.removeClass('show-tooltip');
            }, 1500);
        }

        // Primary (modern) method
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText($codeText).then(() => {
                showTooltip($copyWrapper);
            }).catch(err => {
                console.warn('Clipboard API failed, using fallback:', err);
                fallbackCopy($codeText);
                showTooltip($copyWrapper);
            });
        } else {
            fallbackCopy($codeText);
            showTooltip($copyWrapper);
        }

        // Fallback method for HTTP / older browsers
        function fallbackCopy(text) {
            const $textarea = $('<textarea>');
            $textarea.val(text)
                .css({
                    position: 'fixed',
                    opacity: 0
                });
            $('body').append($textarea);
            $textarea.focus().select();
            try {
                document.execCommand('copy');
            } catch (err) {
                console.error('Fallback copy failed:', err);
            }
            $textarea.remove();
        }
    });

    // Exit Intent
    const path = window.location.pathname.replace(/\/$/, '');
    const bookingPath = new URL((window.guesty_ajax_booking && guesty_ajax_booking.booking_url) || '/booking/', window.location.origin).pathname.replace(/\/$/, '');
    if (path.startsWith(bookingPath)) {
        let isExitModalShown = false;
        let pendingNavigation = null;

        // Inject your modal HTML if not already present
        if ($('#exit-intent').length === 0) {
            $('body').append(`
                <div id="exit-intent" style="display:none;">
                    <div class="exit-intent-wrapper">
                        <p class="exit-intent-header">Are you sure you want to leave this page?</p>
                        <p class="exit-intent-text">You're in the middle of a booking, and your progress might be lost if you leave now. Would you like to stay and finish your booking?</p>
                        <div class="exit-intent-cta">
                            <button class="exit-intent-cta primary stay">Stay on this page</button>
                            <button class="exit-intent-cta secondary leave">Leave anyway</button>
                        </div>
                    </div>
                </div>
            `);
        }

        const $exitIntent = $('#exit-intent');

        function showExitModal(callback = null) {
            if (!isExitModalShown) {
                isExitModalShown = true;
                $exitIntent.fadeIn();
                pendingNavigation = callback;
            }
        }

        // Intercept internal link clicks
        $(document).on('click', 'a', function (e) {
            const href = $(this).attr('href');
            if (
                href &&
                !href.startsWith('#') &&
                !href.startsWith('mailto:') &&
                !href.startsWith('tel:') &&
                !$(this).attr('target') &&
                href !== window.location.pathname + window.location.search
            ) {
                e.preventDefault();
                showExitModal(() => {
                    window.location.href = href;
                });
            }
        });

        // Show modal if mouse exits at top (exit intent)
        // $(document).on('mouseleave', function (e) {
        //     if (e.clientY < 0 && !isExitModalShown) {
        //         showExitModal();
        //     }
        // });

        // Stay button: hide modal
        // $(document).on('click', '.exit-intent-cta.secondary', function () {
        //     $exitIntent.fadeOut();
        //     isExitModalShown = false;
        //     pendingNavigation = null;
        // });

        function closeExitModal() {
            $exitIntent.fadeOut();
            isExitModalShown = false;
            pendingNavigation = null;
        }

        // Click outside modal
        $(document).on('click', '#exit-intent', function (e) {
            if (!$(e.target).closest('.exit-intent-wrapper').length) {
                closeExitModal();
            }
        });

        // Stay button
        $(document).on('click', '.exit-intent-cta.stay', closeExitModal);

        // Leave button: continue with stored navigation
        $(document).on('click', '.exit-intent-cta.leave', function () {
            $exitIntent.fadeOut();
            if (pendingNavigation) {
                pendingNavigation();
            } else {
                window.location.href = '/'; // fallback URL if no link was clicked
            }
        });
    }
 
});