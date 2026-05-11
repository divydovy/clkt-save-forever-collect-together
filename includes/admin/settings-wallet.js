jQuery(document).ready(function($) {
    var walletConnected = false;

    // Initialize CLKT if needed
    async function initializeCLKT() {
        if (!window.ContentCloud.permaweb) {
            console.log('CLKT not loaded on settings page');
            return false;
        }

        try {
            if (!window.ContentCloud.permaweb.status.initialized) {
                await window.ContentCloud.permaweb.initialize();
            }
            return true;
        } catch (error) {
            console.error('Failed to initialize CLKT:', error);
            return false;
        }
    }

    // Update wallet status display
    function updateWalletStatus(connected, address, balance) {
        walletConnected = connected;

        if (connected) {
            $('#status-indicator').removeClass('disconnected').addClass('connected');
            $('#wallet-status-text').text('Connected');
            $('#wallet-address').text(address || 'Loading...');
            $('#wallet-balance').text(balance ? balance + ' AR' : 'Loading...');
            $('#connect-wallet-btn').hide();
            $('#disconnect-wallet-btn, #refresh-balance-btn').show();

            if (address) {
                var permanentViewerUrl = 'https://2deruxhrgmrp2browgc472rhocjc7xjbkrpac7bmg6psfq26ue7q.arweave.net/0MkaXPEzIv0GLrGFz-oncJIv3SFUXgF8LDefIsNeoT8?wallet=' + encodeURIComponent(address);
                $('#permanent-viewer-link').attr('href', permanentViewerUrl).text(permanentViewerUrl);

                var arscanUrl = 'https://arscan.io/address/' + address;
                $('#arscan-link').attr('href', arscanUrl).text(arscanUrl);

                var viewblockUrl = 'https://viewblock.io/arweave/address/' + address;
                $('#viewblock-link').attr('href', viewblockUrl).text(viewblockUrl);

                var goldskyUrl = 'https://arweave-search.goldsky.com/graphql';
                $('#goldsky-gql-link').attr('href', goldskyUrl).text(goldskyUrl);

                var gqlQuery = 'query {\n  transactions(\n    owners: ["' + address + '"]\n    tags: [\n      { name: "App-Name", values: ["ContentCloud"] }\n    ]\n    first: 100\n    sort: HEIGHT_DESC\n  ) {\n    edges {\n      node {\n        id\n        tags {\n          name\n          value\n        }\n        block {\n          timestamp\n        }\n      }\n    }\n  }\n}';
                $('#gql-query-display').text(gqlQuery);

                console.log('GraphQL Query to find all ContentCloud uploads (including bundled items):');
                console.log(gqlQuery);

                $('#copy-gql-btn').off('click').on('click', function() {
                    navigator.clipboard.writeText(gqlQuery).then(function() {
                        var $btn = $(this);
                        var originalText = $btn.text();
                        $btn.text('Copied!');
                        setTimeout(function() { $btn.text(originalText); }, 2000);
                    }.bind(this)).catch(function() {
                        alert('Failed to copy. Please select and copy the query manually.');
                    });
                });

                $('#content-collection-url').show();
            }
        } else {
            $('#status-indicator').removeClass('connected').addClass('disconnected');
            $('#wallet-status-text').text('Not Connected');
            $('#wallet-address').text('Not connected');
            $('#wallet-balance').text('-');
            $('#connect-wallet-btn').show();
            $('#disconnect-wallet-btn, #refresh-balance-btn').hide();
            $('#content-collection-url').hide();
        }
    }

    // Show message
    function showMessage(message, type) {
        type = type || 'info';
        var $messageDiv = $('#wallet-message');
        var bgColor = type === 'error' ? '#fddede' : type === 'success' ? '#d4f4dd' : '#f0f8ff';
        var textColor = type === 'error' ? '#a00' : type === 'success' ? '#00703c' : '#007cba';

        $messageDiv
            .css({
                'background': bgColor,
                'color': textColor,
                'padding': '10px',
                'border-radius': '4px',
                'border': '1px solid ' + textColor
            })
            .html(message)
            .show();

        if (type === 'success') {
            setTimeout(function() { $messageDiv.fadeOut(); }, 5000);
        }
    }

    // Check initial wallet status
    async function checkWalletStatus() {
        if (!window.ContentCloud || !window.ContentCloud.permaweb) {
            var attempts = 0;
            var maxAttempts = 10;

            while (attempts < maxAttempts && (!window.ContentCloud || !window.ContentCloud.permaweb)) {
                await new Promise(function(resolve) { setTimeout(resolve, 200); });
                attempts++;
            }

            if (!window.ContentCloud || !window.ContentCloud.permaweb) {
                updateWalletStatus(false);
                return;
            }
        }

        try {
            var initialized = await initializeCLKT();

            if (!initialized) {
                updateWalletStatus(false);
                return;
            }

            if (window.ContentCloud.permaweb && window.ContentCloud.permaweb.walletManager) {
                var wm = window.ContentCloud.permaweb.walletManager;
                if (wm.wallet && wm.wallet.getActiveAddress) {
                    try {
                        await wm.wallet.getActiveAddress();
                    } catch (e) {
                        // Ignore error
                    }
                }
            }

            var walletInfo = await window.ContentCloud.permaweb.getWalletInfo();
            updateWalletStatus(walletInfo.connected, walletInfo.address, walletInfo.balance);
        } catch (error) {
            console.error('Error checking wallet status:', error);
            updateWalletStatus(false);
        }
    }

    // Connect wallet
    $('#connect-wallet-btn').on('click', async function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Connecting...');

        try {
            var initialized = await initializeCLKT();

            if (!initialized) {
                showMessage('Failed to initialize wallet manager. Please ensure Wander wallet is installed.', 'error');
                $button.prop('disabled', false).text('Connect Wallet');
                return;
            }

            if (!window.arweaveWallet) {
                showMessage(
                    'Wander wallet not detected. Please install it from <a href="https://wander.app" target="_blank">wander.app</a> and refresh this page.',
                    'error'
                );
                $button.prop('disabled', false).text('Connect Wallet');
                return;
            }

            var connected = await window.ContentCloud.permaweb.connectWallet();

            if (connected) {
                var walletInfo = await window.ContentCloud.permaweb.getWalletInfo();
                updateWalletStatus(true, walletInfo.address, walletInfo.balance);
                showMessage('Wallet connected successfully!', 'success');
            }
        } catch (error) {
            console.error('Error connecting wallet:', error);
            showMessage('Failed to connect wallet: ' + error.message, 'error');
        } finally {
            $button.prop('disabled', false).text('Connect Wallet');
        }
    });

    // Disconnect wallet
    $('#disconnect-wallet-btn').on('click', async function() {
        if (!confirm('Are you sure you want to disconnect your wallet?')) {
            return;
        }

        var $button = $(this);
        $button.prop('disabled', true).text('Disconnecting...');

        try {
            await window.ContentCloud.permaweb.disconnectWallet();
            updateWalletStatus(false);
            showMessage('Wallet disconnected', 'success');
        } catch (error) {
            console.error('Error disconnecting wallet:', error);
            showMessage('Failed to disconnect wallet: ' + error.message, 'error');
        } finally {
            $button.prop('disabled', false).text('Disconnect');
        }
    });

    // Refresh balance
    $('#refresh-balance-btn').on('click', async function() {
        var $button = $(this);
        $button.prop('disabled', true).text('Refreshing...');

        try {
            var walletInfo = await window.ContentCloud.permaweb.getWalletInfo();
            if (walletInfo.connected) {
                $('#wallet-balance').text(walletInfo.balance ? walletInfo.balance + ' AR' : 'Loading...');
                showMessage('Balance updated', 'success');
            }
        } catch (error) {
            console.error('Error refreshing balance:', error);
            showMessage('Failed to refresh balance', 'error');
        } finally {
            $button.prop('disabled', false).text('Refresh Balance');
        }
    });

    // Check status on page load
    checkWalletStatus();
});
