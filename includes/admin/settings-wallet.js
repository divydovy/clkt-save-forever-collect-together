jQuery(document).ready(function($) {
    var walletConnected = false;
    var walletProvider = (window.clkt_ajax_obj && window.clkt_ajax_obj.wallet_provider) || 'evm';
    var isEvmWallet = walletProvider === 'evm';

    function text(value) {
        return typeof value === 'string' ? value.trim() : '';
    }

    function randomId() {
        if (window.crypto && window.crypto.randomUUID) {
            return window.crypto.randomUUID();
        }

        return String(Date.now()) + '-' + Math.random().toString(16).slice(2);
    }

    function utf8ToHex(value) {
        return '0x' + Array.from(new TextEncoder().encode(value))
            .map(function(byte) { return byte.toString(16).padStart(2, '0'); })
            .join('');
    }

    function bytesToBase64Url(value) {
        var bytes = value instanceof Uint8Array ? value : new Uint8Array(value);
        var binary = Array.from(bytes)
            .map(function(byte) { return String.fromCharCode(byte); })
            .join('');

        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
    }

    function buildIdentityLinkMessage(evmAddress, arweaveAddress, issuedAt, nonce) {
        return JSON.stringify({
            schema: 'clkt-identity-link-v1',
            schemaVersion: '1.0.0',
            action: 'link-wallets',
            handle: '',
            wallets: [
                { namespace: 'eip155:8453', address: text(evmAddress).toLowerCase() },
                { namespace: 'arweave', address: text(arweaveAddress) }
            ],
            nonce: nonce,
            issuedAt: issuedAt
        });
    }

    function buildIdentityLinkRecord(evmAddress, arweaveAddress, message, issuedAt, nonce, proofs) {
        return {
            schema: 'clkt-identity-link-v1',
            schemaVersion: '1.0.0',
            createdAt: new Date().toISOString(),
            identity: {
                handle: ''
            },
            wallets: [
                { namespace: 'eip155:8453', address: text(evmAddress).toLowerCase() },
                { namespace: 'arweave', address: text(arweaveAddress) }
            ],
            statement: {
                action: 'link-wallets',
                message: message,
                nonce: nonce,
                issuedAt: issuedAt
            },
            proofs: proofs
        };
    }

    function buildIdentityLinkTags(record) {
        var evm = record.wallets.find(function(item) { return item.namespace === 'eip155:8453'; });
        var arweave = record.wallets.find(function(item) { return item.namespace === 'arweave'; });

        return [
            { name: 'Content-Type', value: 'application/json' },
            { name: 'Type', value: 'Identity-Link' },
            { name: 'App-Name', value: 'CLKT' },
            { name: 'CLKT-Record-Schema', value: 'clkt-identity-link-v1' },
            { name: 'CLKT-Record-Version', value: '1' },
            { name: 'Wallet-EVM', value: evm ? evm.address : '' },
            { name: 'Wallet-Arweave', value: arweave ? arweave.address : '' },
            { name: 'Identity-Handle', value: '' },
            { name: 'Payment-Protocol', value: 'x402' },
            { name: 'Payment-Token', value: 'base-usdc' }
        ];
    }

    async function signArweaveIdentityLink(arweaveWallet, arweaveAddress, message) {
        var signature = await arweaveWallet.signature(new TextEncoder().encode(message), {
            name: 'RSA-PSS',
            saltLength: 32
        });

        return {
            namespace: 'arweave',
            address: arweaveAddress,
            scheme: 'arweave-signature',
            signature: typeof signature === 'string' ? signature : bytesToBase64Url(signature),
            signedAt: new Date().toISOString()
        };
    }

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
            $('#wallet-balance').text(isEvmWallet ? 'Base USDC via wallet approval' : (balance ? balance + ' AR' : 'Loading...'));
            $('#connect-wallet-btn').hide();
            $('#disconnect-wallet-btn').show();
            $('#refresh-balance-btn').toggle(!isEvmWallet);

            if (address) {
                var permanentViewerUrl = isEvmWallet
                    ? 'https://app.getclkt.com/collect#collector=' + encodeURIComponent(address)
                    : 'https://2deruxhrgmrp2browgc472rhocjc7xjbkrpac7bmg6psfq26ue7q.arweave.net/0MkaXPEzIv0GLrGFz-oncJIv3SFUXgF8LDefIsNeoT8?wallet=' + encodeURIComponent(address);
                $('#permanent-viewer-link').attr('href', permanentViewerUrl).text(permanentViewerUrl);

                var arscanUrl = isEvmWallet ? 'https://basescan.org/address/' + address : 'https://arscan.io/address/' + address;
                $('#arscan-link').attr('href', arscanUrl).text(arscanUrl);

                var viewblockUrl = isEvmWallet ? 'https://basescan.org/address/' + address : 'https://viewblock.io/arweave/address/' + address;
                $('#viewblock-link').attr('href', viewblockUrl).text(viewblockUrl);

                var goldskyUrl = 'https://arweave-search.goldsky.com/graphql';
                $('#goldsky-gql-link').attr('href', goldskyUrl).text(goldskyUrl);

                var gqlQuery = isEvmWallet
                    ? 'query {\n  transactions(\n    tags: [\n      { name: "App-Name", values: ["CLKT"] },\n      { name: "Collector", values: ["' + address + '"] }\n    ]\n    first: 100\n    sort: HEIGHT_DESC\n  ) {\n    edges {\n      node {\n        id\n        tags {\n          name\n          value\n        }\n        block {\n          timestamp\n        }\n      }\n    }\n  }\n}'
                    : 'query {\n  transactions(\n    owners: ["' + address + '"]\n    tags: [\n      { name: "App-Name", values: ["ContentCloud"] }\n    ]\n    first: 100\n    sort: HEIGHT_DESC\n  ) {\n    edges {\n      node {\n        id\n        tags {\n          name\n          value\n        }\n        block {\n          timestamp\n        }\n      }\n    }\n  }\n}';
                $('#gql-query-display').text(gqlQuery);

                console.log('GraphQL query to find CLKT uploads including bundled items:');
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
                showMessage('Failed to initialize wallet manager. Please ensure the selected browser wallet is installed.', 'error');
                $button.prop('disabled', false).text('Connect Wallet');
                return;
            }

            if (!isEvmWallet && !window.arweaveWallet) {
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
                $('#wallet-balance').text(isEvmWallet ? 'Base USDC via wallet approval' : (walletInfo.balance ? walletInfo.balance + ' AR' : 'Loading...'));
                showMessage('Balance updated', 'success');
            }
        } catch (error) {
            console.error('Error refreshing balance:', error);
            showMessage('Failed to refresh balance', 'error');
        } finally {
            $button.prop('disabled', false).text('Refresh Balance');
        }
    });

    $('#clkt-link-wallets-btn').on('click', async function() {
        var $button = $(this);
        var $result = $('#clkt-identity-link-result');

        $button.prop('disabled', true).text('Linking wallets...');
        $result.removeAttr('style').css({ marginTop: '12px', display: 'none' }).empty();

        try {
            var initialized = await initializeCLKT();

            if (!initialized || !window.ContentCloud.permaweb) {
                throw new Error('Failed to initialize CLKT wallet manager.');
            }

            if (!isEvmWallet) {
                throw new Error('Switch Wallet Provider to EVM wallet (Base USDC/x402) before linking wallets.');
            }

            var walletInfo = await window.ContentCloud.permaweb.getWalletInfo();
            var walletStatus = window.ContentCloud.permaweb.walletManager.getStatus();

            if (!walletInfo.connected || !walletStatus.wallet) {
                throw new Error('Connect your EVM wallet before linking Wander.');
            }

            if (!window.arweaveWallet) {
                throw new Error('Wander wallet not detected. Install or unlock Wander, then refresh this page.');
            }

            await window.arweaveWallet.connect(['ACCESS_ADDRESS', 'SIGNATURE']);
            var arweaveAddress = await window.arweaveWallet.getActiveAddress();
            var issuedAt = new Date().toISOString();
            var nonce = randomId();
            var message = buildIdentityLinkMessage(walletInfo.address, arweaveAddress, issuedAt, nonce);

            showMessage('Sign the identity link with your EVM wallet.', 'info');
            var evmSignature = await walletStatus.wallet.request({
                method: 'personal_sign',
                params: [utf8ToHex(message), walletInfo.address]
            });
            var evmProof = {
                namespace: 'eip155:8453',
                address: text(walletInfo.address).toLowerCase(),
                scheme: 'eip191',
                signature: evmSignature,
                signedAt: new Date().toISOString()
            };

            showMessage('Sign the identity link with your Wander wallet.', 'info');
            var arweaveProof = await signArweaveIdentityLink(window.arweaveWallet, arweaveAddress, message);
            var record = buildIdentityLinkRecord(walletInfo.address, arweaveAddress, message, issuedAt, nonce, [evmProof, arweaveProof]);
            var recordJson = JSON.stringify(record);
            var upload = await window.ContentCloud.permaweb.uploadWithEvmX402({
                bytes: recordJson,
                contentType: 'application/json',
                tags: buildIdentityLinkTags(record),
                title: 'CLKT identity link',
                sizeInKB: new Blob([recordJson]).size / 1024
            });
            var txId = upload.receipt.id;

            var saveResponse = await $.post(window.clkt_ajax_obj.ajax_url, {
                action: 'clkt_save_identity_link',
                nonce: window.clkt_ajax_obj.nonce,
                tx_id: txId,
                evm_address: walletInfo.address,
                arweave_address: arweaveAddress,
                record: recordJson
            });

            if (!saveResponse || !saveResponse.success) {
                throw new Error(saveResponse && saveResponse.data ? saveResponse.data : 'Failed to save identity link in WordPress.');
            }

            $('#clkt-identity-link-current').html(
                '<strong>Current identity link:</strong> <a href="https://arweave.net/' + txId + '" target="_blank" style="word-break: break-all;">' + txId + '</a>'
            );
            $result
                .css({
                    background: '#d4f4dd',
                    border: '1px solid #00703c',
                    borderRadius: '4px',
                    color: '#00703c',
                    padding: '10px'
                })
                .html('Wallets linked. Identity link TX: <a href="https://arweave.net/' + txId + '" target="_blank">' + txId + '</a>')
                .show();
            showMessage('Wallet alias created successfully.', 'success');
        } catch (error) {
            console.error('Error linking wallets:', error);
            $result
                .css({
                    background: '#fddede',
                    border: '1px solid #a00',
                    borderRadius: '4px',
                    color: '#a00',
                    padding: '10px'
                })
                .text('Failed to link wallets: ' + (error.message || error))
                .show();
        } finally {
            $button.prop('disabled', false).text('Link EVM + Wander Wallets');
        }
    });

    // Check status on page load
    checkWalletStatus();
});
