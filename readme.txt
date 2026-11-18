=== CLKT - Save Forever, Collect Together ===
Contributors: divydovy
Tags: arweave, backup, decentralized, web3, permanent-storage
Requires at least: 5.8
Tested up to: 7.1
Stable tag: 1.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Permanently preserve WordPress posts and media on Arweave with one-click decentralized storage.

== Description ==

CLKT connects your WordPress site to permanent, decentralized storage on Arweave. The default upload path uses an external EVM browser wallet with Base USDC/x402 payments through Turbo, while Wander/Arweave remains available as an advanced AR wallet option.

**Key Features:**

* **One-Click Permanent Storage** - Save posts and supported editorial custom post types to the Arweave permaweb instantly
* **Attachment Support** - Automatically includes images and media with your content
* **Version History** - Every save creates a permanent snapshot you can always access
* **Atomic Asset-Compatible Records** - Store CLKT-specific provenance, restore, and collection metadata inside permaweb-native asset objects
* **Bulk Upload** - Save your entire content library in one go
* **Self-Managed Wallet** - Connect your own EVM wallet for Base USDC/x402 uploads, or use Wander/Arweave as an advanced option
* **User-Paid Uploads** - Larger uploads are paid by your connected wallet, not by CLKT-owned keys
* **100% WordPress Compatible** - Works with any theme or plugin
* **No Recurring Fees** - Pay once, store forever (small one-time network fee)

**Why CLKT?**

Traditional backups fail. Servers crash. Companies disappear. Your content deserves better. CLKT ensures your words, images, and ideas persist permanently on the decentralized web - no single point of failure, no corporate control, just permanent peace of mind.

**Perfect For:**

* Bloggers who want to preserve their writing legacy
* Photographers archiving their portfolios
* Journalists protecting their work from censorship
* Anyone who believes their content should last forever

**How It Works:**

1. Install CLKT on your WordPress site
2. Connect an EVM browser wallet such as MetaMask, Rabby, or Brave Wallet, or switch to Wander/Arweave in settings
3. Click "Save Forever" on any post
4. Your content is permanently stored on Arweave
5. Get a permanent link that will work forever

Join the movement to make content truly permanent. Save forever, collect together.

**External Services:**

This plugin relies on the following third-party services. Post content, media, and metadata are not sent to storage or lookup services until you explicitly trigger an upload or lookup.

