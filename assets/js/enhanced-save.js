jQuery(document).ready(function ($) {
    // Check wallet status on load
    clktCheckWalletStatus();

    // Update wallet status when checkbox changes
    $('#clkt_publish_and_upload').change(function () {
        if ($(this).is(':checked')) {
            clktCheckWalletStatus();
        }
    });

    // Hook into WordPress save actions
    var originalPublishFunction = $('#publish').attr('onclick');
    $('#publish, #save-post').on('click', function (e) {
        if ($('#clkt_publish_and_upload').is(':checked')) {
            // If auto-save to permaweb is checked, handle the save differently
            handleEnhancedSave(e, this);
        }
    });

    function clktCheckWalletStatus() {
        var dot = $('#clkt-wallet-dot');
        var text = $('#clkt-wallet-text');

        if (typeof window.arweaveWallet !== 'undefined') {
            // Check if ArConnect is available
            window.arweaveWallet.getActiveAddress()
                .then(function(address) {
                    if (address) {
                        dot.removeClass('disconnected').addClass('connected');
                        text.text('Wallet connected (' + address.substring(0, 8) + '...)');
                    } else {
                        dot.removeClass('connected').addClass('disconnected');
                        text.text('Wallet not connected');
                    }
                })
                .catch(function() {
                    dot.removeClass('connected').addClass('disconnected');
                    text.text('Wallet not available');
                });
        } else {
            dot.removeClass('connected').addClass('disconnected');
            text.text('ArConnect not detected');
        }
    }

    function handleEnhancedSave(event, button) {
        var isPublish = $(button).attr('id') === 'publish';
        var isAutoUpload = $('#clkt_publish_and_upload').is(':checked');

        if (!isAutoUpload) {
            return; // Let normal save proceed
        }

        // Show user that we're handling both save and upload
        var originalText = $(button).val();
        $(button).val(isPublish ? 'Publishing & Saving to Permaweb...' : 'Saving & Uploading...');
        $(button).prop('disabled', true);

        // Let the normal save complete first, then trigger upload
        setTimeout(function() {
            var postId = $('#post_ID').val();
            if (postId) {
                triggerPermawemSave(postId, function() {
                    $(button).val(originalText);
                    $(button).prop('disabled', false);
                });
            }
        }, 1000);
    }

    function triggerPermawemSave(postId, callback) {
        // Use existing CLKT upload functionality
        if (typeof clktPermaweb !== 'undefined' && clktPermaweb.sendToPermaweb) {
            clktPermaweb.sendToPermaweb(postId)
                .then(function() {
                    // Success notification
                    $('<div class="notice notice-success is-dismissible"><p><strong>CLKT:</strong> Post saved to Permaweb successfully!</p></div>')
                        .insertAfter('#wpbody-content h1');
                })
                .catch(function(error) {
                    // Error notification
                    $('<div class="notice notice-error is-dismissible"><p><strong>CLKT:</strong> Failed to save to Permaweb: ' + error.message + '</p></div>')
                        .insertAfter('#wpbody-content h1');
                })
                .finally(callback);
        } else {
            callback();
        }
    }
});
