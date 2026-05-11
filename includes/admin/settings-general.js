jQuery(document).ready(function($) {
    // Check wallet status and show warning if not connected
    async function checkGeneralSettingsWalletStatus() {
        try {
            // Wait for ContentCloud to be initialized
            var attempts = 0;
            while (attempts < 10 && (!window.ContentCloud || !window.ContentCloud.permaweb)) {
                await new Promise(function(resolve) { setTimeout(resolve, 200); });
                attempts++;
            }

            if (!window.ContentCloud || !window.ContentCloud.permaweb) {
                $('#clkt-general-wallet-warning').show();
                return;
            }

            // Initialize if needed
            if (!window.ContentCloud.permaweb.status.initialized) {
                await window.ContentCloud.permaweb.initialize();
            }

            var walletInfo = await window.ContentCloud.permaweb.getWalletInfo();
            if (!walletInfo.connected) {
                $('#clkt-general-wallet-warning').show();
            }
        } catch (error) {
            console.error('Error checking wallet status:', error);
            $('#clkt-general-wallet-warning').show();
        }
    }

    checkGeneralSettingsWalletStatus();
});