1. **Arweave Network** ([arweave.org](https://www.arweave.org)) - The core decentralized storage network. Your post content, media files, and metadata are transmitted to Arweave gateway nodes (arweave.net) for permanent storage when you click "Save Forever". Content becomes publicly accessible at arweave.net URLs. The plugin also uses a permanent viewer hosted on Arweave for displaying uploaded content.
   * [Terms of Service](https://www.arweave.org/terms-of-service)
   * [Privacy Policy](https://www.arweave.org/privacy)

2. **ArDrive Turbo Upload Service** ([ardrive.io](https://ardrive.io)) - Used for Turbo uploads, including x402 paid uploads through `upload.ardrive.io`. When you upload, the plugin sends the signed data item, upload tags, content type, and payment authorization headers when Turbo quotes payment. The connected user wallet signs any required Base USDC authorization; CLKT does not hold or use private keys.
   * [Terms of Service and Privacy Policy](https://ardrive.io/tos-and-privacy/)
   * [Turbo Upload API Documentation](https://docs.ardrive.io/docs/turbo/api/upload.html)

3. **Base Network** ([base.org](https://base.org)) - Used when the EVM/x402 wallet mode is selected. The plugin may ask your browser wallet to switch to Base and may provide Base RPC metadata such as `https://mainnet.base.org`. Wallet address, chain ID, and signed payment authorization data are handled by your wallet and the payment/upload service when you approve an upload.
   * [Terms of Service](https://docs.base.org/terms-of-service)
   * [Privacy Policy](https://www.coinbase.com/legal/privacy)

4. **CoinGecko API** ([coingecko.com](https://www.coingecko.com)) - Used to fetch the current AR/USD exchange rate for cost estimation. Only a simple price query is sent; no user data is transmitted.
   * [Terms of Service](https://www.coingecko.com/en/terms)
   * [Privacy Policy](https://www.coingecko.com/en/privacy)

5. **Goldsky GraphQL** ([goldsky.com](https://goldsky.com)) - Used to search and verify Arweave transactions by ID. No user data is transmitted beyond transaction IDs.
   * [Terms of Service](https://goldsky.com/terms/terms-and-conditions)
   * [Privacy Policy](https://goldsky.com/privacy)

6. **AO Testnet** ([ao.arweave.dev](https://ao.arweave.dev)) - Part of the Arweave ecosystem, used for atomic asset registration when content is uploaded as an atomic asset.
   * [Terms of Service](https://www.arweave.org/terms-of-service)
   * [Privacy Policy](https://www.arweave.org/privacy)

== Installation ==

1. Upload the `clkt` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > CLKT to configure your options
4. Start saving your content forever!

**Getting Started:**

* **Connect Wallet** - Use an EVM browser wallet for the default Base USDC/x402 upload path, or select Wander/Arweave in CLKT settings if you want to pay directly with AR.

== Frequently Asked Questions ==

= What is Arweave? =

Arweave is a decentralized storage network that ensures data permanence. Once uploaded, your content is replicated across hundreds of nodes worldwide and guaranteed to remain accessible forever.

= What is a CLKT Record? =

A CLKT Record is the CLKT-specific data saved inside an atomic asset-compatible Arweave object. The atomic asset layer provides the permaweb envelope: durable data, tags, ownership, discoverability, and licensing hooks. The CLKT Record layer adds the product meaning: source URL, WordPress source data, collector wallet, attribution, content hashes, restore manifest, version history, collection context, and remix references.

CLKT Records should align with the published Tradeable Atomic Asset spec: https://atomic-assets.arweave.net/. The base asset layer should use standard discoverability tags such as Content-Type, Type, Title, Description, and optional Topic tags, plus CLKT-specific tags such as App-Name, CLKT-Record-Version, Source-URL, Source-Domain, Collector, Collection-ID, and restore manifest references. SmartWeave contract tags and UDL license tags are the extension path for collectable, owner, and market behavior.

In practice, a saved item should be one Arweave object, not two separate uploads. CLKT uses the atomic asset-compatible layer for interoperability and the CLKT Record payload for open collecting, attribution, restore proof, and trust.

= Do I need cryptocurrency? =

Yes. The default path uses an EVM browser wallet and may require a small amount of Base USDC when Turbo requires x402 payment. If you switch to Wander/Arweave mode, you need AR tokens for direct Arweave fees. These fees are one-time only for permanent storage.

= How much does it cost? =

Storage on Arweave requires a one-time fee. Small uploads may be accepted by Turbo without payment, while larger uploads may require a Base USDC x402 payment or AR payment depending on the wallet mode you select. No subscriptions, no recurring charges - pay once, store forever.

= Is my content public? =

Content saved to Arweave is publicly accessible by default. CLKT includes optional encryption with password protection for sensitive content. You can encrypt using:
* Your wallet key (most secure)
* Global password (shared across all posts)
* Per-post password (individual control)

Encrypted content requires the password to decrypt and view.

= Can I delete content once uploaded? =

Content on Arweave is permanent and cannot be deleted. This is a feature, not a bug - it ensures true content permanence.

= What happens to my media/images? =

CLKT automatically detects and saves all images and attachments associated with your posts. They're stored permanently alongside your content.

= What is Test Mode? =

Test mode simulates uploads without spending AR tokens, spending Base USDC, or creating real Arweave transactions. Enable in Settings > CLKT > General Settings for development and testing. Test uploads are tagged with TEST_ prefix and hidden in production mode, so you can safely experiment without affecting your live content.

= Can I upload multiple posts at once? =

Yes! Use the Bulk Upload feature in Settings > CLKT > Bulk Upload to save your entire content library to Arweave. You can upload only new/changed content or force re-upload all posts. The preview shows exactly what will be uploaded and the total cost before you commit.

== Screenshots ==

1. CLKT settings page with easy-to-use tabbed interface
2. Post editor with auto-upload preview showing attachment detection and cost estimation
3. Posts list with Permaweb status column showing saved content
4. Bulk upload interface for saving multiple posts at once
5. Successful upload confirmation with permanent Arweave link

== External services ==

This plugin connects to the following third-party services during normal operation. Post content, media, and metadata are not sent until the user explicitly triggers an upload action.

= 1. Arweave Network (arweave.net) =

The core blockchain storage network. CLKT sends your post content, media files, and metadata to Arweave nodes for permanent decentralized storage. This is the primary function of the plugin - when you click "Save Forever", your content is transmitted to the Arweave network.

The plugin connects to the following Arweave gateway domains:

* arweave.net - For uploading content and retrieving stored content via transaction IDs
* 2deruxhrgmrp2browgc472rhocjc7xjbkrpac7bmg6psfq26ue7q.arweave.net - For viewing uploaded content with the permanent viewer

Data sent: Post content (title, body, excerpt), attached media files, post metadata, and wallet address. Content is sent when the user explicitly clicks "Save Forever" or uses bulk upload. Uploaded content becomes publicly accessible on the Arweave network.

* [Arweave Terms of Service](https://www.arweave.org/terms-of-service)
* [Arweave Privacy Policy](https://www.arweave.org/privacy)

= 2. ArDrive Turbo Upload Service (upload.ardrive.io) =

Used for Turbo uploads, including x402 paid uploads from an EVM wallet. CLKT sends signed data items, content type, tags, wallet address tags, and x402 payment authorization headers when Turbo requires payment. The connected user wallet signs any required Base USDC authorization; CLKT does not hold private keys or pay with CLKT-owned keys.

* [ArDrive Terms of Service and Privacy Policy](https://ardrive.io/tos-and-privacy/)
* [Turbo Upload API Documentation](https://docs.ardrive.io/docs/turbo/api/upload.html)

= 3. Base Network (mainnet.base.org) =

Used for the default EVM/Base USDC x402 payment path. CLKT may request that your browser wallet switch to Base and may provide Base RPC metadata to the wallet. Payment authorizations are signed by the connected wallet only after you approve them.

* [Base Terms of Service](https://docs.base.org/terms-of-service)
* [Coinbase Privacy Policy](https://www.coinbase.com/legal/privacy)

= 4. CoinGecko API (api.coingecko.com) =

Used to fetch the current AR/USD exchange rate for cost estimation. Only a simple price query is sent - no user data is transmitted to CoinGecko.

* [CoinGecko Terms of Service](https://www.coingecko.com/en/terms)
* [CoinGecko Privacy Policy](https://www.coingecko.com/en/privacy)

= 5. Goldsky GraphQL (arweave-search.goldsky.com) =

Used to search and verify Arweave transactions. The plugin sends transaction IDs to Goldsky to look up upload status and confirm that content was stored successfully.

* [Goldsky Terms of Service](https://goldsky.com/terms/terms-and-conditions)
* [Goldsky Privacy Policy](https://goldsky.com/privacy)

= 6. AO Testnet (su-router.ao-testnet.xyz) =

Part of the Arweave ecosystem, used for atomic asset registration. When content is uploaded as an atomic asset-compatible object, the plugin communicates with the AO network for registration.

* [AO / Arweave Terms of Service](https://www.arweave.org/terms-of-service)
* [AO / Arweave Privacy Policy](https://www.arweave.org/privacy)

== Changelog ==

= 1.4.0 =
* Add clkt/v1 /settings (GET+POST, manage_options) to read/update whitelisted CLKT settings headlessly.

= 1.3.0 =
* Add clkt/v1 /auto-save-queue: lists posts that should auto-save per the site's own settings, so new posts can be preserved on publish (external signer) without the plugin holding a key.

= 1.2.1 =
* clkt/v1 /save is now idempotent: re-saving a tx re-verifies and updates status without duplicating versions.

= 1.2.0 =
* Add clkt/v1 REST API for headless Save Forever (caller-signed txs; plugin never holds a key).

= 1.1.0 =
* Added attribution layer: license (SPDX) and derived-from fields in CLKT records and Arweave tags
* Added attribution metabox UI in the editor sidebar with license selector and derived-from management
* Added frontend attribution display — license badge and provenance references on public posts
* Added Arweave GraphQL feed client for querying CLKT records by collector, domain, or topic
* Bumped "Tested up to" to WordPress 7.0

= 1.0.31 =
* Added password-gated restore for encrypted uploads with client-side AES-256-GCM decryption
* Added Permanent Edition proof object — a shareable JSON provenance summary with copy-to-clipboard action
* Additional repo cleanup: removed old checkpoints, dev infrastructure files, and added composer.json to distignore

= 1.0.30 =
* Enhanced Proof of Restore modal with content hash, package hash, upload timestamp, encryption status, and clickable Arweave link
* Added proof summary (content hash, media count) to timeline version entries
* Removed 36 tracked debug/test artifacts from the repository
* Backfilled missing changelog entries for 1.0.26 through 1.0.29

= 1.0.29 =
* Fixed x402 payment failure when Turbo returns fractional micro-USDC quotes for certain file sizes
* Rounded fractional amounts up after retry instead of rejecting them permanently
* Hardened attachment proxy endpoint with URL validation against upload directory and attachment records
* Disabled redirects and added response size limit on proxy fetch requests
* Fixed content hash race condition where editing a post during upload recorded the wrong hash
* Fixed frontend display option key referencing nonexistent setting
* Prevented permaweb links from appearing in post excerpts
* Added duplicate-output guard for permaweb links in content

= 1.0.28 =
* Fixed uploads for supported custom post types such as photo archives
* Treated non-attachment custom post types as post-like content in the upload UI and upload executor

= 1.0.27 =
* Added support for public editorial custom post types with editor support
* Preserved custom post type metadata in CLKT records and restore manifests
* Added a `clkt_supported_post_types` filter so sites can adjust the supported authoring surface

= 1.0.26 =
* Retry invalid fractional Turbo x402 quotes before wallet signing instead of rounding them into unverifiable payments
* Surface clearer Turbo x402 payment errors for larger media uploads
* Quiet benign Gutenberg/editor boot diagnostics unless WordPress debug mode is enabled

= 1.0.25 =
* Removed the single-post editor Upload More action that duplicated the preview refresh flow and broke narrow metabox layouts
* Switched Turbo x402 payment signing to the official x402 EVM client path used by the hosted app
* Quieted editor, upload, and wallet diagnostics unless WordPress debug mode is enabled

= 1.0.24 =
* Treat Arweave gateway propagation responses, including 572, as pending verification instead of failed uploads
* Reuse the upload preview component and remove noisy duplicate-instance diagnostics
* Show the expected x402 recipient, token contract, and network before MetaMask payment signing

= 1.0.23 =
* Fixed paid Turbo uploads when x402 quotes include fractional micro-USDC values
* Normalized x402 payment amounts before spend-cap checks and wallet signing
* Preserved EVM upload attestation requirements for public permanent Arweave uploads

= 1.0.22 =
* Added default EVM wallet mode for Base USDC/x402 Turbo uploads
* Kept Wander/Arweave available as an advanced AR wallet option
* Added configurable x402 spend cap for paid Turbo uploads
* Added public EVM/Wander identity-link workflow for wallet migration
* Updated external services documentation for Turbo, Base, and x402 payments

= 1.0.21 =
* Added Proof of Restore manifests for successful post uploads
* Added verify/restore preview action for saved Permaweb versions
* Added restore draft creation from saved upload manifests without overwriting the original post
* Added E2E coverage for post plus media restore proof flow

= 1.0.20 =
* Consolidated auto-upload onto the pending-upload path to avoid duplicate attachment uploads and extra wallet signatures
* Removed pre-public legacy upload, status, notice, migration, and compatibility paths
* Removed old direct upload button wiring, enhanced-save script, and legacy Permaweb integration file
* Verified one-post, one-attachment auto-upload at two upload/signature calls
* Full E2E suite passing with 17 tests

= 1.0.19 =
* Fixed critical race condition in auto-upload system preventing re-uploads after editing published posts
* Modified save_version() to only clear pending flags if upload brought post up to date
* Added content hash checking to detect when post was edited after upload started
* All 16 Tier 1 tests passing

= 1.0.6 =
* Fixed critical bug where transaction IDs were saved as "[object Object]" in timeline
* Fixed verification logic to correctly show error status for invalid transaction IDs
* Fixed single-click upload - removed redundant check that required clicking twice
* Added comprehensive debug logging for troubleshooting upload issues

= 1.0.5 =
* Fixed force reupload mode - attachments now upload correctly when force reupload is enabled
* Fixed cost calculation consistency across all views (preview details and summary)
* Improved upload state display in force reupload mode (shows "Up to date" with reupload indicator)
* Fixed cost calculations to use configurable free dispatch limit instead of hardcoded values
* Enhanced upload mode handling and parameter passing throughout the upload chain

= 1.0.4 =
* Fixed bulk upload logic and improved test mode handling
* Improved upload state tracking to properly detect editor changes
* Fixed test mode upload visibility in post listings
* Enhanced permaweb integration and refactored upload flows

= 1.0.3 =
* Fixed attachment reuse logic in new_only mode
* Improved TEST_ transaction filtering in production mode
* Enhanced upload preview debugging and fixed display issues
* Added backward compatibility for content hash verification

= 1.0.2 =
* Fixed wallet connection refresh on page load
* Improved editor change detection and auto-upload behavior
* Enhanced ArConnect integration for paid uploads
* Fixed signing process for uploads larger than 100KB

= 1.0.1 =
* Added configurable max upload size setting
* Clarified free dispatch limit in settings
* Improved paid upload support for files >100KB
* Enhanced test mode banner persistence
* UI improvements and bug fixes

= 1.0.0 =
* Initial release
* One-click permanent storage for posts and pages
* Automatic attachment detection and upload
* Self-managed wallet integration (Wander)
* Bulk upload functionality
* Version history tracking
* Consolidated settings interface

== Upgrade Notice ==

= 1.0.28 =
Fixes upload execution for supported custom post types such as Photos.

= 1.0.27 =
Adds support for public editorial custom post types and preserves post type metadata in CLKT records.

= 1.0.26 =
Retries invalid Turbo x402 quote responses before wallet signing and improves larger media payment errors.

= 1.0.25 =
Fixes the single-post receipt action layout, reduces production console noise, and hardens paid x402 signing.

= 1.0.24 =
Improves x402 payment confirmation clarity and keeps new Arweave uploads pending while gateways propagate.

= 1.0.23 =
Fixes paid Turbo uploads for larger media when Turbo returns fractional micro-USDC x402 quotes.

= 1.0.22 =
Adds the lower-barrier EVM/Base USDC x402 upload path and documents the current external services used by paid uploads.

= 1.0.21 =
Adds Proof of Restore so saved Permaweb versions can be verified and reconstructed into a draft.

= 1.0.20 =
Consolidates auto-upload behavior and removes pre-public legacy paths. Fixes duplicate attachment upload/signature prompts for posts with media.

= 1.0.19 =
Critical fix for auto-upload race condition. Re-uploads after editing published posts now work correctly. Highly recommended update.

= 1.0.6 =
Critical bug fixes for transaction ID saving and upload reliability. Highly recommended update for all users.

= 1.0.5 =
Important bug fixes for force reupload mode and cost calculation. Recommended update for all users.

= 1.0.0 =
First release of CLKT - Start saving your content forever!

== Additional Info ==

**Contributing**: Find us on GitHub at [github.com/divydovy/clkt-save-forever-collect-together](https://github.com/divydovy/clkt-save-forever-collect-together)

**Support**: Visit [getclkt.com](https://getclkt.com) for documentation and support

**Join the Community**: Follow us for updates and tips

Remember: Save Forever, Collect Together!
