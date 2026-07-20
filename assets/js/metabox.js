function toggleTimeline(element) {
    const content = element.nextElementSibling;
    const chevron = element.querySelector('.clkt-timeline-chevron');

    if (content.classList.contains('open')) {
        content.classList.remove('open');
        chevron.classList.remove('open');
    } else {
        content.classList.add('open');
        chevron.classList.add('open');
    }
}

function toggleSettings(element) {
    const content = element.nextElementSibling;
    const chevron = element.querySelector('.clkt-settings-chevron');

    if (content.style.display === 'none') {
        content.style.display = 'block';
        chevron.style.transform = 'rotate(180deg)';
    } else {
        content.style.display = 'none';
        chevron.style.transform = 'rotate(0deg)';
    }
}

(function () {
    function getAjaxConfig() {
        return window.clkt_ajax_obj || {};
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getPanel() {
        let panel = document.getElementById('clkt-restore-proof-panel');
        if (!panel) {
            const container = document.querySelector('.clkt-permaweb-container');
            if (!container) return null;

            panel = document.createElement('div');
            panel.id = 'clkt-restore-proof-panel';
            panel.className = 'clkt-restore-proof-panel';
            panel.hidden = true;
            container.appendChild(panel);
        }

        return panel;
    }

    async function postAjax(action, values) {
        const config = getAjaxConfig();
        const ajaxUrl = config.ajax_url || window.ajaxurl;
        const nonce = config.nonce || '';

        if (!ajaxUrl || !nonce) {
            throw new Error('CLKT AJAX is not available on this screen.');
        }

        const response = await fetch(ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action,
                nonce,
                ...values
            })
        });

        const payload = await response.json();
        if (!payload.success) {
            throw new Error(payload.data?.message || payload.data || 'Request failed');
        }

        return payload.data;
    }

    function verificationLabel(status) {
        if (status === 'verified') return 'Verified';
        if (status === 'mismatch') return 'Mismatch';
        if (status === 'unavailable') return 'Unavailable';
        return 'Unknown';
    }

    function truncateHash(hash) {
        if (!hash || hash.length <= 16) return hash || '';
        return hash.substring(0, 8) + '…' + hash.substring(hash.length - 6);
    }

    function formatTimestamp(ts) {
        if (!ts) return '';
        const d = new Date(typeof ts === 'number' ? ts * 1000 : ts);
        if (isNaN(d.getTime())) return '';
        return d.toISOString().replace('T', ' ').substring(0, 19) + ' UTC';
    }

    async function decryptAesGcm(encryptedBase64, password) {
        const encryptedArray = new Uint8Array(
            atob(encryptedBase64).split('').map(c => c.charCodeAt(0))
        );
        const salt = encryptedArray.slice(0, 16);
        const iv = encryptedArray.slice(16, 28);
        const ciphertext = encryptedArray.slice(28);

        const keyMaterial = await crypto.subtle.importKey(
            'raw', new TextEncoder().encode(password), 'PBKDF2', false, ['deriveKey']
        );
        const key = await crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
            keyMaterial,
            { name: 'AES-GCM', length: 256 },
            false,
            ['decrypt']
        );
        const decrypted = await crypto.subtle.decrypt({ name: 'AES-GCM', iv }, key, ciphertext);
        return new TextDecoder().decode(decrypted);
    }

    function renderPreview(data, postId, txId) {
        const panel = getPanel();
        if (!panel) return;

        const preview = data.preview || {};
        const verification = data.verification || {};
        const version = data.version || {};
        const contentText = String(preview.content || '').replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
        const contentExcerpt = contentText.length > 220 ? `${contentText.substring(0, 220)}...` : contentText;
        const attachments = Array.isArray(preview.attachments) ? preview.attachments : [];
        const resolvedTxId = preview.source_tx_id || version.tx_id || txId;
        const contentHash = preview.content_hash || version.content_hash || '';
        const packageHash = preview.package_hash || '';
        const uploadTime = formatTimestamp(version.timestamp);
        const isRealTx = resolvedTxId && !resolvedTxId.startsWith('TEST_');
        const isEncrypted = !!preview.encrypted;

        let restoreSection;
        if (isEncrypted) {
            restoreSection = `
                <div class="clkt-restore-proof-decrypt-gate">
                    <label for="clkt-restore-password" style="display:block; font-size:11px; color:#646970; margin-bottom:4px;">Enter encryption password to restore</label>
                    <input type="password" id="clkt-restore-password" class="clkt-restore-password" placeholder="Encryption password" style="width:100%; padding:6px 8px; font-size:12px; border:1px solid #d1d5db; border-radius:4px; margin-bottom:8px;" />
                    <button type="button" class="button button-primary clkt-restore-proof-decrypt-restore" data-post-id="${escapeHtml(postId)}" data-tx-id="${escapeHtml(txId)}" style="width:100%; text-align:center;">
                        Decrypt &amp; Restore
                    </button>
                </div>`;
        } else {
            restoreSection = `
                <button type="button" class="button button-primary clkt-restore-proof-create-draft" data-post-id="${escapeHtml(postId)}" data-tx-id="${escapeHtml(txId)}">
                    Create Restore Draft
                </button>`;
        }

        panel.hidden = false;
        panel.innerHTML = `
            <div class="clkt-restore-proof-card">
                <div class="clkt-restore-proof-header">
                    <strong>Proof of Restore</strong>
                    <button type="button" class="button-link clkt-restore-proof-close" aria-label="Close restore proof">Close</button>
                </div>
                <div class="clkt-restore-proof-status clkt-restore-proof-status--${escapeHtml(verification.status || 'unknown')}">
                    ${escapeHtml(verificationLabel(verification.status))}
                </div>
                <div class="clkt-restore-proof-message">${escapeHtml(verification.message || '')}</div>
                <dl class="clkt-restore-proof-details">
                    <dt>Title</dt>
                    <dd>${escapeHtml(preview.title || 'Untitled')}</dd>
                    <dt>Transaction</dt>
                    <dd class="clkt-restore-proof-tx">
                        <code title="${escapeHtml(resolvedTxId)}">${escapeHtml(truncateHash(resolvedTxId))}</code>
                        ${isRealTx ? `<a href="https://arweave.net/${escapeHtml(resolvedTxId)}" target="_blank" rel="noopener" class="clkt-restore-proof-arweave-link" title="View on Arweave">&#x2197;</a>` : ''}
                    </dd>
                    ${contentHash ? `<dt>Content Hash</dt>
                    <dd><code title="${escapeHtml(contentHash)}">${escapeHtml(truncateHash(contentHash))}</code></dd>` : ''}
                    ${packageHash ? `<dt>Package Hash</dt>
                    <dd><code title="${escapeHtml(packageHash)}">${escapeHtml(truncateHash(packageHash))}</code></dd>` : ''}
                    <dt>Media</dt>
                    <dd>${attachments.length} attachment${attachments.length === 1 ? '' : 's'}</dd>
                    ${uploadTime ? `<dt>Uploaded</dt>
                    <dd>${escapeHtml(uploadTime)}</dd>` : ''}
                    ${isEncrypted ? `<dt>Encrypted</dt>
                    <dd>Yes — password required to restore content</dd>` : ''}
                    ${!isEncrypted ? `<dt>Content</dt>
                    <dd>${escapeHtml(contentExcerpt || '[No previewable content]')}</dd>` : ''}
                </dl>
                <div class="clkt-restore-proof-actions">
                    ${restoreSection}
                </div>
                <div class="clkt-restore-proof-edition" style="margin-top:10px; padding-top:8px; border-top:1px solid #e5e7eb;">
                    <button type="button" class="button-link clkt-copy-permanent-edition" data-post-id="${escapeHtml(postId)}" data-tx-id="${escapeHtml(txId)}" style="font-size:11px; color:#8b5cf6; cursor:pointer;">
                        Copy Permanent Edition proof
                    </button>
                </div>
            </div>
        `;

        panel.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function renderError(error) {
        const panel = getPanel();
        if (!panel) return;

        panel.hidden = false;
        panel.innerHTML = `
            <div class="clkt-restore-proof-card">
                <div class="clkt-restore-proof-header">
                    <strong>Proof of Restore</strong>
                    <button type="button" class="button-link clkt-restore-proof-close" aria-label="Close restore proof">Close</button>
                </div>
                <div class="clkt-restore-proof-status clkt-restore-proof-status--mismatch">Error</div>
                <div class="clkt-restore-proof-message">${escapeHtml(error.message || error)}</div>
            </div>
        `;
    }

    const LICENSE_URLS = {
        'CC-BY-4.0': 'https://creativecommons.org/licenses/by/4.0/',
        'CC-BY-SA-4.0': 'https://creativecommons.org/licenses/by-sa/4.0/',
        'CC-BY-NC-4.0': 'https://creativecommons.org/licenses/by-nc/4.0/',
        'CC-BY-NC-SA-4.0': 'https://creativecommons.org/licenses/by-nc-sa/4.0/',
        'CC0-1.0': 'https://creativecommons.org/publicdomain/zero/1.0/',
        'MIT': 'https://opensource.org/licenses/MIT',
        'Apache-2.0': 'https://www.apache.org/licenses/LICENSE-2.0'
    };

    function saveAttribution() {
        const spdxSelect = document.getElementById('clkt-license-spdx');
        if (!spdxSelect) return;

        const postId = spdxSelect.getAttribute('data-post-id');
        let spdx = spdxSelect.value;
        if (spdx === 'custom') {
            const customInput = document.getElementById('clkt-license-custom-spdx');
            spdx = customInput ? customInput.value.trim() : '';
        }

        const urlInput = document.getElementById('clkt-license-url');
        const noteInput = document.getElementById('clkt-license-note');

        const derivedEntries = [];
        document.querySelectorAll('#clkt-derived-from-list .clkt-derived-entry').forEach(entry => {
            const rel = entry.querySelector('.clkt-derived-relationship');
            const title = entry.querySelector('.clkt-derived-title');
            const author = entry.querySelector('.clkt-derived-author');
            const url = entry.querySelector('.clkt-derived-url');
            const txId = entry.querySelector('.clkt-derived-txid');
            if (rel && rel.value) {
                derivedEntries.push({
                    relationship: rel.value,
                    title: title ? title.value.trim() : '',
                    author: author ? author.value.trim() : '',
                    url: url ? url.value.trim() : '',
                    txId: txId ? txId.value.trim() : ''
                });
            }
        });

        postAjax('clkt_save_attribution', {
            post_id: postId,
            license_spdx: spdx,
            license_url: urlInput ? urlInput.value.trim() : '',
            license_note: noteInput ? noteInput.value.trim() : '',
            derived_from: JSON.stringify(derivedEntries)
        }).catch(() => {});
    }

    let saveTimer = null;
    function debouncedSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveAttribution, 500);
    }

    function setupLicenseHandlers() {
        const spdxSelect = document.getElementById('clkt-license-spdx');
        if (!spdxSelect) return;

        spdxSelect.addEventListener('change', function () {
            const val = this.value;
            const customContainer = document.getElementById('clkt-license-custom-container');
            const urlContainer = document.getElementById('clkt-license-url-container');
            const noteContainer = document.getElementById('clkt-license-note-container');
            const urlInput = document.getElementById('clkt-license-url');

            if (val === '') {
                customContainer.style.display = 'none';
                urlContainer.style.display = 'none';
                noteContainer.style.display = 'none';
            } else if (val === 'custom') {
                customContainer.style.display = 'block';
                urlContainer.style.display = 'block';
                noteContainer.style.display = 'block';
                if (urlInput) urlInput.value = '';
            } else {
                customContainer.style.display = 'none';
                urlContainer.style.display = 'block';
                noteContainer.style.display = 'block';
                if (urlInput && LICENSE_URLS[val]) {
                    urlInput.value = LICENSE_URLS[val];
                }
            }
            debouncedSave();
        });

        const inputs = ['clkt-license-custom-spdx', 'clkt-license-url', 'clkt-license-note'];
        inputs.forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', debouncedSave);
        });
    }

    function createDerivedEntry() {
        const entry = document.createElement('div');
        entry.className = 'clkt-derived-entry';
        entry.innerHTML = `
            <div class="clkt-derived-entry-header">
                <select class="clkt-select clkt-derived-relationship">
                    <option value="citation">Citation</option>
                    <option value="response">Response</option>
                    <option value="translation">Translation</option>
                    <option value="remix">Remix</option>
                    <option value="fork">Fork</option>
                </select>
                <button type="button" class="clkt-derived-remove" title="Remove">&times;</button>
            </div>
            <input type="text" class="clkt-text-input clkt-derived-title" placeholder="Title">
            <input type="text" class="clkt-text-input clkt-derived-author" placeholder="Author">
            <input type="text" class="clkt-text-input clkt-derived-url" placeholder="URL">
            <input type="text" class="clkt-text-input clkt-derived-txid" placeholder="Arweave TX ID (optional)">
        `;
        bindDerivedEntryEvents(entry);
        return entry;
    }

    function bindDerivedEntryEvents(entry) {
        entry.querySelector('.clkt-derived-remove').addEventListener('click', function () {
            entry.remove();
            debouncedSave();
        });
        entry.querySelectorAll('input, select').forEach(el => {
            el.addEventListener('change', debouncedSave);
        });
    }

    function setupDerivedHandlers() {
        document.querySelectorAll('#clkt-derived-from-list .clkt-derived-entry').forEach(bindDerivedEntryEvents);

        const addBtn = document.querySelector('.clkt-derived-add');
        if (addBtn) {
            addBtn.addEventListener('click', function () {
                const list = document.getElementById('clkt-derived-from-list');
                if (list) {
                    list.appendChild(createDerivedEntry());
                }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            setupLicenseHandlers();
            setupDerivedHandlers();
        });
    } else {
        setupLicenseHandlers();
        setupDerivedHandlers();
    }

    document.addEventListener('click', async (event) => {
        const proofButton = event.target.closest('.clkt-restore-proof-button');
        if (proofButton) {
            event.preventDefault();

            const postId = proofButton.getAttribute('data-post-id');
            const txId = proofButton.getAttribute('data-tx-id');
            const originalText = proofButton.textContent;

            proofButton.disabled = true;
            proofButton.textContent = 'Checking...';

            try {
                const data = await postAjax('clkt_restore_proof_preview', {
                    post_id: postId,
                    tx_id: txId
                });
                renderPreview(data, postId, txId);
            } catch (error) {
                renderError(error);
            } finally {
                proofButton.disabled = false;
                proofButton.textContent = originalText;
            }

            return;
        }

        const closeButton = event.target.closest('.clkt-restore-proof-close');
        if (closeButton) {
            event.preventDefault();
            const panel = getPanel();
            if (panel) {
                panel.hidden = true;
                panel.innerHTML = '';
            }
            return;
        }

        const draftButton = event.target.closest('.clkt-restore-proof-create-draft');
        if (draftButton) {
            event.preventDefault();

            const postId = draftButton.getAttribute('data-post-id');
            const txId = draftButton.getAttribute('data-tx-id');
            const originalText = draftButton.textContent;

            draftButton.disabled = true;
            draftButton.textContent = 'Creating...';

            try {
                const data = await postAjax('clkt_restore_proof_create_draft', {
                    post_id: postId,
                    tx_id: txId
                });

                draftButton.closest('.clkt-restore-proof-actions').innerHTML = `
                    <a class="button button-primary" href="${escapeHtml(data.edit_url)}">Open Restore Draft</a>
                `;
            } catch (error) {
                renderError(error);
                draftButton.disabled = false;
                draftButton.textContent = originalText;
            }

            return;
        }

        const decryptButton = event.target.closest('.clkt-restore-proof-decrypt-restore');
        if (decryptButton) {
            event.preventDefault();

            const postId = decryptButton.getAttribute('data-post-id');
            const txId = decryptButton.getAttribute('data-tx-id');
            const passwordInput = decryptButton.closest('.clkt-restore-proof-decrypt-gate').querySelector('.clkt-restore-password');
            const password = passwordInput ? passwordInput.value : '';

            if (!password) {
                passwordInput.style.borderColor = '#d63638';
                passwordInput.focus();
                return;
            }

            const originalText = decryptButton.textContent;
            decryptButton.disabled = true;
            decryptButton.textContent = 'Fetching from Arweave...';

            try {
                const arweaveUrl = 'https://arweave.net/' + encodeURIComponent(txId);
                const arweaveResponse = await fetch(arweaveUrl);
                if (!arweaveResponse.ok) {
                    throw new Error('Could not fetch record from Arweave (HTTP ' + arweaveResponse.status + '). The transaction may not be propagated yet.');
                }

                const contentType = arweaveResponse.headers.get('content-type') || '';
                if (!contentType.includes('json')) {
                    throw new Error('Arweave transaction did not return a JSON record. It may not be a CLKT upload or may not have propagated yet.');
                }

                const record = await arweaveResponse.json();
                const encryptedData = record.encrypted_data || (record.content && record.content.encrypted_data);
                if (!encryptedData) {
                    throw new Error('No encrypted data found in the Arweave record.');
                }

                decryptButton.textContent = 'Decrypting...';
                const decryptedJson = await decryptAesGcm(encryptedData, password);
                const decryptedPost = JSON.parse(decryptedJson);

                const decryptedContent = decryptedPost.content?.raw || decryptedPost.content?.rendered || '';
                const decryptedTitle = decryptedPost.title?.rendered || '';
                const decryptedExcerpt = decryptedPost.excerpt?.raw || '';

                if (!decryptedContent) {
                    throw new Error('Decryption succeeded but no content found in the decrypted record.');
                }

                decryptButton.textContent = 'Creating draft...';
                const data = await postAjax('clkt_restore_proof_create_draft', {
                    post_id: postId,
                    tx_id: txId,
                    decrypted_title: decryptedTitle,
                    decrypted_content: decryptedContent,
                    decrypted_excerpt: decryptedExcerpt
                });

                decryptButton.closest('.clkt-restore-proof-actions').innerHTML = `
                    <a class="button button-primary" href="${escapeHtml(data.edit_url)}">Open Restore Draft</a>
                `;
            } catch (error) {
                const msg = error.message || String(error);
                if (msg.includes('decrypt') || msg.includes('Decryption')) {
                    passwordInput.style.borderColor = '#d63638';
                    passwordInput.value = '';
                    passwordInput.placeholder = 'Wrong password — try again';
                    passwordInput.focus();
                    decryptButton.disabled = false;
                    decryptButton.textContent = originalText;
                } else {
                    renderError(error);
                    decryptButton.disabled = false;
                    decryptButton.textContent = originalText;
                }
            }

            return;
        }

        const editionButton = event.target.closest('.clkt-copy-permanent-edition');
        if (editionButton) {
            event.preventDefault();

            const postId = editionButton.getAttribute('data-post-id');
            const txId = editionButton.getAttribute('data-tx-id');
            const originalText = editionButton.textContent;

            editionButton.textContent = 'Loading...';

            try {
                const data = await postAjax('clkt_get_permanent_edition', {
                    post_id: postId,
                    tx_id: txId
                });

                await navigator.clipboard.writeText(JSON.stringify(data.edition, null, 2));
                editionButton.textContent = 'Copied to clipboard';
                editionButton.style.color = '#00a32a';
                setTimeout(() => {
                    editionButton.textContent = originalText;
                    editionButton.style.color = '';
                }, 2000);
            } catch (error) {
                editionButton.textContent = 'Copy failed';
                editionButton.style.color = '#d63638';
                setTimeout(() => {
                    editionButton.textContent = originalText;
                    editionButton.style.color = '';
                }, 2000);
            }

            return;
        }
    });
})();
