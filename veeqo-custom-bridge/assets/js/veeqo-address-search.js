jQuery(document).ready(function($) {
    // Variable to hold our debounce timer
    let searchTimeout;

    // Expose the function globally so Google's URL callback can trigger it
    window.initVeeqoHeadlessSearch = async function() {
        console.log("Veeqo: Initializing Modern Headless Address Search via Callback...");
        setupField('billing');
        setupField('shipping');
    };

    function setupField(type) {
        var $input = $('#' + type + '_address_1');
        
        if ($input.length === 0 || $input.data('vq-bound')) return;
        $input.data('vq-bound', true);
        
        $input.attr('autocomplete', 'new-password'); 

        var $list = $('<ul class="vq-pac-dropdown"></ul>');
        $input.after($list);

        $input.on('input', function() {
            // Clear the previous timer every time they hit a new key
            clearTimeout(searchTimeout);
            var val = $(this).val();
            
            if (val.length < 3) {
                $list.hide().empty();
                return;
            }

            // Start a 300ms timer before making the expensive API call
            searchTimeout = setTimeout(async function() {
                try {
                    // Grab the current country from WooCommerce
                    var currentCountry = $('#' + type + '_country').val();
                    var requestConfig = { input: val };
                    
                    // Restrict Google's search to that specific country
                    if (currentCountry) {
                        requestConfig.includedRegionCodes = [currentCountry];
                    }

                    const response = await google.maps.places.AutocompleteSuggestion.fetchAutocompleteSuggestions(requestConfig);

                    $list.empty();

                    if (response && response.suggestions) {
                        $.each(response.suggestions, function(i, suggestion) {
                            if (!suggestion.placePrediction) return;
                            
                            var description = suggestion.placePrediction.text.text;
                            var placeId = suggestion.placePrediction.placeId;
                            
                            // Security fix: Safely inject text as text, not HTML
                            var $li = $('<li>').text(description);
                            
                            $li.on('click', function() {
                                $input.val(description);
                                $list.hide().empty();
                                getPlaceDetails(placeId, type);
                            });
                            $list.append($li);
                        });
                        $list.show();
                    } else {
                        $list.hide();
                    }
                } catch (e) {
                    console.error("Veeqo Search Error:", e);
                    $list.hide();
                }
            }, 300); // 300ms delay
        });

        $input.on('blur', function() {
            setTimeout(function() { $list.hide(); }, 200);
        });
    }

    async function getPlaceDetails(placeId, type) {
        try {
            const place = new google.maps.places.Place({ id: placeId });
            await place.fetchFields({ fields: ['addressComponents'] });

            var data = { street_number: '', route: '', locality: '', administrative_area_level_1: '', postal_code: '', country: '' };
            
            if (place.addressComponents) {
                $.each(place.addressComponents, function(i, comp) {
                    var typeName = comp.types[0];
                    if (data.hasOwnProperty(typeName)) {
                        data[typeName] = comp.shortText;
                    }
                });
            }

            var street = (data.street_number + ' ' + data.route).trim();
            
            if (street) $('#' + type + '_address_1').val(street).trigger('change');
            $('#' + type + '_city').val(data.locality).trigger('change');
            $('#' + type + '_state').val(data.administrative_area_level_1).trigger('change');
            $('#' + type + '_postcode').val(data.postal_code).trigger('change');
            $('#' + type + '_country').val(data.country).trigger('change');

        } catch (e) {
            console.error("Veeqo Details Error:", e);
        }
    }

    // Re-bind safely whenever WooCommerce updates the checkout blocks
    $(document.body).on('updated_checkout updated_wc_div', function() {
        if (typeof google !== 'undefined' && google.maps && google.maps.places) {
            setupField('billing');
            setupField('shipping');
        }
    });
});