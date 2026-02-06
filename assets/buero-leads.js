jQuery(document).ready(function($) {
    // Handle pageviewc count button click
    const beaconAvailable = typeof navigator !== "undefined" && typeof navigator.sendBeacon === "function";
    let leadid = new URLSearchParams(window.location.search).get("id");
    let pageid = $(".data_id_buero").first().text();
    let targetURLs = $('#buero-link-targets')
    if (!targetURLs) {
        targetURLs = {
            "link1": "#",
            "link2": "#",
            "link3": "#",
            "menu-class": "#"
        }
    }else {
        targetURLs = Object.fromEntries(targetURLs.children('span').get().map(s => [s.className, s.textContent.trim()])) 
    }

    if (leadid) {


        const body = new URLSearchParams({
            lead_id: String(leadid),
            page_id: String(pageid),
            column: String("page_view"),
            nonce: String(bueroLeads.nonce)
        });

        if (beaconAvailable) {
            navigator.sendBeacon(bueroLeads.ajaxUrl, body);
        }else {
            $.ajax({
                url: bueroLeads.ajaxUrl,
                type: 'POST',
                data: {
                    lead_id: leadid,
                    page_id: pageid,
                    column: "page_view",
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bueroLeads.nonce);
                },
                success: function(response) {
                    console.log("success")
                },
                error: function(xhr, status, error) {
                    console.error('Error tracking:', error);
                }
            });
        }
        
    }

    let [menu_class, target] = (targetURLs["menu-class"] ?? "").split("|")
    
    $(document).on('click', '.bb-counter-button' +(menu_class ? ', .'+menu_class : ''), function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var leadId = new URLSearchParams(window.location.search).get("id");
        let column;
        

        if ($button.attr('class').includes(menu_class)){
            column = target
        }else{
            column = $button.attr('id');
        }
        
        // Get target URL from data attribute
        let targetURL = targetURLs[column]
        
        if (!targetURL) {
            targetURL = '#';
        }

        // Disable button and show loading state
        $button.prop('disabled', true);
        // $btnLoading.show();
        
        // If no lead ID, just redirect to CV
        if (!leadId || leadId === '') {
            window.location.href = targetURL;
            return;
        }
        const body = new URLSearchParams({
            lead_id: String(leadId),
            page_id: String(pageid),
            column: String(column),
            nonce: String(bueroLeads.nonce)
        });

        if  (beaconAvailable) {
            navigator.sendBeacon(bueroLeads.ajaxUrl, body);
            window.location.href = targetURL;
        }else {
            $.ajax({
                url: bueroLeads.ajaxUrl,
                type: 'POST',
                data: {
                    lead_id: leadId,
                    page_id: pageid,
                    column: column
                },
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', bueroLeads.nonce);
                },
                success: function(response) {
                    console.log("success")
                    window.location.href = targetURL;
                },
                error: function(xhr, status, error) {
                    console.error('Error tracking:', error);
                    window.location.href = targetURL;
                }
            });
        }
        



    });
});

