jQuery(document).ready(function ($) {

	// Favourites
	let isExitModalShown = false;
	const favoritesKey = 'guestyFavorites';
	let favorites = JSON.parse(localStorage.getItem(favoritesKey)) || [];

	// Initialize hearts for posts already in the DOM
	$('.property-favorite').each(function () {
		const $el = $(this);
		const postId = $el.data('post-id')?.toString();
		if (favorites.includes(postId)) {
			$el.addClass('favorited');
		}
	});

	// Delegated click handler – works for both initial and AJAX-loaded posts
	$(document).on('click', '.property-favorite', function () {
		const $el = $(this);
		const postId = $el.data('post-id')?.toString();
		$el.toggleClass('favorited');
		const isFav = $el.hasClass('favorited');
		
		// Re-read favorites to be in sync with localStorage in case of external changes
		let favorites = JSON.parse(localStorage.getItem(favoritesKey)) || [];
		
		if (isFav) {
			if (!favorites.includes(postId)) {
				favorites.push(postId);
			}

			const propertyTitle = $el.closest('li').find('.property-title').text().trim();
			
			$('#fav-popup').fadeIn();
			$('#fav-popup .fav-popup-text').html(`<strong>${propertyTitle}</strong> has been successfully added to your favourite list. Tap the menu (☰) in the top-right or use the button below to open the Favourites page.`);
		} else {
			favorites = favorites.filter(id => id !== postId);
		}
		
		localStorage.setItem(favoritesKey, JSON.stringify(favorites));

		// auto-hide after 2s
		setTimeout(() => {
			$('#fav-popup').fadeOut();
		}, 10000);
	});

	// Favorites load
	if ($("#favorites-list").length) {
		if (favorites.length) {
			loadFavorites(1); // initial page
		} else {
			$("#favorites-no-selected.hidden").removeClass('hidden');
			$("#favorites-selected").addClass('hidden');
		}
	}

	// Inject your modal HTML if not already present
	if ($('#fav-popup').length === 0) {
		$('body').append(`
			<div id="fav-popup" style="display:none;">
				<div class="fav-popup-wrapper">
					<p class="fav-popup-header">
						<span>Added to Favourites</span>
						<span class="fav-popup-close"></span>
					</p>
					<p class="fav-popup-text"></p>
					<div class="fav-popup-cta">
						<a href="/favourites" class="fav-popup-cta primary stay">Check my favourites</a>
					</div>
				</div>
			</div>
		`);
	}

	let isSubmitting = false;

    // =========================
    // VALIDATE EMAILS
    // =========================
    function validateEmails(raw) {

        const emails = raw
            .split(',')
            .map(e => e.trim())
            .filter(Boolean);

        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

        const valid = emails.every(email => emailRegex.test(email));

        return valid ? emails : false;
    }

    // =========================
    // BUTTON LOADING STATE
    // =========================
    function setLoadingState($button, loading = true) {

        if (loading) {

            $button
                .prop('disabled', true)
                .addClass('loading')
                .html(`
                    <span class="btn-text">Sending...</span>
                    <span class="btn-loader"></span>
                `);

        } else {

            $button
                .prop('disabled', false)
                .removeClass('loading')
                .html('Send');
        }
    }

    // =========================
    // SUCCESS BUTTON STATE
    // =========================
    function setSuccessState($button) {

        $button
            .removeClass('loading')
            .addClass('success')
            .html(`
                <span class="btn-checkmark">✓</span>
                Sent Successfully
            `);

        setTimeout(() => {
            $button
                .removeClass('success')
                .prop('disabled', false)
                .html('Send');
        }, 2500);
    }

    // =========================
    // SHOW FORM MESSAGE
    // =========================
    function showMessage($message, text, type = 'error') {

        $message
            .removeClass('success error')
            .addClass(type)
            .html(text)
            .fadeIn();
    }

    // =========================
    // OPEN MODAL
    // =========================
    $(document).on('click', '#share-favorites-link', function () {
        let favorites = JSON.parse(localStorage.getItem('guestyFavorites')) || [];

        if (!favorites.length) return;

        $('#favorites-modal').fadeIn();
    });

    // =========================
    // CLOSE MODAL
    // =========================
    $(document).on('click', '.favorites-modal-close, .favorites-modal-overlay', function () {

        if (isSubmitting) return;

        $('#favorites-modal').fadeOut();
    });

    // =========================
    // SUBMIT FORM
    // =========================
    $(document).on('submit', '#favorites-share-form', function (e) {

        e.preventDefault();

        // prevent double submit
        if (isSubmitting) return;

        const favorites = JSON.parse(localStorage.getItem('guestyFavorites')) || [];

        if (!favorites.length) return;

        const $form = $(this);
        const $message = $form.find('.form-message');
        const $button = $form.find('button[type="submit"]');

        const sender_name = $form.find('[name="sender_name"]').val().trim();
        const sender_email = $form.find('[name="sender_email"]').val().trim();
        const recipient_raw = $form.find('[name="recipient_emails"]').val().trim();

        const recipients = validateEmails(recipient_raw);

        // clear old message
        $message.hide();

        // =========================
        // VALIDATION
        // =========================
        if (!sender_name) {
            return showMessage($message, 'Sender name is required.');
        }

        if (!sender_email) {
            return showMessage($message, 'Sender email is required.');
        }

        if (!recipients) {
            return showMessage($message, 'Please enter valid recipient email(s).');
        }

        // =========================
        // START LOADING
        // =========================
        isSubmitting = true;

        setLoadingState($button, true);

        $form.addClass('is-loading');

        // =========================
        // AJAX
        // =========================
        $.post(guesty_ajax_search.ajax_url, {
            action: 'send_favorites_email',
                nonce: guesty_ajax_search.nonce,
            sender_name,
            sender_email,
            recipient_emails: recipients.join(','),
            newsletter: $form.find('[name="newsletter"]').is(':checked') ? 1 : 0,
            favorites
        })

        .done(function (response) {

            if (response.success) {

                showMessage(
                    $message,
                    response.data.message,
                    'success'
                );

                $form[0].reset();

                setSuccessState($button);

                // auto close modal
                setTimeout(() => {
                    $('#favorites-modal').fadeOut();
                }, 2500);

            } else {

                showMessage(
                    $message,
                    response.data.message,
                    'error'
                );

                setLoadingState($button, false);
            }

        })

        .fail(function () {

            showMessage(
                $message,
                'Something went wrong. Please try again.',
                'error'
            );

            setLoadingState($button, false);
        })

        .always(function () {

            isSubmitting = false;

            $form.removeClass('is-loading');
        });

    });

	// Share your favorites to others
	// $(document).on('click', '#share-favorites-link', function () {
	// 	let favorites = JSON.parse(localStorage.getItem('guestyFavorites')) || [];

	// 	if (!favorites.length) return;

	// 	const baseUrl = window.location.origin + '/properties';
	// 	const shareUrl = `${baseUrl}?ids=${favorites.join(',')}`;

	// 	const $btn = $(this);
	// 	const $tooltip = $btn.find('.copy-tooltip');

	// 	function showTooltip() {
	// 		$tooltip.addClass('show');

	// 		setTimeout(() => {
	// 			$tooltip.removeClass('show');
	// 		}, 2000);
	// 	}

	// 	if (navigator.clipboard && window.isSecureContext) {
	// 		navigator.clipboard.writeText(shareUrl)
	// 			.then(showTooltip)
	// 			.catch(() => fallbackCopy(shareUrl));
	// 	} else {
	// 		fallbackCopy(shareUrl);
	// 	}

	// 	function fallbackCopy(text) {
	// 		const tempInput = document.createElement('input');
	// 		tempInput.value = text;
	// 		document.body.appendChild(tempInput);
	// 		tempInput.select();
	// 		document.execCommand('copy');
	// 		document.body.removeChild(tempInput);

	// 		showTooltip();
	// 	}
	// });

	function closeExitModal() {
		$('#fav-popup').fadeOut();
		isExitModalShown = false;
	}

	$(document).on('click', '.fav-popup-close', function () {
		closeExitModal();
	});

	// Click outside modal
	$(document).on('click', '#fav-popup', function (e) {
		if (!$(e.target).closest('.fav-popup-wrapper').length) {
			closeExitModal();
		}
	});

	function loadFavorites(page) {
		let favorites = JSON.parse(localStorage.getItem(favoritesKey)) || [];

		if (!favorites.length) {
			$('#share-favorites-link').parent().hide();
			$("#favorites-list").html('<p>No favourites selected.</p>');
			$("#load-more-favorites").hide();
			return;
		}

		const perPage = 6;
		const offset = (page - 1) * perPage;
		const pagedIds = favorites.slice(offset, offset + perPage);

		if (!pagedIds.length) {
			$("#load-more-favorites").hide();
			return;
		}

		$.post(
			guesty_ajax_search.ajax_url,
			{
				action: 'get_favorite_posts',
                nonce: guesty_ajax_search.nonce,
				ids: pagedIds,
				// paged: page
			},
			function (response) {
				if (!response.success) return;

				if (page === 1) {
					$("#favorites-list").html('<ul id="property-search"></ul>');
				}

				$("#favorites-list #property-search").append(response.data.html);
				initSlickGallery("#favorites-list");

				// Update share button visibility
				const validIds = $("#favorites-list #property-search li")
					.map(function () { return $(this).data('post-id')?.toString(); })
					.get();

				$('#share-favorites-link').parent().toggle(validIds.length > 0);

				// Restore hearts
				$("#favorites-list .property-favorite").each(function () {
					const $el = $(this);
					if (favorites.includes($el.data('post-id')?.toString())) {
						$el.addClass('favorited');
					}
				});

				// Initial load
				const showLoadMore = favorites.length > perPage;
				$("#load-more-favorites").toggle(showLoadMore).data('page', 1);

				// Subsequent pages
				$("#load-more-favorites").toggle(offset + perPage < favorites.length).data('page', page);
			}
		);
	}

	// Load more click handler
	$(document).on("click", "#load-more-favorites", function () {
		const $btn = $(this);
		const nextPage = parseInt($btn.data("page"), 10) + 1;
		loadFavorites(nextPage);
		$btn.data("page", nextPage);
	});

  	// Property pages show the locally synced base rate immediately. Exact
	// Guesty pricing is requested only after the guest selects dates in the
	// booking flow; no quote request is fired on DOM ready.
	const priceFromParam = $('.priceFromParam .elementor-heading-title');
	const nightFromParam = $('.nightsFromParam .elementor-heading-title');
	const estimateFromParam = $('.estimateFromParam');

	function getCurrencySymbol(currencyCode) {
		const symbols = { USD: '$', EUR: '€', GBP: '£', AUD: 'A$', CAD: 'C$', NZD: 'NZ$', JPY: '¥' };
		return symbols[currencyCode] || currencyCode;
	}

	if (typeof guesty_ajax_search !== 'undefined' && Number(guesty_ajax_search.stored_base_price || 0) > 0) {
		const currencyCode = String(guesty_ajax_search.stored_currency || guesty_ajax_search.default_currency || 'GBP').toUpperCase();
		const symbol = getCurrencySymbol(currencyCode);
		const amount = Number(guesty_ajax_search.stored_base_price).toLocaleString(undefined, {
			minimumFractionDigits: 2,
			maximumFractionDigits: 2
		});
		priceFromParam.text(`${symbol}${amount}`);
		nightFromParam.text('per night');
		estimateFromParam.removeClass('loading').attr('data-price-source', 'synced-meta');
	} else {
		estimateFromParam.removeClass('loading');
	}

	// Initialize Slick for gallery images
	function initSlickGallery(context = document) {
		$(context)
			.find('.property-search-image:not(.slick-initialized)')
			.slick({
				lazyLoad: 'ondemand',
				prevArrow: '<button type="button" class="slick-prev"></button>',
				nextArrow: '<button type="button" class="slick-next"></button>',
			});
	}

	// Close search error popup
	$(document).on('click', '.property-search-errors_closebtn', function () {
		$('.property-search-errors').hide();
	});

	// Handle "Load More" click
	$('#load-more-results').on('click', function () {
		const $btn = $(this);
		const nextPage = parseInt($btn.data('page'), 10) + 1;
		const params = new URLSearchParams(window.location.search);
		let shownIds = $btn.data('shown-ids') || [];

		$('#property-search li').each(function () {
			const id = $(this).data('post-id');
			if (id && !shownIds.includes(id)) {
				shownIds.push(id);
			}
		});

		$btn.data('shown-ids', shownIds);

		const data = {
			action: 'guesty_load_more',
                nonce: guesty_ajax_search.nonce,
			paged: nextPage,
			shown_ids: shownIds,
			title: params.get('title') || '',
			arrival: $btn.data('arrival') || params.get('arrival') || '',
			departure: $btn.data('departure') || params.get('departure') || '',
			guests: params.get('guests') || '',
			bedrooms: params.get('bedrooms') || '',
			bathrooms: params.get('bathrooms') || '',
			orderby: $btn.data('orderby') || '',
			order: $btn.data('order') || 'ASC',
			display: $btn.data('display') || '',
			seed: $btn.data('seed') || '',
			price_min: params.get('price_min') || '',
			price_max: params.get('price_max') || '',
			destination: params.get('destination') || '',
			property_type: params.get('property_type') || '',
			sort: params.get('sort') || '',
			highlights: params.getAll('highlights[]') || [],
			shared_ids: params.get('ids') ? params.get('ids').split(',') : [],
		};

		$btn.text('Loading...').prop('disabled', true);

		$.post(guesty_ajax_search.ajax_url, data, function (res) {
			if (res.html && res.html.indexOf('No properties found') === -1) {
				$('#property-search').append(res.html);
				$btn.data('page', nextPage).text('Load More').prop('disabled', false);
				initSlickGallery();

				// Re-initialize favorites
				$('#property-search').find('.property-favorite').each(function () {
					const $el = $(this);
					const postId = $el.data('post-id')?.toString();
					const favorites = JSON.parse(localStorage.getItem(favoritesKey)) || [];
					if (favorites.includes(postId)) {
						$el.addClass('favorited');
					} else {
						$el.removeClass('favorited');
					}
				});

				var activeInfoWindow = new google.maps.InfoWindow();
				var activeCard = null;

				// ✅ Add new map pins if locations are returned
				if (res.locations && res.locations.length > 0) {
					res.locations.forEach(function (loc) {
						var position = {
							lat: parseFloat(loc.lat),
							lng: parseFloat(loc.lng)
						};
						// console.log(loc);
						// Add marker
						var marker = new google.maps.Marker({
							map: map,
							position: position,
							title: loc.title,
							cardId: loc.cardID
						});

						// Marker click event
						marker.addListener("click", function() {
							// Close previously opened InfoWindow
							activeInfoWindow.close();

							// Set content and open the InfoWindow for the current marker
							activeInfoWindow.setContent(
								'<div class="gmap-window">' +
								'<h3>' + loc.title + '</h3>' +
								'<a href="' + loc.link + '" target="_blank" rel="noopener noreferrer">View More</a>' +
								'</div>'
							);
							activeInfoWindow.open(map, marker);

							// Scroll to the property card
							if (marker.cardId) {
								const el = document.getElementById(marker.cardId);
								if (el) {
									el.scrollIntoView({ behavior: "smooth", block: "start" });

									// Remove 'active' from previous card
									if (activeCard) activeCard.classList.remove("active");

									// Add 'active' to current card
									el.classList.add("active");
									activeCard = el; // update activeCard
								}
							}
						});

						// Extend bounds to include this marker
						bounds.extend(position);
					});

					// Re-fit map to include all markers
					map.fitBounds(bounds);

					// Update global locations array
					mapLocations = mapLocations.concat(res.locations);
				}

				if (!res.has_more) {
					$btn.hide();
				}
			} else {
				$btn.hide();
			}
		});
	});



	// Initialize Slick for initial set
	initSlickGallery();

	// Search Form
	$('#property-search-form').on('submit', function() {
		$(this).find('input[name]').each(function() {
			const type = $(this).attr('type');
			const value = $(this).val();

			// Remove name if value is empty string or (optional) "0"
			if ((value === '' || value === null || value === undefined || value === '0') && type !== 'checkbox') {
				$(this).removeAttr('name');
			}

			// Remove unchecked checkboxes
			if (type === 'checkbox' && !$(this).is(':checked')) {
				$(this).removeAttr('name');
			}
		});

		$(this).find('select[name]').each(function() {
			if (!$(this).val()) {
				$(this).removeAttr('name');
			}
		});
	});


	// Show/Hide Advance Filters
	function toggleAdvanceFilters(show) {
		var $parent = $('.advance-filter');
		
		if (show) {
			$parent.addClass('active');
			$('.advance-fields').slideDown();
		} else {
			$parent.removeClass('active');
			$('.advance-fields').slideUp();
		}
	}

	$('.advance-filter').on('click', function(e) {
		e.preventDefault();
		var $parent = $(this).closest('.advance-filter');
    	toggleAdvanceFilters(!$parent.hasClass('active'));
    });
	$('.advance-fields-close').on('click', function(e) {
		e.preventDefault();
    	toggleAdvanceFilters(false);
    });

	document.querySelectorAll('.stepper').forEach(stepper => {

		const display = stepper.querySelector('.display');
		const hidden = stepper.querySelector('.stepper-value');
		const minus = stepper.querySelector('.minus');
		const plus = stepper.querySelector('.plus');

		// ⭐ get max from HTML
		const max = parseInt(stepper.dataset.max || 5);

		// build values dynamically
		let values = [];
		for(let i=1; i<=max; i++){
			values.push(String(i));
		}

		function getCurrentIndex(){
			let val = hidden.value;
			return values.indexOf(val);
		}

		function setValue(index){
			if(index < 0) index = -1;
			if(index > values.length-1) index = values.length-1;

			// blank state
			if(index === -1){
				hidden.value = '';
				display.value = '';
				minus.disabled = true;
				plus.disabled = false;
				return;
			}

			let val = values[index];
			hidden.value = val;
			display.value = val;

			minus.disabled = false;
			plus.disabled = (index === values.length-1);
		}

		// init
		let startIndex = getCurrentIndex();
		if(startIndex === -1){
			setValue(-1);
		}else{
			setValue(startIndex);
		}

		minus.addEventListener('click', ()=>{
			setValue(getCurrentIndex()-1);
		});

		plus.addEventListener('click', ()=>{
			let i = getCurrentIndex();
			if(i === -1) i = 0;
			else i++;
			setValue(i);
		});
	});

	
	const priceSlider = document.getElementById("price_range_slider");
	if (priceSlider) {
		const minInput = document.getElementById("price_min");
		const maxInput = document.getElementById("price_max");
		const minInputdisplay = document.getElementById("price_range_display_min");
		const maxInputdisplay = document.getElementById("price_range_display_max");

		// Default slider values
		const defaultMin = 0;
		const defaultMax = 10000;

		// Get initial values from inputs (or use defaults)
		let minVal = parseInt(minInput.value) || defaultMin;
		let maxVal = parseInt(maxInput.value) || defaultMax;

		// Init slider
		noUiSlider.create(priceSlider, {
			start: [minVal, maxVal],
			connect: true,
			step: 10,
			range: {
				min: defaultMin,
				max: defaultMax
			},
			format: {
				to: value => Math.round(value),
				from: value => Number(value)
			}
		});

		let sliderChanged = false;

		priceSlider.noUiSlider.on("update", function(values) {
			const min = values[0];
			const max = values[1];

			minInput.value = min;
			maxInput.value = max;

			minInputdisplay.textContent = `£${Number(min).toLocaleString("en-UK", { minimumFractionDigits: 2 })}`;
			maxInputdisplay.textContent = `£${Number(max).toLocaleString("en-UK", { minimumFractionDigits: 2 })}`;

			sliderChanged = true;
		});

		// Disable price inputs if slider untouched or at defaults
		document.querySelector("#property-search-form").addEventListener("submit", function() {
			const [curMin, curMax] = priceSlider.noUiSlider.get().map(Number);
			if (!sliderChanged || (curMin === defaultMin && curMax === defaultMax)) {
				minInput.disabled = true;
				maxInput.disabled = true;
			}
		});

		// Set initial display on load
		minInputdisplay.textContent = `£${Number(minVal).toLocaleString("en-UK", { minimumFractionDigits: 2 })}`;
		maxInputdisplay.textContent = `£${Number(maxVal).toLocaleString("en-UK", { minimumFractionDigits: 2 })}`;

		// Clear All
		$("#clear-all-filters").on("click", function(e) {
			e.preventDefault();

			// Reset form inputs
			$("#property-search-form")
				.find("input[type=text], input[type=number], input[type=date], input[type=hidden]")
				.val("");

			$("#property-search-form")
				.find("input[type=radio], input[type=checkbox]")
				.prop("checked", false);

			$("#property-search-form select").prop('selectedIndex', 0);

			// reset steppers to blank
			document.querySelectorAll('.stepper').forEach(stepper => {
				const display = stepper.querySelector('.display');
				const hidden = stepper.querySelector('.stepper-value');
				const minus = stepper.querySelector('.minus');
				const plus = stepper.querySelector('.plus');

				hidden.value = '';
				display.value = '';

				minus.disabled = true;
				plus.disabled = false;
			});

			// Reset slider + disable inputs
			priceSlider.noUiSlider.set([defaultMin, defaultMax]);
			$("#price_min, #price_max").val("").prop("disabled", false);

			$("#price_range_display_min").text(`£${Number(defaultMin).toLocaleString("en-UK", { minimumFractionDigits: 2 })}`);
			$("#price_range_display_max").text(`£${Number(defaultMax).toLocaleString("en-UK", { minimumFractionDigits: 2 })}`);

			sliderChanged = false;
		});
	}

	// Google Maps
	if (typeof mapLocations !== "undefined" && mapLocations.length > 0 && typeof google !== 'undefined' && document.getElementById('property-map')) {

	var map = new google.maps.Map(document.getElementById("property-map"), {
		// zoom: 16,
		center: { lat: parseFloat(mapLocations[0].lat), lng: parseFloat(mapLocations[0].lng) }
	});

	var bounds = new google.maps.LatLngBounds();
	var activeInfoWindow = new google.maps.InfoWindow();
	var activeCard = null;

	$.each(mapLocations, function(index, loc) {
		var position = { lat: parseFloat(loc.lat), lng: parseFloat(loc.lng) };

		// Create marker
		var marker = new google.maps.Marker({
			position: position,
			map: map,
			title: loc.title,
			cardId: loc.cardID // make sure your mapLocations has cardID
		});

		// Marker click event
		marker.addListener("click", function() {
			// Close previously opened InfoWindow
			activeInfoWindow.close();

			// Set content and open the InfoWindow for the current marker
			activeInfoWindow.setContent(
				'<div class="gmap-window">' +
				'<h3>' + loc.title + '</h3>' +
				'<a href="' + loc.link + '" target="_blank" rel="noopener noreferrer">View More</a>' +
				'</div>'
			);
			activeInfoWindow.open(map, marker);

			// Scroll to the property card
			if (marker.cardId) {
				const el = document.getElementById(marker.cardId);
				if (el) {
					el.scrollIntoView({ behavior: "smooth", block: "start" });

					// Remove 'active' from previous card
					if (activeCard) activeCard.classList.remove("active");

					// Add 'active' to current card
					el.classList.add("active");
					activeCard = el; // update activeCard
				}
			}
		});

		// Extend map bounds to include marker
		bounds.extend(position);
	});

	map.fitBounds(bounds);

	// Google Map Toggle
	var $toggle = $("#mapToggle");
	var $label = $(".toggle-label");

	$toggle.on("change", function () {
		if ($(this).is(":checked")) {
			$label.text("Hide Map");
			$('.search-result-with-map').addClass('show-map');
		} else {
			$label.text("View Map");
			$('.search-result-with-map').removeClass('show-map');
		}
	});
	}

});

// Flatpickr initialization for arrival and departure
document.addEventListener('DOMContentLoaded', function () {
	const arrivalInput = document.getElementById('arrival');
	const departureInput = document.getElementById('departure');

	if (!arrivalInput || !departureInput || typeof flatpickr === 'undefined') {
		return;
	}

	const departurePicker = flatpickr(departureInput, {
		minDate: 'today',
		dateFormat: 'Y-m-d',
		altInput: true,
		altFormat: 'j F, Y',
		monthSelectorType: 'static',
		disableMobile: true,
	});

	flatpickr(arrivalInput, {
		minDate: 'today',
		dateFormat: 'Y-m-d',
		altInput: true,
		altFormat: 'j F, Y',
		monthSelectorType: 'static',
		disableMobile: true,
		onChange: function (selectedDates) {
		if (selectedDates.length > 0) {
			const arrivalDate = selectedDates[0];
			const nextDay = new Date(arrivalDate);
			nextDay.setDate(nextDay.getDate() + 1);
			departurePicker.set('minDate', nextDay);

			const selectedDeparture = departurePicker.selectedDates[0];
			if (selectedDeparture && selectedDeparture < nextDay) {
			departureInput.value = '';
			departurePicker.clear();
			}
		}
		},
	});
});
